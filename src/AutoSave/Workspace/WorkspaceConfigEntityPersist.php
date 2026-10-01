<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists staged component tree config entities as workspace-scoped config.
 *
 * Runs inside the staging workspace: the save is intercepted by the Workspace
 * Config module and stored in that workspace's partition, so Live is untouched
 * and the draft resolves as regular configuration for every consumer inside
 * the workspace. Staging never validates; a draft the storage layer refuses
 * (an exception anywhere in the save) is retained as a snapshot row instead,
 * so no auto-save is ever dropped. Reads resolve snapshots before the
 * workspace copy, and a later successful persist deletes the snapshot again.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::usesWorkspaceConfigStaging()
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceContentEntityPersist
 * @see \Drupal\workspace_config\WorkspaceConfigDatabaseStorage::write()
 */
final class WorkspaceConfigEntityPersist {

  /**
   * Whether a staged config write is running.
   *
   * @see ::isStagingWrite()
   */
  private bool $stagingWrite = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveSnapshotRepository $snapshotRepository,
    private readonly PendingContentAutoSaveBuffer $pendingBuffer,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    // The cache holding reconstructed AutoSaveEntity objects; deletes here
    // must target the same backend WorkspaceAutoSave caches into.
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $cache,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether a Canvas staged config write is currently running.
   *
   * TRUE exactly while ::persist() saves a draft as workspace-scoped
   * configuration. Config save listeners that reconcile drafts with saves
   * made elsewhere must ignore these saves: the draft is the thing being
   * saved.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   */
  public function isStagingWrite(): bool {
    return $this->stagingWrite;
  }

  /**
   * Stages a draft as workspace-scoped configuration.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The draft.
   * @param string|null $clientId
   *   The client instance that produced the draft, if known.
   * @param array<string, mixed>|null $entry
   *   The auto-save entry the draft was staged with, carrying the metadata
   *   (original hash, owner, edit time) that workspace-scoped configuration
   *   cannot record itself.
   * @param string|null $flushToken
   *   When flushing a deferred write: the token of the buffered row being
   *   flushed. A newer row queued in the meantime is left untouched.
   */
  public function persist(ComponentTreeConfigEntityBase $entity, ?string $clientId, ?array $entry = NULL, ?string $flushToken = NULL): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $type_id = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $storage = $this->entityTypeManager->getStorage($type_id);
    // Never mutate the caller's entity object: the save marks it as saved and
    // recalculates its dependencies, which would leak into the caller's own
    // later use of the same object.
    $to_save = clone $entity;
    // Drafts are reconstructed through ::create(), which marks them new; the
    // config object they target exists (in Live, or staged in this workspace)
    // and must be updated, not inserted.
    $to_save->enforceIsNew($storage->load($id) === NULL);
    $previous = $this->stagingWrite;
    try {
      $this->stagingWrite = TRUE;
      $to_save->save();
    }
    catch (\Throwable $e) {
      // Retention over failure: keep the draft as a payload snapshot. If the
      // snapshot write fails too, the client must see the error.
      $this->logger->warning('Canvas auto-save for @type @id could not be stored as workspace-scoped configuration (@message); stored as a snapshot instead.', [
        '@type' => $type_id,
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      $this->persistSnapshot($entity, $clientId);
      return;
    }
    finally {
      $this->stagingWrite = $previous;
    }
    // The workspace-scoped copy is now the current staged state; a snapshot
    // row from an earlier failed persist would otherwise shadow it forever.
    $this->snapshotRepository->deleteFor($type_id, $id, WorkspaceAutoSave::snapshotLangcode($entity));
    // Workspace-scoped configuration records neither which client instance
    // produced the draft, the editor, nor the base hash it started from, but
    // attribution, concurrent-edit validation and conflict detection need
    // them. A deferred write queued after the one being flushed carries its
    // own metadata and must not be replaced.
    // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::getStagedEntryMetadata()
    $existing = $this->pendingBuffer->get($key) ?? [];
    $pending_token = $existing['token'] ?? NULL;
    if ($pending_token === NULL || $pending_token === $flushToken) {
      $metadata = ['client_id' => $clientId] + WorkspaceAutoSave::entryMetadata($entry) + [
        'owner' => (int) $this->currentUser->id(),
        'updated' => $this->time->getRequestTime(),
      ];
      // The base recorded for config created inside the workspace outlives
      // individual writes.
      // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::CONFIG_BASE_HASH_KEY
      $this->pendingBuffer->set($key, $metadata + \array_intersect_key($existing, [WorkspaceAutoSave::CONFIG_BASE_HASH_KEY => TRUE]));
    }
    $storage->resetCache([$id]);
    $this->cache->delete($key);
  }

  /**
   * Retains a config entity draft as a payload snapshot row.
   */
  public function persistSnapshot(ConfigEntityInterface $entity, ?string $clientId): void {
    $payload = \json_encode($entity->toArray(), JSON_THROW_ON_ERROR);
    $data_hash = AutoSaveManager::generateHashFromData(\json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR));
    $this->snapshotRepository->persist(
      $entity->getEntityTypeId(),
      (string) $entity->id(),
      WorkspaceAutoSave::snapshotLangcode($entity),
      $payload,
      $data_hash,
      $clientId,
      (int) $this->currentUser->id(),
    );
  }

}
