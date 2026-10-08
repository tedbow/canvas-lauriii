<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Key-value stores for drafts and staging metadata, one per workspace.
 *
 * Two collections per workspace:
 * - drafts (`canvas.auto_save.{workspace}`): the retention store for drafts
 *   that are not a workspace revision or workspace-scoped configuration:
 *   config entity types whose save has side effects (code components, asset
 *   libraries, brand kits, staged config updates, staged configuration
 *   translations), and any draft the storage layer rejected. Rows have the
 *   1.x `canvas.auto_save` shape (`entity_type`, `entity_id`, `data`,
 *   `langcode`, `is_default_translation`, `label`, `data_hash`, `client_id`,
 *   `owner`, `updated`), so the 1.x store migrates by renaming its collection.
 *   A row the storage layer rejected also carries `storage_error`.
 * - metadata (`canvas.auto_save_meta.{workspace}`): what the primary stores
 *   cannot record about a draft: the client instance that produced it, the
 *   verbatim draft `path` value, and attribution for workspace-scoped
 *   configuration.
 *
 * Keys are `{type}:{id}[:{langcode}]`, without the workspace prefix that
 * AutoSaveManager::getAutoSaveKey() carries: the collection is the workspace.
 */
final class AutoSaveFallbackStore {

  public const string DRAFTS_COLLECTION_PREFIX = 'canvas.auto_save.';

  public const string METADATA_COLLECTION_PREFIX = 'canvas.auto_save_meta.';

  /**
   * The draft row key holding the storage layer's message when it refused.
   *
   * Absent on rows the fallback store holds by design.
   */
  public const string STORAGE_ERROR_KEY = 'storage_error';

  /**
   * The longest workspace ID a collection name can carry.
   *
   * The key_value table's collection column is 128 characters.
   */
  public const int MAX_WORKSPACE_ID_LENGTH = 100;

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The store key of an entity: `{type}:{id}[:{langcode}]`.
   */
  public static function targetKey(EntityInterface $entity): string {
    $key = $entity->getEntityTypeId() . ':' . $entity->id();
    if ($entity instanceof TranslatableInterface) {
      $key .= ':' . $entity->language()->getId();
    }
    return $key;
  }

  /**
   * Asserts a workspace ID fits the collection name.
   *
   * @throws \InvalidArgumentException
   */
  public static function assertWorkspaceIdLength(string $workspace_id): void {
    if (\strlen($workspace_id) > self::MAX_WORKSPACE_ID_LENGTH) {
      throw new \InvalidArgumentException(\sprintf('Workspace IDs are limited to %d characters; "%s" is %d.', self::MAX_WORKSPACE_ID_LENGTH, $workspace_id, \strlen($workspace_id)));
    }
  }

  public function drafts(string $workspace_id): KeyValueStoreInterface {
    self::assertWorkspaceIdLength($workspace_id);
    return $this->keyValueFactory->get(self::DRAFTS_COLLECTION_PREFIX . $workspace_id);
  }

  public function metadata(string $workspace_id): KeyValueStoreInterface {
    self::assertWorkspaceIdLength($workspace_id);
    return $this->keyValueFactory->get(self::METADATA_COLLECTION_PREFIX . $workspace_id);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getDraft(string $workspace_id, string $key): ?array {
    $row = $this->drafts($workspace_id)->get($key);
    return \is_array($row) && isset($row['entity_type'], $row['data']) ? $row : NULL;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getAllDrafts(string $workspace_id): array {
    return \array_filter(
      $this->drafts($workspace_id)->getAll(),
      static fn ($row): bool => \is_array($row) && isset($row['entity_type'], $row['data']),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function getMetadata(string $workspace_id, string $key): array {
    $row = $this->metadata($workspace_id)->get($key);
    return \is_array($row) ? $row : [];
  }

  /**
   * Merges values into a draft's metadata row.
   *
   * @param array<string, mixed> $values
   */
  public function mergeMetadata(string $workspace_id, string $key, array $values): void {
    $this->metadata($workspace_id)->set($key, $values + $this->getMetadata($workspace_id, $key));
  }

  /**
   * Removes every row (draft and metadata) of one target in one workspace.
   */
  public function deleteTarget(string $workspace_id, string $key): void {
    $this->drafts($workspace_id)->delete($key);
    $this->metadata($workspace_id)->delete($key);
  }

  /**
   * Removes every row of one workspace.
   */
  public function deleteWorkspace(string $workspace_id): void {
    $this->drafts($workspace_id)->deleteAll();
    $this->metadata($workspace_id)->deleteAll();
  }

  /**
   * @return list<string>
   */
  public function workspaceIds(): array {
    if (!$this->entityTypeManager->hasDefinition('workspace')) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('workspace')->getQuery()->accessCheck(FALSE)->execute();
    return \array_values(\array_map(\strval(...), $ids));
  }

}
