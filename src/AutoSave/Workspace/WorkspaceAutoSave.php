<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\CanvasServiceProvider;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Workspace\WorkspaceEntityLockedException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\workspace_config\WorkspaceConfigInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists Canvas auto-save state using the active workspace and snapshots.
 *
 * Staging for a given entity lives in exactly one place, resolved in this
 * order: the pending write buffer (deferred saves not yet flushed), a payload
 * snapshot row (drafts the storage layer rejected, and config entities without
 * workspace-scoped staging), then the primary store: a pending revision
 * tracked in the staging workspace for content, or workspace-scoped
 * configuration (staged through the Workspace Config module) for component
 * tree config entities. A successful persist to a primary store removes the
 * snapshot row for the same target.
 *
 * The staging workspace is the active workspace when one is negotiated, or
 * the Main workspace (`canvas_default`) as the fallback for sessions that
 * never selected one. Every store partitions per workspace.
 *
 * Workspace services use untyped optional injection so the container can
 * compile when the Workspaces module is not installed yet.
 */
final class WorkspaceAutoSave {

  /**
   * Staging metadata key: base hash of config created inside the workspace.
   *
   * Configuration created while a workspace is active has no Live copy to
   * compute hashes and starting points against, so the copy it was created as
   * is recorded once and kept until the workspace publishes or the draft is
   * discarded.
   *
   * @see ::getBaseHash()
   */
  public const string CONFIG_BASE_HASH_KEY = 'config_base_hash';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigManagerInterface $configManager,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'config.storage')]
    private readonly StorageInterface $configStorage,
    #[Autowire(service: 'workspaces.manager')]
    private readonly WorkspaceManagerInterface $workspaceManager,
    #[Autowire(service: 'workspaces.tracker')]
    private readonly WorkspaceTrackerInterface $workspaceAssociation,
    private readonly WorkspaceConfigInformationInterface $workspaceConfigInformation,
    private readonly AutoSaveSnapshotRepository $snapshotRepository,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    // MUST be a non-serializing backend; a serializing one (e.g. cache.static)
    // would run cached entities' ::__sleep(), forcing computed fields to
    // compute mid-cache-write and potentially recurse.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::__construct()
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    // Staging bookkeeping must resolve identically in every workspace.
    // @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
    #[Autowire(service: CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE)]
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly AutoSaveRevisionPruner $revisionPruner,
    private readonly WorkspaceContentEntityPersist $contentEntityPersist,
    private readonly WorkspaceConfigEntityPersist $configEntityPersist,
    private readonly PendingContentAutoSaveBuffer $pendingBuffer,
    private readonly DeferredAutoSaveFlusher $deferredFlusher,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Sets revision_created / revision_user when a new pending revision is saved.
   *
   * Entity forms update these via ContentEntityForm; Canvas API saves do not,
   * so revision_timestamp would otherwise stay aligned with an older revision.
   *
   * Runs after workspaces.module's entity_presave, which sets a new revision.
   */
  public function stampAutoSaveWorkspaceRevisionMetadata(EntityInterface $entity): void {
    if (!$entity instanceof RevisionLogInterface || !$entity instanceof ContentEntityInterface) {
      return;
    }
    if (!$this->workspaceManager->hasActiveWorkspace()) {
      return;
    }
    if ($entity->isSyncing()) {
      return;
    }
    if (!$entity->isNewRevision()) {
      return;
    }
    $entity->setRevisionCreationTime($this->time->getRequestTime());
    $entity->setRevisionUserId((int) $this->currentUser->id());
  }

  /**
   * The workspace staging reads and writes resolve against.
   *
   * @see \Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace::stagingId()
   */
  public function getStagingWorkspaceId(): string {
    return AutoSaveWorkspace::stagingId($this->workspaceManager);
  }

  /**
   * The workspace a bookkeeping switch is entering, while one is in progress.
   *
   * @see ::executeInWorkspaceUnchecked()
   */
  private ?string $uncheckedSwitchWorkspaceId = NULL;

  /**
   * Whether publish-time staging is running.
   *
   * @see ::executePublishTimeStaging()
   */
  private bool $publishTimeStaging = FALSE;

  /**
   * Whether publish-time staging is currently running.
   *
   * TRUE exactly while CanvasWorkspacePublisher stages snapshot-held drafts
   * into the workspace and calls Workspace::publish(). Doubles as the "this
   * publish went through the validated Canvas pipeline" signal for the
   * pre-publish snapshot gate, and tells staged-write listeners that these
   * saves are not editorial writes.
   *
   * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher::publish()
   * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber::onPrePublish()
   */
  public function isPublishTimeStaging(): bool {
    return $this->publishTimeStaging;
  }

  /**
   * Runs $callable with publish-time staging flagged as in progress.
   *
   * @see ::isPublishTimeStaging()
   */
  public function executePublishTimeStaging(callable $callable): mixed {
    $previous = $this->publishTimeStaging;
    $this->publishTimeStaging = TRUE;
    try {
      return $callable();
    }
    finally {
      $this->publishTimeStaging = $previous;
    }
  }

  /**
   * Whether a Canvas staged config write is currently running.
   *
   * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceConfigEntityPersist::isStagingWrite()
   */
  public function isStagingConfigWrite(): bool {
    return $this->configEntityPersist->isStagingWrite();
  }

  /**
   * Whether core negotiated an active workspace for this request.
   */
  public function hasActiveWorkspace(): bool {
    return $this->workspaceManager->hasActiveWorkspace();
  }

  /**
   * Whether $entity's drafts stage as workspace-scoped configuration.
   *
   * Component tree config entities (content templates, patterns, page
   * variants) stage every draft as a config save inside the staging
   * workspace, which the Workspace Config module stores in that workspace's
   * partition. Inside the workspace the draft then resolves as regular
   * configuration for every consumer (entity view builders, Views, page
   * variant resolution), not only through the auto-save read API.
   *
   * Other Canvas config entities keep snapshot staging: code components and
   * asset libraries compile and write asset files on save, and staged config
   * updates apply to a different target on save, neither of which a draft
   * should trigger.
   *
   * @see ::persistConfigEntity()
   * @see ::loadWorkspaceStagedConfigAutoSave()
   */
  public function usesWorkspaceConfigStaging(EntityInterface $entity): bool {
    return $entity instanceof ComponentTreeConfigEntityBase && $this->usesWorkspaceConfigStagingForType($entity->getEntityTypeId());
  }

  /**
   * Type-level counterpart of ::usesWorkspaceConfigStaging().
   */
  private function usesWorkspaceConfigStagingForType(string $entity_type_id): bool {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return FALSE;
    }
    $class = $this->entityTypeManager->getDefinition($entity_type_id)->getClass();
    if (!\is_a($class, ComponentTreeConfigEntityBase::class, TRUE)) {
      return FALSE;
    }
    // A config entity type the site has not declared workspace-safe cannot be
    // written inside a workspace at all; its drafts stay in snapshot rows.
    // @see \Drupal\canvas\Hook\WorkspaceAutoSaveHooks::workspaceConfigSafeListAlter()
    return $this->workspaceConfigInformation->isConfigEntityTypeIdWorkspaceSafe($entity_type_id);
  }

  /**
   * Whether a bookkeeping switch into this workspace is in progress.
   *
   * @see \Drupal\canvas\Hook\WorkspaceAutoSaveRevisionHooks::workspaceAccess()
   */
  public function isUncheckedSwitchInto(string $workspace_id): bool {
    return $this->uncheckedSwitchWorkspaceId === $workspace_id;
  }

  /**
   * Runs a callback inside a workspace, regardless of who triggered it.
   *
   * Staging bookkeeping (pending lists, discarding staged revisions when an
   * entity is deleted, lock lookups) runs in whichever request causes it: a
   * field admin deleting a field storage, a content editor deleting a node.
   * Core only lets the current user switch into a workspace they may view,
   * but bookkeeping is not a user action, so view access is granted for the
   * switch's duration through hook_workspace_access(). Access results are
   * statically cached per account: a cached denial is dropped before the
   * switch and the grant afterwards.
   *
   * @see \Drupal\workspaces\WorkspaceManager::doSwitchWorkspace()
   * @see \Drupal\canvas\Hook\WorkspaceAutoSaveRevisionHooks::workspaceAccess()
   */
  private function executeInWorkspaceUnchecked(string $workspace_id, callable $callback): mixed {
    $wm = $this->workspaceManager;
    $handler = $this->entityTypeManager->getAccessControlHandler('workspace');
    $previous = $this->uncheckedSwitchWorkspaceId;
    $this->uncheckedSwitchWorkspaceId = $workspace_id;
    $handler->resetCache();
    try {
      return $wm->executeInWorkspace($workspace_id, $callback);
    }
    finally {
      $this->uncheckedSwitchWorkspaceId = $previous;
      $handler->resetCache();
    }
  }

  /**
   * Runs a bookkeeping callback inside the staging workspace.
   *
   * A passthrough when the staging workspace is already active: every
   * workspace switch dispatches WorkspaceSwitchEvent, which resets config
   * and field definition caches, so needless round trips are avoided.
   *
   * @see ::executeInWorkspaceUnchecked()
   */
  private function executeInStagingWorkspaceUnchecked(callable $callback): mixed {
    $staging_workspace_id = $this->getStagingWorkspaceId();
    if ($this->workspaceManager->getActiveWorkspace()?->id() === $staging_workspace_id) {
      return $callback();
    }
    return $this->executeInWorkspaceUnchecked($staging_workspace_id, $callback);
  }

  /**
   * The snapshot target langcode for an entity.
   *
   * Language-less targets (config entities) use LANGCODE_NOT_SPECIFIED, not
   * an empty string: StringItem treats '' as an empty field, which would be
   * stored as NULL, break entity query conditions and (because SQL unique
   * indexes ignore NULL) void the one-row-per-target unique key.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
   */
  public static function snapshotLangcode(EntityInterface $entity): string {
    return $entity instanceof TranslatableInterface ? $entity->language()->getId() : LanguageInterface::LANGCODE_NOT_SPECIFIED;
  }

  /**
   * Whether the entity's staged state lives in the buffer or a snapshot row.
   *
   * TRUE means the draft is not (yet) a workspace-tracked revision or a
   * workspace-staged config object, so a workspace publish must stage it
   * into the workspace first.
   *
   * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
   */
  public function hasSnapshotStaging(EntityInterface $entity): bool {
    if ($entity->id() === NULL) {
      return FALSE;
    }
    $buffer_row = $this->pendingBuffer->get(AutoSaveManager::getAutoSaveKey($entity));
    if ($buffer_row !== NULL && isset($buffer_row['data'])) {
      return TRUE;
    }
    if ($this->snapshotRepository->resolveLatestStaged($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity)) !== NULL) {
      return TRUE;
    }
    return FALSE;
  }

  public function hasWorkspaceStaging(EntityInterface $entity): bool {
    if ($entity->id() === NULL) {
      return FALSE;
    }
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $buffer_row = $this->pendingBuffer->get($key);
    if ($buffer_row !== NULL && isset($buffer_row['data'])) {
      return TRUE;
    }
    if ($this->snapshotRepository->resolveLatestStaged($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity)) !== NULL) {
      return TRUE;
    }
    if ($entity instanceof ContentEntityInterface) {
      return !$this->loadWorkspaceStagedContentAutoSave($entity)->isEmpty();
    }
    if ($this->usesWorkspaceConfigStaging($entity)) {
      \assert($entity instanceof ComponentTreeConfigEntityBase);
      return !$this->loadWorkspaceStagedConfigAutoSave($entity)->isEmpty();
    }
    return FALSE;
  }

  /**
   * The workspace, other than the staging one, that owns the entity, if any.
   *
   * @return string|null
   *   The owning workspace ID, or NULL when the entity is unowned or owned
   *   by the staging workspace.
   */
  public function getOwningWorkspaceId(EntityInterface $entity): ?string {
    if (!$entity instanceof ContentEntityInterface || $entity->id() === NULL) {
      return NULL;
    }
    $tracking_ids = $this->workspaceAssociation->getEntityTrackingWorkspaceIds($entity, TRUE);
    $others = \array_diff($tracking_ids, [$this->getStagingWorkspaceId()]);
    $first = \reset($others);
    return $first === FALSE ? NULL : (string) $first;
  }

  /**
   * Attribution for the pending change locking an entity to a workspace.
   *
   * @return array{workspaceId: string, ownerId: int, updated: int}|null
   *   The owning workspace with the staged revision's editor and time, or
   *   NULL when the entity is unowned or owned by the staging workspace.
   */
  public function getOwningWorkspaceLockInfo(EntityInterface $entity): ?array {
    $owning_id = $this->getOwningWorkspaceId($entity);
    if ($owning_id === NULL) {
      return NULL;
    }
    \assert($entity instanceof ContentEntityInterface);
    $id = $entity->id();
    if ($id === NULL) {
      return NULL;
    }
    return $this->executeInWorkspaceUnchecked($owning_id, function () use ($entity, $id, $owning_id): array {
      $staged = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->load($id);
      $info = ['workspaceId' => $owning_id, 'ownerId' => 0, 'updated' => (int) $this->time->getRequestTime()];
      if ($staged instanceof ContentEntityInterface) {
        $info['ownerId'] = self::stagedRevisionOwner($staged);
        $info['updated'] = $this->stagedRevisionTime($staged);
      }
      return $info;
    });
  }

  /**
   * Rejects a staged write for an entity owned by another workspace.
   *
   * @throws \Drupal\canvas\Workspace\WorkspaceEntityLockedException
   */
  private function assertNotLockedInAnotherWorkspace(EntityInterface $entity): void {
    $owning_id = $this->getOwningWorkspaceId($entity);
    if ($owning_id === NULL) {
      return;
    }
    $owning = $this->entityTypeManager->getStorage('workspace')->load($owning_id);
    throw new WorkspaceEntityLockedException($owning_id, $owning !== NULL ? (string) $owning->label() : $owning_id);
  }

  /**
   * Whether the entity has a staging workspace association row.
   */
  private function isEntityTrackedInStagingWorkspace(EntityInterface $entity): bool {
    if ($entity->id() === NULL) {
      return FALSE;
    }
    $tracked = $this->workspaceAssociation->getTrackedEntities(
      $this->getStagingWorkspaceId(),
      $entity->getEntityTypeId(),
      [(string) $entity->id()],
    );
    return !empty($tracked[$entity->getEntityTypeId()]);
  }

  /**
   * Entity to use when building the layout API response (tree + preview HTML).
   */
  public function getEntityForLayoutEditing(ContentEntityInterface $entity): ContentEntityInterface {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if ($this->pendingBuffer->has($key)) {
      $this->cache->delete($key);
    }
    elseif ($this->isEntityTrackedInStagingWorkspace($entity)) {
      $this->cache->delete($key);
    }
    $auto_save = $this->loadAutoSaveEntity($entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof ContentEntityInterface);
      return $auto_save->entity;
    }
    if (!$this->isEntityTrackedInStagingWorkspace($entity)) {
      return $entity;
    }
    $id = $entity->id();
    \assert($id !== NULL);
    $reloaded = $this->executeInWorkspaceUnchecked($this->getStagingWorkspaceId(), function () use ($entity, $id) {
      $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
      $loaded = $storage->load($id);
      return $loaded instanceof ContentEntityInterface ? $loaded : $entity;
    });
    return $reloaded;
  }

  private function loadWorkspaceStagedContentAutoSave(ContentEntityInterface $entity): AutoSaveEntity {
    if (!$this->isEntityTrackedInStagingWorkspace($entity)) {
      return AutoSaveEntity::empty();
    }
    $id = $entity->id();
    \assert($id !== NULL);
    $wm = $this->workspaceManager;
    $key = AutoSaveManager::getAutoSaveKey($entity);
    return $this->executeInWorkspaceUnchecked($this->getStagingWorkspaceId(), function () use ($entity, $id, $key, $wm): AutoSaveEntity {
      $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
      $active = $storage->load($id);
      if (!$active instanceof ContentEntityInterface) {
        return AutoSaveEntity::empty();
      }
      $original = $wm->executeOutsideWorkspace(function () use ($storage, $id) {
        $unchanged = $storage->loadUnchanged($id);
        return $unchanged instanceof ContentEntityInterface ? $unchanged : $storage->load($id);
      });
      if (!$original instanceof ContentEntityInterface) {
        return AutoSaveEntity::empty();
      }
      // Auto-save entries are per translation: compare and return the
      // translation matching the requested entity's language. A translation
      // the staged revision does not carry (e.g. after the draft's langcode
      // changed) has no draft.
      // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
      $langcode = $entity->language()->getId();
      if (!$active->hasTranslation($langcode)) {
        return AutoSaveEntity::empty();
      }
      $active = $active->getTranslation($langcode);
      if ($original->hasTranslation($langcode)) {
        $original = $original->getTranslation($langcode);
      }
      $this->applyRecordedDraftPath($active, $key);
      $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($active));
      $unchanged_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($original));
      if (\hash_equals($unchanged_hash, $hash) && !$this->hasStoredFormViolations($key)) {
        return AutoSaveEntity::empty();
      }
      $auto_save_entity = new AutoSaveEntity($active, $hash, $this->getStagedClientId($key), $this->stagedRevisionTime($active));
      $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
      return $auto_save_entity;
    });
  }

  public function importLegacyArray(EntityInterface $entity, array $legacy): void {
    \assert(isset($legacy['data']) && \is_array($legacy['data']));
    $storage = $this->entityTypeManager->getStorage($legacy['entity_type']);
    $staged = $storage->create($legacy['data']);
    \assert($staged instanceof EntityInterface);
    // The staged draft targets $entity: enforce its identity, or the persist
    // would create a new entity instead of staging a revision of the existing
    // one. ::create() marks the reconstruction as new even when the legacy
    // data carries the id, and pre-1.0 legacy rows may lack the id entirely.
    if ($staged instanceof ContentEntityInterface && $entity instanceof ContentEntityInterface) {
      $entity_type = $storage->getEntityType();
      foreach (['id', 'uuid', 'revision'] as $key_name) {
        $key = $entity_type->getKey($key_name);
        if (\is_string($key) && $key !== '' && $staged->get($key)->isEmpty() && !$entity->get($key)->isEmpty()) {
          $staged->set($key, $entity->get($key)->value);
        }
      }
      $staged->enforceIsNew(FALSE);
      $staged->updateLoadedRevisionId();
      // ::create() pre-marks the entity as a new revision, which makes the
      // later setNewRevision(TRUE) in workspaces' entity_presave a no-op
      // that skips clearing the revision key; the save would then insert a
      // duplicate of the grafted revision id. Reset the flag so that
      // transition runs and a fresh revision id is assigned.
      $staged->setNewRevision(FALSE);
    }
    // Pass the legacy entry through so its metadata (owner, updated,
    // original_hash, conflict retention) survives the migration.
    $this->persistStagedEntity($staged, $legacy['client_id'] ?? NULL, TRUE, $legacy);
  }

  /**
   * @param array<string, mixed>|null $entry
   *   The full auto-save entry as built by AutoSaveManager::saveEntity()
   *   (data, langcode, is_default_translation, original_hash, conflict
   *   retention, …). Its metadata (owner, updated, original_hash) is kept
   *   alongside the staged draft so conflict detection and symmetric
   *   translation keep their data.
   */
  public function persistStagedEntity(EntityInterface $entity, ?string $clientId, bool $immediateContentPersist = FALSE, ?array $entry = NULL): void {
    // An entity's pending work lives in exactly one workspace at a time
    // (core's tracking); a staged write for an entity owned by another
    // workspace is rejected with the owning workspace named, never silently
    // retargeted.
    $this->assertNotLockedInAnotherWorkspace($entity);

    // A negotiated workspace whose entity has been deleted mid-session must
    // fail the write: falling through to another store (or Live) would
    // silently misplace the draft.
    if ($this->workspaceManager->hasActiveWorkspace()
      && $this->entityTypeManager->getStorage('workspace')->load($this->getStagingWorkspaceId()) === NULL) {
      throw new \RuntimeException(\sprintf('The active workspace "%s" no longer exists; the auto-save was rejected.', $this->getStagingWorkspaceId()));
    }

    // Scope the workspace context to the persist operation: permanently
    // activating the workspace would leak into subsequent entity saves in the
    // same process (CLI, tests, long-running workers).
    $this->snapshotRepository->executeInStagingWorkspace(function () use ($entity, $clientId, $immediateContentPersist, $entry): void {
      if ($this->usesWorkspaceConfigStaging($entity)) {
        \assert($entity instanceof ComponentTreeConfigEntityBase);
        $this->persistConfigEntity($entity, $clientId, $immediateContentPersist, $entry);
        return;
      }
      if ($entity instanceof ConfigEntityInterface) {
          $this->configEntityPersist->persistSnapshot($entity, $clientId);
        return;
      }
      if ($entity instanceof ContentEntityInterface) {
        $this->persistContentEntity($entity, $clientId, $immediateContentPersist, $entry);
        return;
      }
      throw new \InvalidArgumentException('Unsupported entity for workspace auto-save.');
    });
  }

  /**
   * Stages a component tree config entity draft as workspace-scoped config.
   *
   * Deferred to kernel terminate on preview-critical routes, exactly like
   * content: a config save is a synchronous storage write with cache
   * invalidation attached, and one flush per request also bounds that
   * invalidation to once per target per request.
   *
   * @see ::usesWorkspaceConfigStaging()
   * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceConfigEntityPersist
   */
  private function persistConfigEntity(ComponentTreeConfigEntityBase $entity, ?string $clientId, bool $immediatePersist, ?array $entry): void {
    if ($immediatePersist || !$this->shouldDeferContentPersistToTerminate()) {
      $this->configEntityPersist->persist($entity, $clientId, $entry);
      return;
    }
    $this->deferredFlusher->enqueue($entity, $clientId, $entry);
  }

  private function persistContentEntity(ContentEntityInterface $entity, ?string $clientId, bool $immediateContentPersist, ?array $entry = NULL): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $use_immediate = $immediateContentPersist || !$this->shouldDeferContentPersistToTerminate();
    if ($use_immediate) {
      $this->contentEntityPersist->persist($entity, $clientId);
      // Workspace revisions cannot record which client instance produced the
      // draft nor the hash of the Live base it started from, but
      // concurrent-edit validation and conflict detection need both.
      // @see ::getStagedClientId()
      // @see ::getStagedEntryMetadata()
      $this->pendingBuffer->set($key, ['client_id' => $clientId] + self::entryMetadata($entry));
      return;
    }
    $this->deferredFlusher->enqueue($entity, $clientId, $entry);
  }

  /**
   * Conflict-detection metadata to carry alongside workspace staging.
   *
   * @param array<string, mixed>|null $entry
   *
   * @return array<string, mixed>
   */
  public static function entryMetadata(?array $entry): array {
    $metadata = \array_intersect_key($entry ?? [], \array_flip([
      'original_hash',
      'owner',
      'updated',
      AutoSaveManager::AUTO_SAVE_CONFLICT_KEY,
      self::DRAFT_PATH_KEY,
    ]));
    // Record the draft's `path` value verbatim: on a staged revision the
    // computed path field resolves through alias storage, which cannot
    // represent a draft that cleared (or never set) its alias.
    // @see ::applyRecordedDraftPath()
    if (!\array_key_exists(self::DRAFT_PATH_KEY, $metadata) && isset($entry['data']) && \is_array($entry['data'])) {
      $metadata[self::DRAFT_PATH_KEY] = $entry['data']['path'] ?? [];
    }
    return $metadata;
  }

  /**
   * Metadata key holding a content draft's verbatim `path` field value.
   */
  public const string DRAFT_PATH_KEY = 'draft_path';

  /**
   * Overrides a staged entity's computed path with the recorded draft value.
   *
   * The alias lookup powering the computed path field is not revision-aware:
   * inside the workspace it resolves the staged alias, and a draft that
   * cleared its alias would still present the previously staged (or Live)
   * one. The verbatim value recorded at staging time is authoritative.
   */
  private function applyRecordedDraftPath(ContentEntityInterface $entity, string $key): void {
    if (!$entity->hasField('path')) {
      return;
    }
    $metadata = $this->getStagedEntryMetadata($key);
    if (\is_array($metadata) && \array_key_exists(self::DRAFT_PATH_KEY, $metadata)) {
      $draft_path = $metadata[self::DRAFT_PATH_KEY];
      if (!$draft_path) {
        // A cleared alias is recorded as an empty value; explicit NULL resets
        // the computed path field instead of assigning the empty value.
        $draft_path = NULL;
      }
      $entity->set('path', $draft_path);
    }
  }

  /**
   * Whether stored entity form violations exist for an auto-save key.
   *
   * A draft whose persisted content equals the Live entity is still a pending
   * change when the client submitted entity form values that failed
   * validation: the recorded violations (with their invalid values) are the
   * only thing distinguishing the draft, and publishing must surface them.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::saveEntityFormViolations()
   * @see \Drupal\canvas\Controller\ApiLayoutController::getFilteredEntityData()
   */
  private function hasStoredFormViolations(string $key): bool {
    return $this->keyValueFactory->get(AutoSaveManager::FORM_VIOLATIONS_STORE)->has($key);
  }

  /**
   * The client instance id that produced the workspace-staged draft, if known.
   */
  private function getStagedClientId(string $key): ?string {
    $client_id = $this->getStagedEntryMetadata($key)['client_id'] ?? NULL;
    return \is_string($client_id) ? $client_id : NULL;
  }

  /**
   * Auto-save entry metadata recorded alongside workspace staging.
   *
   * @return array<string, mixed>|null
   *   The recorded metadata (client_id, original_hash, conflict retention),
   *   or NULL when nothing is recorded for $key.
   */
  public function getStagedEntryMetadata(string $key): ?array {
    return $this->pendingBuffer->get($key);
  }

  /**
   * Re-keys staging bookkeeping after a content draft's langcode changed.
   *
   * A content draft's auto-save key carries its langcode. The staged revision
   * needs no migration (its key derives from the entity), but the metadata
   * recorded alongside it (client instance, stored-entity hash, draft path,
   * conflict retention) and any snapshot row are keyed by the old langcode.
   *
   * @param string|null $original_hash
   *   The stored-entity hash to record under the new key, or NULL to keep the
   *   recorded one.
   *
   * @return bool
   *   TRUE when bookkeeping was re-keyed, FALSE when nothing was recorded.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::migrateLangcode()
   */
  public function migrateStagingKey(ContentEntityInterface $entity, string $old_langcode, ?string $original_hash): bool {
    $type_id = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $new_langcode = $entity->language()->getId();
    $old_key = $this->getStagingWorkspaceId() . ':' . $type_id . ':' . $id . ':' . $old_langcode;
    $new_key = AutoSaveManager::getAutoSaveKey($entity);
    $langcode_field = $entity->getEntityType()->getKey('langcode');

    $rekey = static function (array $row) use ($original_hash, $new_langcode, $langcode_field): array {
      if ($original_hash !== NULL) {
        $row[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY] = $original_hash;
      }
      if (isset($row['langcode'])) {
        $row['langcode'] = $new_langcode;
      }
      // Buffered rows carry the serialized draft in the field-items format
      // produced by AutoSaveManager::toStorableArray(): update its langcode
      // so the reconstructed entity carries the new language.
      if (\is_string($langcode_field) && isset($row['data'][$langcode_field])) {
        $row['data'][$langcode_field] = [['value' => $new_langcode]];
      }
      // Saving the entity with a new langcode deletes the path alias of the
      // old language (path_entity_translation_delete()); the alias text itself
      // survives in the draft. Drop the alias id and move the item to the new
      // language, so the draft creates a fresh alias instead of updating a
      // deleted one.
      // @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
      foreach ([self::DRAFT_PATH_KEY, 'path'] as $path_key) {
        $items = $path_key === 'path' ? ($row['data']['path'] ?? NULL) : ($row[$path_key] ?? NULL);
        if (!\is_array($items)) {
          continue;
        }
        foreach ($items as &$path_item) {
          if (\is_array($path_item)) {
            unset($path_item['pid']);
            if (\array_key_exists('langcode', $path_item)) {
              $path_item['langcode'] = $new_langcode;
            }
          }
        }
        unset($path_item);
        if ($path_key === 'path') {
          $row['data']['path'] = $items;
        }
        else {
          $row[$path_key] = $items;
        }
      }
      return $row;
    };

    $moved = FALSE;
    $buffered = $this->pendingBuffer->get($old_key);
    if ($buffered !== NULL) {
      $this->pendingBuffer->set($new_key, $rekey($buffered));
      $this->pendingBuffer->delete($old_key);
      $moved = TRUE;
    }
    $snapshot = $this->snapshotRepository->resolveLatestStaged($type_id, $id, $old_langcode);
    if ($snapshot !== NULL) {
      $payload = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
      $payload = $rekey(['data' => $payload])['data'];
      $snapshot->set('target_langcode', $new_langcode);
      $snapshot->set('payload', \json_encode($payload, JSON_THROW_ON_ERROR));
      $snapshot->save();
      $moved = TRUE;
    }
    // The staged revision still carries the old langcode: re-stage it in the
    // new language so the draft follows the entity (and no draft remains under
    // the old langcode). Its alias follows through the recorded draft path.
    if ($this->isEntityTrackedInStagingWorkspace($entity)) {
      $this->executeInStagingWorkspaceUnchecked(function () use ($type_id, $id, $old_langcode, $new_langcode, $langcode_field): void {
        $storage = $this->entityTypeManager->getStorage($type_id);
        $storage->resetCache([$id]);
        $staged = $storage->load($id);
        if (!$staged instanceof ContentEntityInterface || !$staged->hasTranslation($old_langcode) || !\is_string($langcode_field)) {
          return;
        }
        $staged->getTranslation($old_langcode)->set($langcode_field, $new_langcode)->save();
      });
      $moved = TRUE;
    }
    if ($moved) {
      $this->cache->delete($old_key);
      $this->cache->delete($new_key);
    }
    return $moved;
  }

  /**
   * Advances the recorded stored-entity hash after a conflict resolution.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::resolveConflict()
   * @see ::getStagedEntryMetadata()
   */
  public function advanceStagedEntryOriginalHash(EntityInterface $entity, string $hash): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $this->pendingBuffer->set($key, [AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY => $hash] + ($this->pendingBuffer->get($key) ?? []));
    $this->cache->delete($key);
  }

  /**
   * Defers DB writes for Canvas API routes (e.g. layout preview PATCH) only.
   *
   * CLI, Drush, and kernel tests without a matching route use immediate
   * persist.
   * Set CANVAS_TEST_FORCE_DEFER_AUTOSAVE=1 to exercise defer in unit tests.
   */
  private function shouldDeferContentPersistToTerminate(): bool {
    $force = \getenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE');
    if ($force === '1' || $force === 'true') {
      return TRUE;
    }
    $name = $this->routeMatch->getRouteName();
    return \is_string($name) && \str_starts_with($name, 'canvas.api.');
  }

  public function loadAutoSaveEntity(EntityInterface $entity, bool $bypassCache = FALSE): AutoSaveEntity {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if (!$bypassCache) {
      $cached = $this->cache->get($key);
      if ($cached) {
        \assert($cached->data instanceof AutoSaveEntity);
        return $cached->data;
      }
    }

    // Staging resolves in a fixed order for every entity type: the pending
    // write buffer, then a snapshot row, then the primary store (a
    // workspace-tracked revision, or workspace-scoped configuration).
    // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceContentEntityPersist
    // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceConfigEntityPersist
    $pending = $this->loadPendingBufferedAutoSave($entity);
    if ($pending !== NULL) {
      $this->cache->set($key, $pending, tags: [AutoSaveManager::CACHE_TAG]);
      return $pending;
    }

    if ($entity->id() !== NULL) {
      $snapshot = $this->snapshotRepository->resolveLatestStaged($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity));
      if ($snapshot !== NULL) {
        $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
        $staged = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->create($data);
        $auto_save_entity = new AutoSaveEntity($staged, $snapshot->getDataHash(), $snapshot->getClientInstanceId(), (int) ($snapshot->getChangedTime() ?? $this->time->getRequestTime()));
        $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
        return $auto_save_entity;
      }
    }

    if ($entity instanceof ContentEntityInterface) {
      return $this->loadWorkspaceStagedContentAutoSave($entity);
    }

    if ($this->usesWorkspaceConfigStaging($entity)) {
      \assert($entity instanceof ComponentTreeConfigEntityBase);
      return $this->loadWorkspaceStagedConfigAutoSave($entity);
    }

    return AutoSaveEntity::empty();
  }

  /**
   * Loads a draft staged as workspace-scoped configuration.
   *
   * The workspace-scoped copy is the draft. Dirty state is derived: a copy
   * whose normalized data equals its base (the Live configuration, or the
   * copy the config was created as inside the workspace) is not a pending
   * change, unless entity form violations are recorded for it.
   *
   * @see ::getBaseHash()
   */
  private function loadWorkspaceStagedConfigAutoSave(ComponentTreeConfigEntityBase $entity): AutoSaveEntity {
    $id = $entity->id();
    if ($id === NULL) {
      return AutoSaveEntity::empty();
    }
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $staged = $this->executeInStagingWorkspaceUnchecked(static fn () => $storage->loadUnchanged($id));
    if (!$staged instanceof ComponentTreeConfigEntityBase) {
      return AutoSaveEntity::empty();
    }
    $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($staged));
    $base_hash = $this->getBaseHash($entity);
    if ($base_hash !== NULL && \hash_equals($base_hash, $hash) && !$this->hasStoredFormViolations($key)) {
      return AutoSaveEntity::empty();
    }
    // Hand out a copy: callers may adjust the draft (e.g. force it enabled
    // for preview), which must not leak into the entity static cache.
    $draft = clone $staged;
    $draft->enforceIsNew(FALSE);
    $metadata = $this->getStagedEntryMetadata($key);
    $updated = isset($metadata['updated']) && \is_numeric($metadata['updated']) ? (int) $metadata['updated'] : NULL;
    $auto_save_entity = new AutoSaveEntity($draft, $hash, $this->getStagedClientId($key), $updated);
    $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * The normalized hash of the saved copy an auto-save draft is based on.
   *
   * Content and snapshot-staged config: the copy ::loadUnchangedBase()
   * returns. Config staged as workspace-scoped configuration: the Live
   * configuration when one exists, otherwise the copy it was created as
   * inside the workspace (recorded once in the staging metadata). Never the
   * staged copy itself: that is the draft, and comparing a draft against
   * itself would make every re-save look like a reset to the original values.
   *
   * @return string|null
   *   The base hash, or NULL when the entity has no saved base at all.
   */
  public function getBaseHash(EntityInterface $entity): ?string {
    $id = $entity->id();
    if ($id === NULL) {
      return NULL;
    }
    $base = $this->loadUnchangedBase($entity->getEntityTypeId(), (string) $id);
    if ($base !== NULL) {
      return AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($base));
    }
    if ($this->usesWorkspaceConfigStaging($entity)) {
      \assert($entity instanceof ComponentTreeConfigEntityBase);
      return $this->ensureConfigBaseRecorded($entity);
    }
    return NULL;
  }

  /**
   * Records (once) and returns the base hash of workspace-created config.
   *
   * Nothing can have been auto-saved before the base is recorded: every
   * staged write and every read of the draft's state resolves the base
   * first, so the workspace-scoped copy found on first sight is the copy the
   * config was created as.
   *
   * @return string|null
   *   The recorded base hash, or NULL when the config does not exist in the
   *   staging workspace either.
   *
   * @see self::CONFIG_BASE_HASH_KEY
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   */
  private function ensureConfigBaseRecorded(ComponentTreeConfigEntityBase $entity): ?string {
    $id = $entity->id();
    \assert($id !== NULL);
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $metadata = $this->pendingBuffer->get($key) ?? [];
    $recorded = $metadata[self::CONFIG_BASE_HASH_KEY] ?? NULL;
    if (\is_string($recorded)) {
      return $recorded;
    }
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $staged = $this->executeInStagingWorkspaceUnchecked(static fn () => $storage->loadUnchanged($id));
    if (!$staged instanceof ComponentTreeConfigEntityBase) {
      return NULL;
    }
    $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($staged));
    $this->pendingBuffer->set($key, [self::CONFIG_BASE_HASH_KEY => $hash] + $metadata);
    return $hash;
  }

  /**
   * Reacts to a config save made inside the staging workspace by other code.
   *
   * With workspace-scoped staging the saved copy is the draft, so there is no
   * separate draft to reconcile. Only bookkeeping remains: the memoized draft
   * is stale, and a config object that was just created inside the workspace
   * (no Live copy) needs its base recorded before anything edits it.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   */
  public function onWorkspaceStagedConfigSaved(ComponentTreeConfigEntityBase $entity): void {
    $id = $entity->id();
    if ($id === NULL) {
      return;
    }
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $this->cache->delete($key);
    if ($this->loadUnchangedBase($entity->getEntityTypeId(), (string) $id) === NULL) {
      $this->ensureConfigBaseRecorded($entity);
    }
  }

  /**
   * Loads a deferred write still sitting in the pending buffer.
   *
   * Content and workspace-staged config alike: both defer their persist to
   * kernel terminate on preview-critical routes.
   */
  private function loadPendingBufferedAutoSave(EntityInterface $entity): ?AutoSaveEntity {
    if ($entity->id() === NULL) {
      return NULL;
    }
    if (!$entity instanceof ContentEntityInterface && !$this->usesWorkspaceConfigStaging($entity)) {
      return NULL;
    }
    $row = $this->pendingBuffer->get(AutoSaveManager::getAutoSaveKey($entity));
    if ($row === NULL || !isset($row['entity_type'], $row['data'], $row['data_hash'])) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage($row['entity_type']);
    $staged = $storage->create($row['data']);
    return new AutoSaveEntity($staged, $row['data_hash'], $row['client_id'] ?? NULL, isset($row['updated']) ? (int) $row['updated'] : NULL);
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getAllList(): array {
    /** @var array<string, array<string, mixed>> $out */
    $out = [];
    foreach ($this->snapshotRepository->loadAll() as $snapshot) {
      $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
      // Some labels are derived (e.g. PageRegion), so an unsaved entity object
      // is needed to compute the label the way the entity type defines it.
      $staged = $this->entityTypeManager->getStorage($snapshot->getTargetEntityTypeId())->create($data);
      // Derive the key from the staged entity so it matches getAutoSaveKey()
      // exactly (config keys carry no langcode, content keys always do).
      $key = AutoSaveManager::getAutoSaveKey($staged);
      $out[$key] = [
        'entity_type' => $snapshot->getTargetEntityTypeId(),
        'entity_id' => $snapshot->getTargetEntityId(),
        'data' => $data,
        'langcode' => $staged->language()->getId(),
        'is_default_translation' => !($staged instanceof TranslatableInterface) || $staged->isDefaultTranslation(),
        'label' => self::labelForAutoSaveList($staged),
        'data_hash' => $snapshot->getDataHash(),
        'client_id' => $snapshot->getClientInstanceId(),
        'owner' => (int) $snapshot->getOwnerId(),
        'updated' => (int) ($snapshot->getChangedTime() ?? $this->time->getRequestTime()),
      ];
    }

    $this->appendWorkspaceTrackedContentEntities($out);
    $this->appendPendingBufferEntities($out);

    \ksort($out);
    return $out;
  }

  /**
   * Adds content entities present only in the pending (pre-revision) buffer.
   *
   * @param array<string, array<string, mixed>> $out
   */
  private function appendPendingBufferEntities(array &$out): void {
    $prefix = $this->getStagingWorkspaceId() . ':';
    foreach ($this->pendingBuffer->getAll() as $kv_key => $row) {
      // Buffer rows record their workspace in the key prefix; list only the
      // active workspace's rows.
      if (!\str_starts_with((string) $kv_key, $prefix)) {
        continue;
      }
      if (isset($out[$kv_key])) {
        continue;
      }
      if (!isset($row['entity_type'], $row['entity_id'], $row['data'], $row['data_hash'])) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($row['entity_type']);
      $created = $storage->create($row['data']);
      $langcode = $row['langcode'] ?? NULL;
      $metadata = self::entryMetadata($row);
      // The recorded draft path is already part of the row's 'data'; it is
      // staging bookkeeping, not a list row property.
      // @see ::appendWorkspaceTrackedContentEntities()
      unset($metadata[self::DRAFT_PATH_KEY]);
      $out[$kv_key] = $metadata + [
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
        'data' => $row['data'],
        'langcode' => $langcode,
        'is_default_translation' => $row['is_default_translation'] ?? TRUE,
        'label' => self::labelForAutoSaveList($created),
        'data_hash' => $row['data_hash'],
        'client_id' => $row['client_id'] ?? NULL,
        'owner' => (int) ($row['owner'] ?? 0),
        'updated' => (int) ($row['updated'] ?? $this->time->getRequestTime()),
      ];
    }
  }

  /**
   * Reconstructs all staged drafts of one entity type, unsaved.
   *
   * Config entity drafts live in snapshot rows, so this read never activates
   * the auto-save workspace. That matters for callers reacting to events
   * triggered by users without workspace view access, e.g. the config-delete
   * hook firing for arbitrary config deletions.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigDelete()
   */
  public function loadStagedEntitiesOfType(string $entity_type_id): array {
    $entities = [];
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    foreach ($this->snapshotRepository->loadAll() as $snapshot) {
      if ($snapshot->getTargetEntityTypeId() !== $entity_type_id) {
        continue;
      }
      $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
      $entities[] = $storage->create($data);
    }
    return $entities;
  }

  /**
   * Adds content staged as tracked revisions in the auto-save workspace.
   *
   * Node and other content entities are persisted via $entity->save() in the
   * workspace, so the pending list must read workspace association data to
   * match the client "changed" state.
   *
   * @param array<string, array<string, mixed>> $out
   */
  private function appendWorkspaceTrackedContentEntities(array &$out): void {
    $staging_workspace_id = $this->getStagingWorkspaceId();
    $workspace = $this->entityTypeManager->getStorage('workspace')->load($staging_workspace_id);
    if ($workspace === NULL) {
      return;
    }
    $wm = $this->workspaceManager;
    // The workspace must be active for this read: computed fields on staged
    // revisions (e.g. a page's path alias, staged as a dependent path_alias
    // entity) only resolve to their staged values inside the workspace, and
    // the emitted data_hash must match what per-entity staging reads produce.
    $this->executeInWorkspaceUnchecked($staging_workspace_id, function () use (&$out, $wm, $staging_workspace_id): void {
      $tracked = $this->workspaceAssociation->getTrackedEntities($staging_workspace_id);
      foreach ($tracked as $entity_type_id => $revision_map) {
        // Entities implicitly staged alongside a host item (e.g. the URL
        // alias written when a page with a changed path is staged) are not
        // pending changes of their own: they follow their host item through
        // publish and discard.
        // @see ::discardWorkspaceStagedContentEntity()
        if (\in_array($entity_type_id, self::DEPENDENT_ENTITY_TYPE_IDS, TRUE)) {
          continue;
        }
        // Config changes staged by the workspace_config module are tracked as
        // workspace_config rows; present each as the config object it stages.
        if ($entity_type_id === 'workspace_config') {
          $this->appendStagedWorkspaceConfig($out, \array_unique($revision_map));
          continue;
        }
        foreach ($revision_map as $entity_id) {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
          $entity = $storage->load($entity_id);
          if (!$entity instanceof ContentEntityInterface) {
            continue;
          }
          $canonical = $wm->executeOutsideWorkspace(function () use ($storage, $entity_id) {
            $unchanged = $storage->loadUnchanged($entity_id);
            return $unchanged instanceof ContentEntityInterface ? $unchanged : $storage->load($entity_id);
          });
          // Auto-save entries are per translation: emit one entry for every
          // translation whose staged state differs from the canonical one.
          // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
          foreach (\array_keys($entity->getTranslationLanguages()) as $langcode) {
            $translation = $entity->getTranslation($langcode);
            $key = AutoSaveManager::getAutoSaveKey($translation);
            if (isset($out[$key])) {
              continue;
            }
            $this->applyRecordedDraftPath($translation, $key);
            $data_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($translation));
            if ($canonical instanceof ContentEntityInterface && $canonical->hasTranslation($langcode)) {
              $canonical_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($canonical->getTranslation($langcode)));
              if (\hash_equals($canonical_hash, $data_hash) && !$this->hasStoredFormViolations($key)) {
                continue;
              }
            }
            $metadata = self::entryMetadata($this->getStagedEntryMetadata($key));
            // Already applied to $translation above; not a list row property.
            unset($metadata[self::DRAFT_PATH_KEY]);
            $out[$key] = $metadata + [
              'entity_type' => $translation->getEntityTypeId(),
              'entity_id' => $translation->id(),
              'data' => AutoSaveManager::toStorableArray($translation),
              'langcode' => $langcode,
              'is_default_translation' => $translation->isDefaultTranslation(),
              'label' => self::labelForAutoSaveList($translation),
              'data_hash' => $data_hash,
              'client_id' => $this->getStagedClientId($key),
              'owner' => self::stagedRevisionOwner($translation),
              'updated' => $this->stagedRevisionTime($translation),
            ];
          }
        }
      }
    });
  }

  /**
   * Entity types staged only as dependents of a host item, never on their own.
   *
   * @var list<string>
   */
  private const DEPENDENT_ENTITY_TYPE_IDS = ['path_alias'];

  /**
   * Adds pending-list entries for config staged via workspace_config.
   *
   * Each workspace_config row stages one config object. Rows staging a config
   * entity are presented as that entity (loaded inside the workspace, so the
   * staged values drive type, ID, and label); rows staging simple config (or
   * config deleted in the workspace) are presented as the raw row. Runs
   * inside the staging workspace.
   *
   * @param array<string, array<string, mixed>> $out
   * @param array<int|string, int|string> $entity_ids
   */
  private function appendStagedWorkspaceConfig(array &$out, array $entity_ids): void {
    $storage = $this->entityTypeManager->getStorage('workspace_config');
    foreach ($storage->loadMultiple($entity_ids) as $row) {
      \assert($row instanceof ContentEntityInterface);
      $name = (string) $row->label();
      $mapped = $name === '' ? NULL : $this->configManager->loadConfigEntityByName($name);
      $metadata = [];
      if ($mapped instanceof ConfigEntityInterface) {
        $key = AutoSaveManager::getAutoSaveKey($mapped);
        if (isset($out[$key])) {
          // A snapshot draft of the same config entity supersedes the staged
          // workspace copy in the pending list: the snapshot is the editor's
          // current working copy.
          continue;
        }
        $data_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($mapped));
        if ($this->usesWorkspaceConfigStaging($mapped)) {
          // Dirty state is derived: a staged copy equal to the Live copy is
          // not a pending change. A copy created inside the workspace has no
          // Live counterpart and is always pending (publishing creates it).
          $live = $this->loadUnchangedBase($mapped->getEntityTypeId(), (string) $mapped->id());
          if ($live !== NULL
            && \hash_equals(AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($live)), $data_hash)
            && !$this->hasStoredFormViolations($key)) {
            continue;
          }
          $metadata = self::entryMetadata($this->getStagedEntryMetadata($key));
        }
        $entry = [
          'entity_type' => $mapped->getEntityTypeId(),
          'entity_id' => $mapped->id(),
          'data' => $mapped->toArray(),
          'langcode' => $mapped->language()->getId(),
          'is_default_translation' => TRUE,
          'label' => self::labelForAutoSaveList($mapped),
          'data_hash' => $data_hash,
          'client_id' => $this->getStagedClientId($key),
        ];
      }
      else {
        // Simple config, or config deleted in the workspace: no entity to
        // present, so the row itself carries the entry.
        $key = AutoSaveManager::getAutoSaveKey($row);
        if (isset($out[$key])) {
          continue;
        }
        $entry = [
          'entity_type' => 'workspace_config',
          'entity_id' => $row->id(),
          'data' => AutoSaveManager::toStorableArray($row),
          'label' => $name === '' ? (string) $row->id() : $name,
          'data_hash' => AutoSaveManager::generateHashFromData([
            'name' => $name,
            'changed' => $row->get('changed')->value,
          ]),
        ];
      }
      // Attribution comes from the staging metadata Canvas records with each
      // staged write; config staged by other code (a config form submitted
      // inside the workspace) only has the tracking row to go on.
      $out[$key] = $entry + $metadata + [
        'langcode' => NULL,
        'is_default_translation' => TRUE,
        'client_id' => NULL,
        'owner' => self::stagedRevisionOwner($row),
        'updated' => $this->stagedRevisionTime($row),
      ];
    }
  }

  /**
   * The editor recorded on the staged revision, falling back to the owner.
   *
   * @see ::stampAutoSaveWorkspaceRevisionMetadata()
   */
  private static function stagedRevisionOwner(ContentEntityInterface $entity): int {
    if ($entity instanceof RevisionLogInterface) {
      $revision_user = $entity->getRevisionUserId();
      if ($revision_user !== NULL) {
        return (int) $revision_user;
      }
    }
    return $entity instanceof EntityOwnerInterface ? (int) ($entity->getOwnerId() ?? 0) : 0;
  }

  /**
   * The time recorded on the staged revision, falling back to request time.
   *
   * @see ::stampAutoSaveWorkspaceRevisionMetadata()
   */
  private function stagedRevisionTime(ContentEntityInterface $entity): int {
    if ($entity instanceof RevisionLogInterface) {
      $revision_time = $entity->getRevisionCreationTime();
      if ($revision_time !== NULL) {
        return (int) $revision_time;
      }
    }
    return (int) $this->time->getRequestTime();
  }

  /**
   * Human-readable label for GET /auto-saves/pending (OpenAPI non-null).
   */
  private static function labelForAutoSaveList(EntityInterface $entity): string {
    $label = $entity->label();
    if ($label === NULL || $label === '') {
      return (string) $entity->id();
    }
    return (string) $label;
  }

  /**
   * Deletes pending workspace revisions so discard/publish can clear staging.
   */
  private function discardWorkspaceStagedContentEntity(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface || $entity->id() === NULL) {
      return;
    }
    $this->pendingBuffer->delete(AutoSaveManager::getAutoSaveKey($entity));
    if (!$this->isEntityTrackedInStagingWorkspace($entity)) {
      return;
    }
    $this->discardTrackedRevisions($entity->getEntityTypeId(), (string) $entity->id());
    $this->discardDependentStagedEntities($entity);
    $this->revisionPruner->reset($entity);
  }

  /**
   * Deletes every tracked pending revision of one entity from the workspace.
   */
  private function discardTrackedRevisions(string $type_id, string $eid): void {
    $tracker = $this->workspaceAssociation;
    $staging_workspace_id = $this->getStagingWorkspaceId();
    $this->executeInWorkspaceUnchecked($staging_workspace_id, function () use ($type_id, $eid, $tracker, $staging_workspace_id): void {
      $storage = $this->entityTypeManager->getStorage($type_id);
      if (!$storage instanceof RevisionableStorageInterface) {
        return;
      }
      $tracked = $tracker->getTrackedEntities($staging_workspace_id, $type_id, [$eid]);
      if (empty($tracked[$type_id])) {
        return;
      }
      foreach (\array_keys($tracked[$type_id]) as $revision_id) {
        $storage->deleteRevision($revision_id);
      }
    });
  }

  /**
   * Discards staged dependent entities (e.g. path aliases) of a host item.
   *
   * Staging a host entity inside the workspace also stages entities it
   * implicitly edits; when the host's staging is cleared, theirs must be too,
   * or they linger tracked (and exclusive-edit locked) with no owner.
   */
  private function discardDependentStagedEntities(ContentEntityInterface $entity): void {
    try {
      $host_path = '/' . $entity->toUrl()->getInternalPath();
    }
    catch (\Exception) {
      // Entities without a canonical route cannot have aliases.
      return;
    }
    $staging_workspace_id = $this->getStagingWorkspaceId();
    foreach (self::DEPENDENT_ENTITY_TYPE_IDS as $dependent_type_id) {
      if (!$this->entityTypeManager->hasDefinition($dependent_type_id)) {
        continue;
      }
      $tracked = $this->workspaceAssociation->getTrackedEntities($staging_workspace_id, $dependent_type_id);
      if (empty($tracked[$dependent_type_id])) {
        continue;
      }
      $dependent_ids = $this->executeInWorkspaceUnchecked($staging_workspace_id, function () use ($dependent_type_id, $tracked, $host_path): array {
        $ids = [];
        $storage = $this->entityTypeManager->getStorage($dependent_type_id);
        foreach (\array_unique($tracked[$dependent_type_id]) as $dependent_id) {
          $dependent = $storage->load($dependent_id);
          if ($dependent !== NULL && $dependent->hasField('path') && $dependent->get('path')->value === $host_path) {
            $ids[] = (string) $dependent_id;
          }
        }
        return $ids;
      });
      foreach ($dependent_ids as $dependent_id) {
        $this->discardTrackedRevisions($dependent_type_id, $dependent_id);
      }
    }
  }

  public function deleteEntity(EntityInterface $entity): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if ($entity->id() !== NULL) {
      $this->snapshotRepository->deleteFor($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity));
    }
    $this->discardWorkspaceStagedContentEntity($entity);
    $this->discardWorkspaceStagedConfig($entity);
    $this->pendingBuffer->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
    $this->cache->delete($key);
  }

  /**
   * Removes the workspace-scoped copy of a config entity from staging.
   *
   * Configuration created inside the workspace (no Live copy) is deleted;
   * configuration that exists in Live has its workspace copy reset to the
   * Live values and its tracking rows removed, so the workspace no longer
   * stages that name at all. A config object the workspace has deleted (a
   * staged delete marker, or the entity deletion that triggered this call)
   * is left alone: resetting it would resurrect it.
   *
   * @see \Drupal\workspace_config\WorkspaceConfigDatabaseStorage::delete()
   */
  private function discardWorkspaceStagedConfig(EntityInterface $entity): void {
    if (!$this->usesWorkspaceConfigStaging($entity) || $entity->id() === NULL) {
      return;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $wm = $this->workspaceManager;
    $name = $entity->getConfigDependencyName();
    $type_id = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $staging_workspace_id = $this->getStagingWorkspaceId();
    $this->executeInWorkspaceUnchecked($staging_workspace_id, function () use ($wm, $name, $type_id, $id, $staging_workspace_id): void {
      if (!$this->configStorage->exists($name)) {
        return;
      }
      $live = $wm->executeOutsideWorkspace(fn () => $this->configStorage->read($name));
      if ($live === FALSE) {
        // Only ever existed in this workspace, or Live deleted it: the
        // workspace copy goes, and so does every tracking row for the name
        // (a staged delete marker would otherwise linger as a pending change).
        $this->configStorage->delete($name);
      }
      else {
        // Writing the Live values replaces the cached workspace read; the
        // tracking rows then go so the workspace no longer stages the name.
        $this->configStorage->write($name, $live);
      }
      $tracked = $this->workspaceAssociation->getTrackedEntities($staging_workspace_id, 'workspace_config');
      $storage = $this->entityTypeManager->getStorage('workspace_config');
      $rows = \array_filter(
        $storage->loadMultiple(\array_unique($tracked['workspace_config'] ?? [])),
        static fn (EntityInterface $row): bool => (string) $row->label() === $name,
      );
      if ($rows !== []) {
        $wm->executeOutsideWorkspace(static fn () => $storage->delete($rows));
      }
      $this->configFactory->reset($name);
      $this->entityTypeManager->getStorage($type_id)->resetCache([$id]);
    });
    $entity_type = $this->entityTypeManager->getDefinition($type_id);
    $this->cacheTagsInvalidator->invalidateTags(['config:' . $name, ...$entity_type->getListCacheTags()]);
  }

  /**
   * Persists any pending (pre-terminate) auto-save buffer for an entity.
   *
   * Call before returning autoSave hashes to the client so the reported hash
   * matches the primary store (buffer tokens are only valid until flush).
   * Covers content entities and workspace-staged config alike; a no-op for
   * anything else.
   */
  public function flushDeferredContentEntity(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface && !$this->usesWorkspaceConfigStaging($entity)) {
      return;
    }
    $this->deferredFlusher->flushNow($entity);
  }

  /**
   * Clears every Canvas staging store for one workspace after its publish.
   *
   * Core clears the workspace association itself; this removes Canvas's
   * snapshot rows, buffer rows and tombstones, key-value staging rows, form
   * violations, pruner bookkeeping, and caches. Runs for every publish
   * surface via the post-publish event.
   *
   * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber::onPostPublish()
   */

  /**
   * Whether any snapshot rows are staged in a workspace.
   *
   * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber::onPrePublish()
   */
  public function workspaceHasSnapshotRows(string $workspace_id): bool {
    return $this->snapshotRepository->loadAll($workspace_id) !== [];
  }

  public function clearWorkspaceStores(string $workspace_id): void {
    $this->snapshotRepository->deleteAll($workspace_id);
    $prefix = $workspace_id . ':';
    foreach (\array_keys($this->pendingBuffer->getAll()) as $key) {
      if (\str_starts_with((string) $key, $prefix)) {
        $this->pendingBuffer->delete((string) $key);
      }
    }
    $collections = [
      AutoSaveManager::FORM_VIOLATIONS_STORE,
      AutoSaveManager::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE,
      AutoSaveRevisionPruner::STORE,
    ];
    foreach ($collections as $collection) {
      $store = $this->keyValueFactory->get($collection);
      foreach (\array_keys($store->getAll()) as $key) {
        if (\str_starts_with((string) $key, $prefix)) {
          $store->delete((string) $key);
        }
      }
    }
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
    $this->cache->deleteAll();
  }

  public function deleteAll(): void {
    $this->snapshotRepository->deleteAll();
    // Workspace-tracked staged revisions are staging too: discard them, or
    // "discard all" leaves pending changes that reappear on the next listing.
    $tracked = $this->workspaceAssociation->getTrackedEntities($this->getStagingWorkspaceId());
    // Config staged as workspace-scoped configuration is discarded through
    // its own path, which also resets the workspace's cached config reads.
    foreach (\array_unique($tracked['workspace_config'] ?? []) as $row_id) {
      $row = $this->entityTypeManager->getStorage('workspace_config')->load($row_id);
      $name = $row === NULL ? '' : (string) $row->label();
      $mapped = $name === '' ? NULL : $this->executeInStagingWorkspaceUnchecked(fn () => $this->configManager->loadConfigEntityByName($name));
      if ($mapped instanceof ConfigEntityInterface) {
        $this->discardWorkspaceStagedConfig($mapped);
      }
    }
    $tracked = $this->workspaceAssociation->getTrackedEntities($this->getStagingWorkspaceId());
    foreach ($tracked as $entity_type_id => $revision_map) {
      foreach (\array_unique($revision_map) as $entity_id) {
        $this->discardTrackedRevisions($entity_type_id, (string) $entity_id);
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
        if ($entity !== NULL) {
          $this->revisionPruner->reset($entity);
        }
      }
    }
    $this->pendingBuffer->deleteAll();
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
  }

  /**
   * Opaque token for concurrent-edit checks.
   *
   * Always derived from the saved base: the starting point identifies the base
   * an auto-save draft started from, so it must stay stable across successive
   * auto-saves (snapshot rows and pending-buffer tokens change on every save)
   * and only change when the entity itself is saved.
   *
   * @see ::loadUnchangedBase()
   */
  public function getAutoSaveStartingPoint(EntityInterface $entity): string|int|null {
    \assert($entity->id() !== NULL);
    $saved_entity = $this->loadUnchangedBase($entity->getEntityTypeId(), (string) $entity->id());
    if ($saved_entity === NULL && $this->usesWorkspaceConfigStaging($entity)) {
      // Created inside the workspace: no Live copy exists until publish, so
      // the recorded base identifies the starting point. It changes when the
      // workspace publishes (a Live copy then exists) and never before.
      \assert($entity instanceof ComponentTreeConfigEntityBase);
      return $this->ensureConfigBaseRecorded($entity);
    }
    \assert($saved_entity instanceof EntityInterface);
    $auto_save_start_revision = $saved_entity instanceof RevisionableInterface
      ? $saved_entity->getRevisionId()
      : \hash('xxh64', \json_encode($saved_entity->toArray(), JSON_THROW_ON_ERROR));
    if ($saved_entity instanceof EntityChangedInterface) {
      $auto_save_start_revision .= '-' . $saved_entity->getChangedTime();
    }
    return $auto_save_start_revision;
  }

  /**
   * Loads the saved copy an auto-save draft is based on.
   *
   * Content entities load outside any workspace: their drafts are staged as
   * workspace revisions, so with the auto-save workspace active a plain
   * loadUnchanged() would return the draft itself rather than the Live base
   * that hashes and starting points must be computed against.
   *
   * Config entities staged as workspace-scoped configuration load outside
   * any workspace as well: inside it, the unchanged copy is the draft. For
   * configuration created inside the workspace this returns NULL, since no
   * Live copy exists until publish; ::getBaseHash() then falls back to the
   * recorded base.
   *
   * Other config entities load inside the active workspace: their drafts are
   * snapshot rows or key-value entries, never config writes, so the
   * in-workspace unchanged copy is the saved base. Loading outside the
   * workspace would miss config that the Workspace Config module staged in
   * the workspace and has not published yet, which only exists in that
   * workspace's partition.
   *
   * @see ::usesWorkspaceConfigStaging()
   * @see ::persistConfigSnapshot()
   * @see \Drupal\canvas\Controller\ApiConfigControllers
   */
  public function loadUnchangedBase(string $entityTypeId, string|int $id): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $is_config = $this->entityTypeManager->getDefinition($entityTypeId) instanceof ConfigEntityTypeInterface;
    if ($is_config && !$this->usesWorkspaceConfigStagingForType($entityTypeId)) {
      return $storage->loadUnchanged($id);
    }
    $wm = $this->workspaceManager;
    return $wm->executeOutsideWorkspace(static fn () => $storage->loadUnchanged($id));
  }

}
