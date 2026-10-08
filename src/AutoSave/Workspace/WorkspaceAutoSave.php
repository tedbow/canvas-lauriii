<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\AutoSavePublishAwareInterface;
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
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\workspace_config\WorkspaceConfigInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists Canvas auto-save state in the active workspace.
 *
 * A staged write is an entity save inside the staging workspace: content
 * becomes a pending revision tracked by core Workspaces, component tree
 * config entities (content templates, patterns, page variants) become
 * workspace-scoped configuration staged by the Workspace Config module. Only
 * the newest staged revision of an entity is retained.
 *
 * Drafts that cannot be such a save are retained in the fallback store: config
 * entity types whose save has side effects (code components, asset libraries,
 * brand kits, staged config updates, staged configuration translations), and
 * any draft the storage layer rejected. A fallback row is read before the
 * primary store and removed by the next successful primary persist, so a
 * target's draft lives in exactly one place. The fallback store also keeps
 * what the primary stores cannot record about a draft (client instance, the
 * verbatim draft `path` value, attribution of staged configuration).
 *
 * The staging workspace is the active workspace when one is negotiated, or
 * the Main workspace (`canvas_default`) as the fallback for sessions that
 * never selected one. Every store partitions per workspace.
 *
 * The workspace services are nullable so the container compiles on a site
 * updating from 1.x, before canvas_update_11201() has enabled the Workspaces
 * modules; every use goes through an accessor that throws when they are NULL.
 */
final class WorkspaceAutoSave {

  /**
   * Metadata key holding a content draft's verbatim `path` field value.
   */
  public const string DRAFT_PATH_KEY = 'draft_path';

  /**
   * Starting point reported for configuration created inside a workspace.
   *
   * No Live copy exists until publish, so nothing can change under the draft
   * before then; the constant changes to a Live-derived value at publish.
   *
   * @see ::getAutoSaveStartingPoint()
   */
  public const string UNPUBLISHED_STARTING_POINT = 'unpublished';

  /**
   * Entity types staged only as dependents of a host item, never on their own.
   *
   * @var list<string>
   */
  private const DEPENDENT_ENTITY_TYPE_IDS = ['path_alias'];

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

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigManagerInterface $configManager,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'config.storage')]
    private readonly StorageInterface $configStorage,
    // NULL on a site updating from 1.x until canvas_update_11201() has enabled
    // the Workspaces modules; the container must compile before that update.
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?WorkspaceManagerInterface $workspaceManager,
    #[Autowire(service: 'workspaces.tracker')]
    private readonly ?WorkspaceTrackerInterface $workspaceAssociation,
    #[Autowire(service: WorkspaceConfigInformationInterface::class)]
    private readonly ?WorkspaceConfigInformationInterface $workspaceConfigInformation,
    private readonly AutoSaveFallbackStore $store,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    // MUST be a non-serializing backend; a serializing one (e.g. cache.static)
    // would run cached entities' ::__sleep(), forcing computed fields to
    // compute mid-cache-write and potentially recurse.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::__construct()
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  private function workspaceManager(): WorkspaceManagerInterface {
    return $this->workspaceManager ?? throw new \LogicException('The Workspaces module is not installed.');
  }

  private function workspaceAssociation(): WorkspaceTrackerInterface {
    return $this->workspaceAssociation ?? throw new \LogicException('The Workspaces module is not installed.');
  }

  private function workspaceConfigInformation(): WorkspaceConfigInformationInterface {
    return $this->workspaceConfigInformation ?? throw new \LogicException('The Workspace Config module is not installed.');
  }

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
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
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
    return AutoSaveWorkspace::stagingId($this->workspaceManager());
  }

  /**
   * Whether publish-time staging is currently running.
   *
   * TRUE exactly while fallback drafts are being staged into the workspace
   * being published. Those saves are not editorial writes: config save
   * listeners must not reconcile the draft against them, and staged-write
   * listeners (e.g. a review-state demotion) must ignore them.
   *
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
   * Whether core negotiated an active workspace for this request.
   */
  public function hasActiveWorkspace(): bool {
    return $this->workspaceManager()->hasActiveWorkspace();
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
   * Other Canvas config entities keep fallback staging: code components and
   * asset libraries compile and write asset files on save, and staged config
   * updates apply to a different target on save, neither of which a draft
   * should trigger.
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
    // written inside a workspace at all; its drafts stay in the fallback store.
    // @see \Drupal\canvas\Hook\WorkspaceAutoSaveHooks::workspaceConfigSafeListAlter()
    return $this->workspaceConfigInformation()->isConfigEntityTypeIdWorkspaceSafe($entity_type_id);
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
   * Staging bookkeeping (staged config reads and discards, the pending list
   * built for a publish from cron, the update path's draft migration) runs in
   * whichever request causes it. Core only lets the current user switch into
   * a workspace they may view, but bookkeeping is not a user action, so view
   * access is granted for the switch's duration through
   * hook_workspace_access(). Access results are statically cached per
   * account: a cached denial is dropped before the switch and the grant
   * afterwards.
   *
   * @see \Drupal\workspaces\WorkspaceManager::doSwitchWorkspace()
   * @see \Drupal\canvas\Hook\WorkspaceAutoSaveRevisionHooks::workspaceAccess()
   */
  public function executeInWorkspaceUnchecked(string $workspace_id, callable $callback): mixed {
    $wm = $this->workspaceManager();
    if ($wm->getActiveWorkspace()?->id() === $workspace_id) {
      return $callback();
    }
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
   * Runs a callback inside the staging workspace.
   *
   * A passthrough when the staging workspace is already active: every
   * workspace switch dispatches WorkspaceSwitchEvent, which resets config
   * and field definition caches, so needless round trips are avoided.
   *
   * @see ::executeInWorkspaceUnchecked()
   */
  public function executeInStagingWorkspace(callable $callback): mixed {
    return $this->executeInWorkspaceUnchecked($this->getStagingWorkspaceId(), $callback);
  }

  /**
   * Whether the entity holds a draft in any store of the staging workspace.
   */
  public function hasWorkspaceStaging(EntityInterface $entity): bool {
    if ($entity->id() === NULL) {
      return FALSE;
    }
    if ($this->store->getDraft($this->getStagingWorkspaceId(), AutoSaveFallbackStore::targetKey($entity)) !== NULL) {
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
    $tracking_ids = $this->workspaceAssociation()->getEntityTrackingWorkspaceIds($entity, TRUE);
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
    $info = ['workspaceId' => $owning_id, 'ownerId' => 0, 'updated' => (int) $this->time->getRequestTime()];
    $staged = $this->loadTrackedRevision($entity, $owning_id);
    if ($staged !== NULL) {
      $info['ownerId'] = self::stagedRevisionOwner($staged);
      $info['updated'] = $this->stagedRevisionTime($staged);
    }
    return $info;
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
   * The newest revision of an entity tracked in a workspace, if any.
   *
   * Loaded by revision id, so no workspace switch is needed: the pending
   * revision is a plain revision row.
   */
  private function loadTrackedRevision(ContentEntityInterface $entity, ?string $workspace_id = NULL): ?ContentEntityInterface {
    $id = $entity->id();
    if ($id === NULL) {
      return NULL;
    }
    $type_id = $entity->getEntityTypeId();
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id ?? $this->getStagingWorkspaceId(), $type_id, [(string) $id]);
    $revision_ids = \array_keys($tracked[$type_id] ?? []);
    if ($revision_ids === []) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage($type_id);
    if (!$storage instanceof RevisionableStorageInterface) {
      return NULL;
    }
    $revision = $storage->loadRevision(\max($revision_ids));
    return $revision instanceof ContentEntityInterface ? $revision : NULL;
  }

  /**
   * Entity to use when building the layout API response (tree + preview HTML).
   */
  public function getEntityForLayoutEditing(ContentEntityInterface $entity): ContentEntityInterface {
    $auto_save = $this->loadAutoSaveEntity($entity, bypassCache: TRUE);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof ContentEntityInterface);
      return $auto_save->entity;
    }
    // A tracked revision equal to Live (e.g. a sibling translation's draft
    // touched the shared revision) is still the revision the editor works on.
    $staged = $this->loadTrackedRevision($entity);
    if ($staged === NULL) {
      return $entity;
    }
    $langcode = $entity->language()->getId();
    return $staged->hasTranslation($langcode) ? $staged->getTranslation($langcode) : $entity;
  }

  private function loadWorkspaceStagedContentAutoSave(ContentEntityInterface $entity): AutoSaveEntity {
    $staged = $this->loadTrackedRevision($entity);
    if ($staged === NULL) {
      return AutoSaveEntity::empty();
    }
    $id = $entity->id();
    \assert($id !== NULL);
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $original = $this->loadUnchangedBase($entity->getEntityTypeId(), (string) $id);
    if (!$original instanceof ContentEntityInterface) {
      return AutoSaveEntity::empty();
    }
    // Auto-save entries are per translation: compare and return the
    // translation matching the requested entity's language. A translation
    // the staged revision does not carry (e.g. after the draft's langcode
    // changed) has no draft.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
    $langcode = $entity->language()->getId();
    if (!$staged->hasTranslation($langcode)) {
      return AutoSaveEntity::empty();
    }
    $staged = $staged->getTranslation($langcode);
    if ($original->hasTranslation($langcode)) {
      $original = $original->getTranslation($langcode);
    }
    $this->applyRecordedDraftPath($staged, $entity);
    $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($staged));
    $unchanged_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($original));
    if (\hash_equals($unchanged_hash, $hash) && !$this->hasStoredFormViolations($key)) {
      return AutoSaveEntity::empty();
    }
    $auto_save_entity = new AutoSaveEntity($staged, $hash, $this->getStagedClientId($entity), $this->stagedRevisionTime($staged));
    $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * Stages a 1.x key-value auto-save row for an entity.
   *
   * @param array<string, mixed> $legacy
   *   The 1.x row.
   *
   * @see \Drupal\canvas\AutoSave\Workspace\LegacyAutoSaveMigrator
   */
  public function importLegacyArray(EntityInterface $entity, array $legacy): void {
    \assert(isset($legacy['data']) && \is_array($legacy['data']));
    $staged = $this->reconstructDraft($legacy, $entity);
    $this->persistStagedEntity($staged, $legacy['client_id'] ?? NULL, $legacy);
  }

  /**
   * Rebuilds a draft entity from a stored row.
   *
   * @param array<string, mixed> $row
   *   A row in the 1.x auto-save shape.
   * @param \Drupal\Core\Entity\EntityInterface|null $target
   *   The stored entity the draft targets, when known. Its identity is
   *   grafted onto the reconstruction: ::create() marks the reconstruction as
   *   new even when the row carries the id, and pre-1.0 rows may lack it.
   */
  private function reconstructDraft(array $row, ?EntityInterface $target = NULL): EntityInterface {
    $storage = $this->entityTypeManager->getStorage($row['entity_type']);
    $staged = $storage->create($row['data']);
    \assert($staged instanceof EntityInterface);
    if ($staged instanceof ContentEntityInterface && $target instanceof ContentEntityInterface) {
      $entity_type = $storage->getEntityType();
      foreach (['id', 'uuid', 'revision'] as $key_name) {
        $key = $entity_type->getKey($key_name);
        if (\is_string($key) && $key !== '' && $staged->get($key)->isEmpty() && !$target->get($key)->isEmpty()) {
          $staged->set($key, $target->get($key)->value);
        }
      }
      if ($entity_type->isRevisionable()) {
        $staged->updateLoadedRevisionId();
        // ::create() pre-marks the entity as a new revision, which makes the
        // later setNewRevision(TRUE) in workspaces' entity_presave a no-op
        // that skips clearing the revision key; the save would then insert a
        // duplicate of the grafted revision id. Reset the flag so that
        // transition runs and a fresh revision id is assigned.
        $staged->setNewRevision(FALSE);
      }
    }
    $staged->enforceIsNew(FALSE);
    return $staged;
  }

  /**
   * Persists a draft into the staging workspace.
   *
   * @param array<string, mixed> $entry
   *   The auto-save entry as built by AutoSaveManager::saveEntity(): the 1.x
   *   row shape (data, langcode, is_default_translation, label, data_hash,
   *   client_id, owner, updated). It is the fallback row when the primary
   *   store rejects the draft, and the source of the metadata recorded
   *   alongside a primary persist.
   */
  public function persistStagedEntity(EntityInterface $entity, ?string $clientId, array $entry): void {
    // An entity's pending work lives in exactly one workspace at a time
    // (core's tracking); a staged write for an entity owned by another
    // workspace is rejected with the owning workspace named, never silently
    // retargeted.
    $this->assertNotLockedInAnotherWorkspace($entity);

    // A negotiated workspace whose entity has been deleted mid-session must
    // fail the write: falling through to another store (or Live) would
    // silently misplace the draft.
    $workspace_id = $this->getStagingWorkspaceId();
    if ($this->entityTypeManager->getStorage('workspace')->load($workspace_id) === NULL) {
      throw new \RuntimeException(\sprintf('The workspace "%s" no longer exists; the auto-save was rejected.', $workspace_id));
    }

    $entry['client_id'] = $clientId;
    // Scope the workspace context to the persist operation: permanently
    // activating the workspace would leak into subsequent entity saves in the
    // same process (CLI, tests, long-running workers).
    $this->executeInWorkspaceUnchecked($workspace_id, function () use ($entity, $entry, $workspace_id): void {
      if ($this->usesWorkspaceConfigStaging($entity)) {
        \assert($entity instanceof ComponentTreeConfigEntityBase);
        $this->persistConfigEntity($entity, $entry, $workspace_id);
      }
      elseif ($entity instanceof ConfigEntityInterface) {
        $this->retainFallbackDraft($entity, $entry, $workspace_id);
      }
      elseif ($entity instanceof ContentEntityInterface) {
        $this->persistContentEntity($entity, $entry, $workspace_id);
      }
      else {
        throw new \InvalidArgumentException('Unsupported entity for workspace auto-save.');
      }
    });
    $this->cache->delete(AutoSaveManager::getAutoSaveKey($entity));
  }

  /**
   * Stages a component tree config entity draft as workspace-scoped config.
   *
   * @param array<string, mixed> $entry
   */
  private function persistConfigEntity(ComponentTreeConfigEntityBase $entity, array $entry, string $workspace_id): void {
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
    try {
      $to_save->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Canvas auto-save for @type @id could not be stored as workspace-scoped configuration (@message); retained in the fallback store instead.', [
        '@type' => $type_id,
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      $this->retainFallbackDraft($entity, $entry, $workspace_id, $e);
      return;
    }
    $this->recordPrimaryPersist($entity, $entry, $workspace_id);
    $storage->resetCache([$id]);
  }

  /**
   * Stages a content entity draft as a tracked pending revision.
   *
   * A rejected first draft still claims the entity for the workspace with a
   * `changed`-only pending revision of the unchanged entity, so core's
   * EntityWorkspaceConflict lock and the workflow presave hook apply from the
   * first auto-save; the fallback row keeps shadowing it until a draft is
   * accepted, which replaces it like any previously tracked revision.
   *
   * @param array<string, mixed> $entry
   */
  private function persistContentEntity(ContentEntityInterface $entity, array $entry, string $workspace_id): void {
    // Never mutate the caller's entity object: saving inside the workspace
    // marks the object as a non-default pending revision, which would leak
    // into the caller's later saves of the same object (e.g. an
    // editor-initiated Live save silently becoming a pending revision).
    $to_save = clone $entity;
    $previous_revision_ids = $this->trackedRevisionIds($entity, $workspace_id);
    try {
      $to_save->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Canvas auto-save for @type @id could not be stored as a workspace revision (@message); retained in the fallback store instead.', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => (string) $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->retainFallbackDraft($entity, $entry, $workspace_id, $e);
      if ($previous_revision_ids === []) {
        $this->claimEntityForWorkspace($entity);
      }
      return;
    }
    $this->pruneToLatestRevision($to_save, $previous_revision_ids);
    $this->recordPrimaryPersist($entity, $entry, $workspace_id);
  }

  /**
   * Tracks an entity in the active workspace with a placeholder revision.
   *
   * Saves a copy of the unchanged entity, with only `changed` bumped, while
   * the workspace is active; core's presave turns it into a tracked pending
   * revision. Failure is logged only: the fallback row holds the draft
   * either way.
   */
  private function claimEntityForWorkspace(ContentEntityInterface $entity): void {
    $id = $entity->id();
    if ($id === NULL) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $unchanged = $storage->loadUnchanged($id);
    if (!$unchanged instanceof ContentEntityInterface) {
      return;
    }
    $placeholder = clone $unchanged;
    if ($placeholder instanceof EntityChangedInterface) {
      $placeholder->setChangedTime($this->time->getRequestTime());
    }
    try {
      $placeholder->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Canvas could not track @type @id in the workspace for its rejected draft (@message).', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => (string) $entity->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * The revision ids of an entity tracked in a workspace.
   *
   * Core tracks one revision per entity per workspace: a new staged revision
   * replaces the tracked one, which otherwise lingers untracked.
   *
   * @return list<int>
   */
  private function trackedRevisionIds(ContentEntityInterface $entity, string $workspace_id): array {
    if ($entity->id() === NULL) {
      return [];
    }
    $type_id = $entity->getEntityTypeId();
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id, $type_id, [(string) $entity->id()]);
    return \array_map(\intval(...), \array_keys($tracked[$type_id] ?? []));
  }

  /**
   * Keeps only the newest staged revision of an entity.
   *
   * @param list<int> $previous_revision_ids
   *   The revisions tracked before the newest one was saved.
   */
  private function pruneToLatestRevision(ContentEntityInterface $entity, array $previous_revision_ids): void {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof RevisionableStorageInterface) {
      return;
    }
    $current = (int) $entity->getRevisionId();
    foreach ($previous_revision_ids as $revision_id) {
      if ($revision_id === $current) {
        continue;
      }
      try {
        $storage->deleteRevision($revision_id);
      }
      catch (EntityStorageException) {
        // The default revision (an entity created inside the workspace) stays.
      }
    }
  }

  /**
   * Bookkeeping after a draft landed in a primary store.
   *
   * The fallback row of the same target (from an earlier rejected persist)
   * would otherwise shadow the primary store forever; the metadata the
   * primary store cannot record is kept alongside it.
   *
   * @param array<string, mixed> $entry
   */
  private function recordPrimaryPersist(EntityInterface $entity, array $entry, string $workspace_id): void {
    $key = AutoSaveFallbackStore::targetKey($entity);
    $this->store->drafts($workspace_id)->delete($key);
    $this->store->mergeMetadata($workspace_id, $key, self::entryMetadata($entry));
  }

  /**
   * Retains a draft as a fallback row.
   *
   * A rejection is recorded on the row; the publish reports it as a per-item
   * violation.
   *
   * @param array<string, mixed> $entry
   * @param \Throwable|null $rejection
   *   The storage layer's exception when the primary store refused the
   *   draft; NULL for entity types the fallback store holds by design.
   *
   * @see ::getRejectedDraftError()
   */
  private function retainFallbackDraft(EntityInterface $entity, array $entry, string $workspace_id, ?\Throwable $rejection = NULL): void {
    $row = \array_intersect_key($entry, \array_flip([
      'entity_type',
      'entity_id',
      'data',
      'langcode',
      'is_default_translation',
      'label',
      'data_hash',
      'client_id',
      'owner',
      'updated',
    ]));
    $row += [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => AutoSaveManager::toStorableArray($entity),
      'langcode' => $entity->language()->getId(),
      'is_default_translation' => !($entity instanceof TranslatableInterface) || $entity->isDefaultTranslation(),
      'label' => (string) $entity->label(),
      'data_hash' => AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($entity)),
      'client_id' => NULL,
      'owner' => (int) $this->currentUser->id(),
      'updated' => $this->time->getRequestTime(),
    ];
    if ($rejection !== NULL) {
      $row[AutoSaveFallbackStore::STORAGE_ERROR_KEY] = $rejection->getMessage();
    }
    $this->store->drafts($workspace_id)->set(AutoSaveFallbackStore::targetKey($entity), $row);
  }

  /**
   * The storage error recorded when the primary store refused a draft.
   *
   * @return string|null
   *   The storage layer's message, or NULL when the draft is in a primary
   *   store or is held by the fallback store by design.
   *
   * @see ::retainFallbackDraft()
   */
  public function getRejectedDraftError(EntityInterface $entity, ?string $workspace_id = NULL): ?string {
    if ($entity->id() === NULL) {
      return NULL;
    }
    $row = $this->store->getDraft($workspace_id ?? $this->getStagingWorkspaceId(), AutoSaveFallbackStore::targetKey($entity));
    $error = $row[AutoSaveFallbackStore::STORAGE_ERROR_KEY] ?? NULL;
    return \is_string($error) ? $error : NULL;
  }

  /**
   * The metadata recorded alongside a primary-store draft.
   *
   * @param array<string, mixed> $entry
   *
   * @return array<string, mixed>
   */
  private static function entryMetadata(array $entry): array {
    $metadata = \array_intersect_key($entry, \array_flip(['client_id', 'owner', 'updated']));
    // Record the draft's `path` value verbatim: on a staged revision the
    // computed path field resolves through alias storage, which cannot
    // represent a draft that cleared (or never set) its alias.
    // @see ::applyRecordedDraftPath()
    if (isset($entry['data']) && \is_array($entry['data']) && \array_key_exists('path', $entry['data'])) {
      $metadata[self::DRAFT_PATH_KEY] = $entry['data']['path'] ?? [];
    }
    return $metadata;
  }

  /**
   * Overrides a staged entity's computed path with the recorded draft value.
   *
   * The alias lookup powering the computed path field is not revision-aware:
   * it resolves the Live (or last staged) alias, so a draft that cleared its
   * alias would still present one. The verbatim value recorded at staging
   * time is authoritative.
   */
  private function applyRecordedDraftPath(ContentEntityInterface $staged, EntityInterface $target): void {
    if (!$staged->hasField('path')) {
      return;
    }
    $metadata = $this->getStagedMetadata($target);
    if (\array_key_exists(self::DRAFT_PATH_KEY, $metadata)) {
      $draft_path = $metadata[self::DRAFT_PATH_KEY];
      if (!$draft_path) {
        // A cleared alias is recorded as an empty value; explicit NULL resets
        // the computed path field instead of assigning the empty value.
        $draft_path = NULL;
      }
      $staged->set('path', $draft_path);
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
   * The client instance id that produced the staged draft, if known.
   */
  private function getStagedClientId(EntityInterface $entity): ?string {
    $client_id = $this->getStagedMetadata($entity)['client_id'] ?? NULL;
    return \is_string($client_id) ? $client_id : NULL;
  }

  /**
   * @return array<string, mixed>
   */
  private function getStagedMetadata(EntityInterface $entity): array {
    return $this->store->getMetadata($this->getStagingWorkspaceId(), AutoSaveFallbackStore::targetKey($entity));
  }

  /**
   * Re-keys staging bookkeeping after a content draft's langcode changed.
   *
   * A content draft's auto-save key carries its langcode. The staged revision
   * is re-staged under the new language; the fallback row and metadata
   * recorded under the old langcode are re-keyed.
   *
   * @return bool
   *   TRUE when bookkeeping was re-keyed, FALSE when nothing was recorded.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::migrateLangcode()
   */
  public function migrateStagingKey(ContentEntityInterface $entity, string $old_langcode): bool {
    $type_id = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $new_langcode = $entity->language()->getId();
    $workspace_id = $this->getStagingWorkspaceId();
    $old_key = $type_id . ':' . $id . ':' . $old_langcode;
    $new_key = AutoSaveFallbackStore::targetKey($entity);
    $langcode_field = $entity->getEntityType()->getKey('langcode');

    // Saving the entity with a new langcode deletes the path alias of the old
    // language (path_entity_translation_delete()); the alias text itself
    // survives in the draft. Drop the alias id and move the item to the new
    // language, so the draft creates a fresh alias instead of updating a
    // deleted one.
    // @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
    $rekey_path = static function (mixed $items) use ($new_langcode): mixed {
      if (!\is_array($items)) {
        return $items;
      }
      foreach ($items as &$path_item) {
        if (\is_array($path_item)) {
          unset($path_item['pid']);
          if (\array_key_exists('langcode', $path_item)) {
            $path_item['langcode'] = $new_langcode;
          }
        }
      }
      return $items;
    };

    $moved = FALSE;
    $draft = $this->store->getDraft($workspace_id, $old_key);
    if ($draft !== NULL) {
      $draft['langcode'] = $new_langcode;
      if (\is_string($langcode_field) && isset($draft['data'][$langcode_field])) {
        $draft['data'][$langcode_field] = [['value' => $new_langcode]];
      }
      if (isset($draft['data']['path'])) {
        $draft['data']['path'] = $rekey_path($draft['data']['path']);
      }
      $this->store->drafts($workspace_id)->set($new_key, $draft);
      $this->store->drafts($workspace_id)->delete($old_key);
      $moved = TRUE;
    }
    $metadata = $this->store->getMetadata($workspace_id, $old_key);
    if ($metadata !== []) {
      if (\array_key_exists(self::DRAFT_PATH_KEY, $metadata)) {
        $metadata[self::DRAFT_PATH_KEY] = $rekey_path($metadata[self::DRAFT_PATH_KEY]);
      }
      $this->store->mergeMetadata($workspace_id, $new_key, $metadata);
      $this->store->metadata($workspace_id)->delete($old_key);
      $moved = TRUE;
    }
    // The staged revision still carries the old langcode: re-stage it in the
    // new language so the draft follows the entity (and no draft remains under
    // the old langcode). Its alias follows through the recorded draft path.
    $staged = $this->loadTrackedRevision($entity);
    if ($staged !== NULL && $staged->hasTranslation($old_langcode) && \is_string($langcode_field)) {
      $this->executeInWorkspaceUnchecked($workspace_id, static function () use ($staged, $old_langcode, $langcode_field, $new_langcode): void {
        $staged->getTranslation($old_langcode)->set($langcode_field, $new_langcode)->save();
      });
      $moved = TRUE;
    }
    if ($moved) {
      $this->cache->delete($workspace_id . ':' . $old_key);
      $this->cache->delete($workspace_id . ':' . $new_key);
    }
    return $moved;
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

    // A fallback row is the editor's current working copy and shadows the
    // primary store.
    if ($entity->id() !== NULL) {
      $row = $this->store->getDraft($this->getStagingWorkspaceId(), AutoSaveFallbackStore::targetKey($entity));
      if ($row !== NULL) {
        $staged = $this->reconstructDraft($row, $entity);
        $auto_save_entity = new AutoSaveEntity($staged, $row['data_hash'] ?? NULL, $row['client_id'] ?? NULL, isset($row['updated']) ? (int) $row['updated'] : NULL);
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
   * whose normalized data equals the Live configuration is not a pending
   * change, unless entity form violations are recorded for it. Configuration
   * created inside the workspace has no Live copy and is always pending.
   */
  private function loadWorkspaceStagedConfigAutoSave(ComponentTreeConfigEntityBase $entity): AutoSaveEntity {
    $id = $entity->id();
    if ($id === NULL) {
      return AutoSaveEntity::empty();
    }
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $staged = $this->executeInStagingWorkspace(static fn () => $storage->loadUnchanged($id));
    if (!$staged instanceof ComponentTreeConfigEntityBase) {
      return AutoSaveEntity::empty();
    }
    $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($staged));
    $base_hash = $this->getBaseHash($entity);
    if ($base_hash !== NULL && \hash_equals($base_hash, $hash) && !$this->hasStoredFormViolations($key)) {
      return AutoSaveEntity::empty();
    }
    // Hand out a copy: callers may adjust the draft, which must not leak into
    // the entity static cache.
    $draft = clone $staged;
    $draft->enforceIsNew(FALSE);
    $metadata = $this->getStagedMetadata($entity);
    $updated = isset($metadata['updated']) && \is_numeric($metadata['updated']) ? (int) $metadata['updated'] : NULL;
    $auto_save_entity = new AutoSaveEntity($draft, $hash, $this->getStagedClientId($entity), $updated);
    $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * The normalized hash of the saved copy an auto-save draft is based on.
   *
   * The copy ::loadUnchangedBase() returns; never the staged copy itself:
   * that is the draft, and comparing a draft against itself would make every
   * re-save look like a reset to the original values.
   *
   * @return string|null
   *   The base hash, or NULL when the entity has no saved base (configuration
   *   created inside the workspace, which has no Live copy until publish).
   */
  public function getBaseHash(EntityInterface $entity): ?string {
    $id = $entity->id();
    if ($id === NULL) {
      return NULL;
    }
    $base = $this->loadUnchangedBase($entity->getEntityTypeId(), (string) $id);
    return $base === NULL ? NULL : AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($base));
  }

  /**
   * Reacts to a config save made inside the staging workspace by other code.
   *
   * With workspace-scoped staging the saved copy is the draft, so there is no
   * separate draft to reconcile; only the memoized draft is stale.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   */
  public function onWorkspaceStagedConfigSaved(ComponentTreeConfigEntityBase $entity): void {
    if ($entity->id() !== NULL) {
      $this->cache->delete(AutoSaveManager::getAutoSaveKey($entity));
    }
  }

  /**
   * The pending list of a workspace, keyed by auto-save key.
   *
   * @param string|null $workspace_id
   *   The workspace to list; the staging workspace when NULL.
   *
   * @return array<string, array<string, mixed>>
   */
  public function getAllList(?string $workspace_id = NULL): array {
    $workspace_id ??= $this->getStagingWorkspaceId();
    if ($this->entityTypeManager->getStorage('workspace')->load($workspace_id) === NULL) {
      return [];
    }
    // Config staged as workspace-scoped configuration only resolves inside
    // its workspace, so the list is built there.
    return $this->executeInWorkspaceUnchecked($workspace_id, function () use ($workspace_id): array {
      /** @var array<string, array<string, mixed>> $out */
      $out = [];
      foreach ($this->store->getAllDrafts($workspace_id) as $target_key => $row) {
        // Some labels are derived (e.g. PageRegion), so an unsaved entity
        // object is needed to compute the label the way the entity type
        // defines it.
        $staged = $this->entityTypeManager->getStorage($row['entity_type'])->create($row['data']);
        $out[$workspace_id . ':' . $target_key] = [
          'entity_type' => $row['entity_type'],
          'entity_id' => $row['entity_id'] ?? $staged->id(),
          'data' => $row['data'],
          'langcode' => $staged->language()->getId(),
          'is_default_translation' => !($staged instanceof TranslatableInterface) || $staged->isDefaultTranslation(),
          'label' => self::labelForAutoSaveList($staged),
          'data_hash' => $row['data_hash'] ?? AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($staged)),
          'client_id' => $row['client_id'] ?? NULL,
          'owner' => (int) ($row['owner'] ?? 0),
          'updated' => (int) ($row['updated'] ?? $this->time->getRequestTime()),
        ];
      }
      $this->appendWorkspaceTrackedEntities($out, $workspace_id);
      \ksort($out);
      return $out;
    });
  }

  /**
   * Every fallback draft of one entity type, in every workspace.
   *
   * Reads the fallback store only, so it never activates a workspace: the
   * callers react to events triggered by users without workspace view
   * access, e.g. the config-delete hook firing for arbitrary config deletions.
   *
   * @return list<array{workspace: string, key: string, entity: \Drupal\Core\Entity\EntityInterface}>
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigDelete()
   */
  public function findFallbackDraftsOfType(string $entity_type_id): array {
    $found = [];
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    foreach ($this->store->workspaceIds() as $workspace_id) {
      foreach ($this->store->getAllDrafts($workspace_id) as $key => $row) {
        if ($row['entity_type'] === $entity_type_id) {
          $found[] = [
            'workspace' => $workspace_id,
            'key' => (string) $key,
            'entity' => $storage->create($row['data']),
          ];
        }
      }
    }
    return $found;
  }

  /**
   * Removes one fallback draft and its metadata from one workspace.
   */
  public function deleteFallbackDraft(string $workspace_id, string $key): void {
    $this->store->deleteTarget($workspace_id, $key);
    $this->cache->delete($workspace_id . ':' . $key);
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
  }

  /**
   * Adds the entities core tracks in the workspace to the pending list.
   *
   * Content staged as pending revisions, and config staged by the
   * workspace_config module as workspace_config rows.
   *
   * @param array<string, array<string, mixed>> $out
   */
  private function appendWorkspaceTrackedEntities(array &$out, string $workspace_id): void {
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id);
    foreach ($tracked as $entity_type_id => $revision_map) {
      // Entities implicitly staged alongside a host item (e.g. the URL alias
      // written when a page with a changed path is staged) are not pending
      // changes of their own: they follow their host item through publish
      // and discard.
      // @see ::discardDependentStagedEntities()
      if (\in_array($entity_type_id, self::DEPENDENT_ENTITY_TYPE_IDS, TRUE)) {
        continue;
      }
      if ($entity_type_id === 'workspace_config') {
        $this->appendStagedWorkspaceConfig($out, \array_unique($revision_map), $workspace_id);
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage instanceof RevisionableStorageInterface) {
        continue;
      }
      // Several revisions of one entity may be tracked transiently; the
      // newest is the draft.
      $latest = [];
      foreach ($revision_map as $revision_id => $entity_id) {
        $latest[(string) $entity_id] = \max((int) $revision_id, $latest[(string) $entity_id] ?? 0);
      }
      foreach ($latest as $entity_id => $revision_id) {
        $entity = $storage->loadRevision($revision_id);
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $canonical = $this->loadUnchangedBase($entity_type_id, $entity_id);
        // Auto-save entries are per translation: emit one entry for every
        // translation whose staged state differs from the canonical one.
        // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
        foreach (\array_keys($entity->getTranslationLanguages()) as $langcode) {
          $translation = $entity->getTranslation($langcode);
          $key = $workspace_id . ':' . AutoSaveFallbackStore::targetKey($translation);
          if (isset($out[$key])) {
            continue;
          }
          $this->applyRecordedDraftPath($translation, $translation);
          $data_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($translation));
          if ($canonical instanceof ContentEntityInterface && $canonical->hasTranslation($langcode)) {
            $canonical_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($canonical->getTranslation($langcode)));
            if (\hash_equals($canonical_hash, $data_hash) && !$this->hasStoredFormViolations($key)) {
              continue;
            }
          }
          $out[$key] = [
            'entity_type' => $entity_type_id,
            'entity_id' => $translation->id(),
            'data' => AutoSaveManager::toStorableArray($translation),
            'langcode' => $langcode,
            'is_default_translation' => $translation->isDefaultTranslation(),
            'label' => self::labelForAutoSaveList($translation),
            'data_hash' => $data_hash,
            'client_id' => $this->getStagedClientId($translation),
            'owner' => self::stagedRevisionOwner($translation),
            'updated' => $this->stagedRevisionTime($translation),
          ];
        }
      }
    }
  }

  /**
   * Adds pending-list entries for config staged via workspace_config.
   *
   * Each workspace_config row stages one config object. Rows staging a config
   * entity are presented as that entity (loaded inside the workspace, so the
   * staged values drive type, ID, and label); rows staging simple config (or
   * config deleted in the workspace) are presented as the raw row. Runs
   * inside the workspace.
   *
   * @param array<string, array<string, mixed>> $out
   * @param array<int|string, int|string> $entity_ids
   */
  private function appendStagedWorkspaceConfig(array &$out, array $entity_ids, string $workspace_id): void {
    $storage = $this->entityTypeManager->getStorage('workspace_config');
    foreach ($storage->loadMultiple($entity_ids) as $row) {
      \assert($row instanceof ContentEntityInterface);
      $name = (string) $row->label();
      $mapped = $name === '' ? NULL : $this->configManager->loadConfigEntityByName($name);
      $metadata = [];
      if ($mapped instanceof ConfigEntityInterface) {
        $key = $workspace_id . ':' . AutoSaveFallbackStore::targetKey($mapped);
        if (isset($out[$key])) {
          // A fallback draft of the same config entity supersedes the staged
          // workspace copy in the pending list: it is the editor's current
          // working copy.
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
          $metadata = \array_intersect_key(
            $this->store->getMetadata($workspace_id, AutoSaveFallbackStore::targetKey($mapped)),
            \array_flip(['client_id', 'owner', 'updated']),
          );
        }
        $entry = [
          'entity_type' => $mapped->getEntityTypeId(),
          'entity_id' => $mapped->id(),
          'data' => $mapped->toArray(),
          'langcode' => $mapped->language()->getId(),
          'is_default_translation' => TRUE,
          'label' => self::labelForAutoSaveList($mapped),
          'data_hash' => $data_hash,
        ];
      }
      else {
        // Simple config, or config deleted in the workspace: no entity to
        // present, so the row itself carries the entry.
        $key = $workspace_id . ':' . AutoSaveFallbackStore::targetKey($row);
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
      // Attribution comes from the metadata Canvas records with each staged
      // write; config staged by other code (a config form submitted inside
      // the workspace) only has the tracking row to go on.
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
   * Deletes every tracked pending revision of one entity from the workspace.
   */
  private function discardTrackedRevisions(string $type_id, string $eid, string $workspace_id): void {
    $storage = $this->entityTypeManager->getStorage($type_id);
    if (!$storage instanceof RevisionableStorageInterface) {
      return;
    }
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id, $type_id, [$eid]);
    foreach (\array_keys($tracked[$type_id] ?? []) as $revision_id) {
      try {
        $storage->deleteRevision($revision_id);
      }
      catch (EntityStorageException) {
      }
    }
  }

  /**
   * Discards staged dependent entities (e.g. path aliases) of a host item.
   *
   * Staging a host entity inside the workspace also stages entities it
   * implicitly edits; when the host's staging is cleared, theirs must be too,
   * or they linger tracked (and exclusive-edit locked) with no owner.
   */
  private function discardDependentStagedEntities(ContentEntityInterface $entity, string $workspace_id): void {
    try {
      $host_path = '/' . $entity->toUrl()->getInternalPath();
    }
    catch (\Exception) {
      // Entities without a canonical route cannot have aliases.
      return;
    }
    foreach (self::DEPENDENT_ENTITY_TYPE_IDS as $dependent_type_id) {
      if (!$this->entityTypeManager->hasDefinition($dependent_type_id)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($dependent_type_id);
      if (!$storage instanceof RevisionableStorageInterface) {
        continue;
      }
      $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id, $dependent_type_id);
      foreach ($tracked[$dependent_type_id] ?? [] as $revision_id => $dependent_id) {
        $dependent = $storage->loadRevision($revision_id);
        if ($dependent instanceof ContentEntityInterface && $dependent->hasField('path') && $dependent->get('path')->value === $host_path) {
          $this->discardTrackedRevisions($dependent_type_id, (string) $dependent_id, $workspace_id);
        }
      }
    }
  }

  /**
   * Discards every store's draft of an entity in the staging workspace.
   */
  public function deleteEntity(EntityInterface $entity): void {
    $workspace_id = $this->getStagingWorkspaceId();
    if ($entity->id() !== NULL) {
      $this->store->deleteTarget($workspace_id, AutoSaveFallbackStore::targetKey($entity));
    }
    if ($entity instanceof ContentEntityInterface && $entity->id() !== NULL) {
      $this->discardTrackedRevisions($entity->getEntityTypeId(), (string) $entity->id(), $workspace_id);
      $this->discardDependentStagedEntities($entity, $workspace_id);
    }
    $this->discardWorkspaceStagedConfig($entity, $workspace_id);
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
    $this->cache->delete(AutoSaveManager::getAutoSaveKey($entity));
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
  private function discardWorkspaceStagedConfig(EntityInterface $entity, string $workspace_id): void {
    if (!$this->usesWorkspaceConfigStaging($entity) || $entity->id() === NULL) {
      return;
    }
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    $wm = $this->workspaceManager();
    $name = $entity->getConfigDependencyName();
    $type_id = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $this->executeInWorkspaceUnchecked($workspace_id, function () use ($wm, $name, $type_id, $id, $workspace_id): void {
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
      $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id, 'workspace_config');
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
   * Stages every config fallback draft of a workspace into the workspace.
   *
   * Runs inside core's pre-publish event. A config entity save inside the
   * workspace becomes workspace-scoped configuration, which the
   * workspace_config publish subscriber (priority 0) reads from the workspace
   * afterwards; a draft whose save succeeds leaves the fallback store, one
   * whose save fails stays and is reported.
   *
   * Content fallback rows are never staged here: core captured the tracked
   * revisions it will promote before dispatching the event, so a revision
   * saved now would be left behind, untracked, and lost. A content row
   * blocks the publish instead, until the editor re-saves the draft (which
   * retries the primary store) or discards it.
   *
   * @return array<string, \Throwable>
   *   The failures, keyed by auto-save key.
   *
   * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber::onPrePublish()
   * @see \Drupal\workspaces\WorkspacePublisher::publish()
   */
  public function stageFallbackDrafts(string $workspace_id): array {
    return $this->executeInWorkspaceUnchecked($workspace_id, function () use ($workspace_id): array {
      $failures = [];
      $this->executePublishTimeStaging(function () use ($workspace_id, &$failures): void {
        foreach ($this->store->getAllDrafts($workspace_id) as $key => $row) {
          if ($this->entityTypeManager->getDefinition($row['entity_type'])->entityClassImplements(ContentEntityInterface::class)) {
            continue;
          }
          $storage = $this->entityTypeManager->getStorage($row['entity_type']);
          $target = $storage->load($row['entity_id'] ?? '');
          $draft = $this->reconstructDraft($row, $target);
          try {
            if ($draft instanceof AutoSavePublishAwareInterface) {
              $draft->autoSavePublish();
            }
            $draft->save();
          }
          catch (\Throwable $e) {
            $failures[$workspace_id . ':' . $key] = $e;
            continue;
          }
          $this->store->drafts($workspace_id)->delete((string) $key);
          $this->cache->delete($workspace_id . ':' . $key);
        }
      });
      $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
      return $failures;
    });
  }

  /**
   * Clears every Canvas staging store for one workspace after its publish.
   *
   * Core clears the workspace association itself; this removes Canvas's
   * fallback rows and metadata, form violations, and caches. Runs for every
   * publish surface via the post-publish event.
   *
   * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber::onPostPublish()
   */
  public function clearWorkspaceStores(string $workspace_id): void {
    $this->store->deleteWorkspace($workspace_id);
    $prefix = $workspace_id . ':';
    $collections = [
      AutoSaveManager::FORM_VIOLATIONS_STORE,
      AutoSaveManager::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE,
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

  /**
   * Discards every draft in the staging workspace.
   */
  public function deleteAll(): void {
    $workspace_id = $this->getStagingWorkspaceId();
    $this->store->deleteWorkspace($workspace_id);
    // Workspace-tracked staged revisions are staging too: discard them, or
    // "discard all" leaves pending changes that reappear on the next listing.
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id);
    // Config staged as workspace-scoped configuration is discarded through
    // its own path, which also resets the workspace's cached config reads.
    foreach (\array_unique($tracked['workspace_config'] ?? []) as $row_id) {
      $row = $this->entityTypeManager->getStorage('workspace_config')->load($row_id);
      $name = $row === NULL ? '' : (string) $row->label();
      $mapped = $name === '' ? NULL : $this->executeInStagingWorkspace(fn () => $this->configManager->loadConfigEntityByName($name));
      if ($mapped instanceof ConfigEntityInterface) {
        $this->discardWorkspaceStagedConfig($mapped, $workspace_id);
      }
    }
    $tracked = $this->workspaceAssociation()->getTrackedEntities($workspace_id);
    foreach ($tracked as $entity_type_id => $revision_map) {
      foreach (\array_unique($revision_map) as $entity_id) {
        $this->discardTrackedRevisions($entity_type_id, (string) $entity_id, $workspace_id);
      }
    }
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
    $this->cache->deleteAll();
  }

  /**
   * Opaque token for concurrent-edit checks.
   *
   * Always derived from the saved base: the starting point identifies the base
   * an auto-save draft started from, so it must stay stable across successive
   * auto-saves and only change when the entity itself is saved.
   *
   * @see ::loadUnchangedBase()
   */
  public function getAutoSaveStartingPoint(EntityInterface $entity): string|int|null {
    \assert($entity->id() !== NULL);
    $saved_entity = $this->loadUnchangedBase($entity->getEntityTypeId(), (string) $entity->id());
    if ($saved_entity === NULL) {
      // Created inside the workspace: no Live copy exists until publish, so
      // nothing can change under the draft before then.
      return $this->usesWorkspaceConfigStaging($entity) ? self::UNPUBLISHED_STARTING_POINT : NULL;
    }
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
   * workspace revisions, so with the workspace active a plain loadUnchanged()
   * would return the draft itself rather than the Live base that hashes and
   * starting points must be computed against.
   *
   * Config entities staged as workspace-scoped configuration load outside
   * any workspace as well: inside it, the unchanged copy is the draft. For
   * configuration created inside the workspace this returns NULL, since no
   * Live copy exists until publish.
   *
   * Other config entities load inside the active workspace: their drafts are
   * fallback rows, never config writes, so the in-workspace unchanged copy is
   * the saved base. Loading outside the workspace would miss config that the
   * Workspace Config module staged in the workspace and has not published
   * yet, which only exists in that workspace's partition.
   *
   * @see ::usesWorkspaceConfigStaging()
   */
  public function loadUnchangedBase(string $entityTypeId, string|int $id): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $is_config = $this->entityTypeManager->getDefinition($entityTypeId) instanceof ConfigEntityTypeInterface;
    if ($is_config && !$this->usesWorkspaceConfigStagingForType($entityTypeId)) {
      return $storage->loadUnchanged($id);
    }
    return $this->workspaceManager()->executeOutsideWorkspace(static fn () => $storage->loadUnchanged($id));
  }

}
