<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\AutoSave;

// cspell:ignore Duderino

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveFallbackStore;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Workspace\WorkspaceEntityLockedException;
use Drupal\canvas\Workspace\WorkspaceNormalizer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\workspaces\Entity\Workspace;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Workspace-backed auto-save staging invariants.
 *
 * @coversDefaultClass \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class WorkspaceAutoSaveStagingTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'field',
    'entity_test',
    'language',
    'path_alias',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_mulrevpub');

    $account = $this->createUser([
      'administer workspaces',
      'view any workspace',
      'edit any workspace',
    ]);
    self::assertInstanceOf(User::class, $account);
    $this->setCurrentUser($account);
  }

  private function autoSaveManager(): AutoSaveManager {
    $manager = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $manager);
    return $manager;
  }

  private function trackedRevisionCount(string $entity_type_id, string $entity_id): int {
    $tracked = $this->container->get(WorkspaceTrackerInterface::class)
      ->getTrackedEntities(AutoSaveWorkspace::ID, $entity_type_id, [$entity_id]);
    return \count($tracked[$entity_type_id] ?? []);
  }

  /**
   * All revisions of an entity, live and pending.
   *
   * The workspace association tracks only the newest pending revision, so it
   * cannot measure revision churn.
   */
  private function revisionCount(string $entity_type_id, string $entity_id): int {
    return (int) $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity_type_id)
      ->getQuery()
      ->allRevisions()
      ->condition('id', $entity_id)
      ->count()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * An identical retry from the same client must not touch staged state.
   */
  public function testIdenticalPayloadRetryIsANoOp(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $manager = $this->autoSaveManager();

    $id = (string) $entity->id();
    $baseline = $this->revisionCount('entity_test_mulrevpub', $id);

    $draft = clone $entity;
    $draft->set('name', 'draft one');
    $manager->saveEntity($draft, 'client-a');
    self::assertSame($baseline + 1, $this->revisionCount('entity_test_mulrevpub', $id));
    self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', $id));

    // Simulate a client retry: rebuild the draft from the staged state (the
    // base a retrying client works from) and re-send the identical payload.
    $staged = $manager->getEntityForLayoutEditing($entity);
    $manager->saveEntity($staged, 'client-a');
    self::assertSame($baseline + 1, $this->revisionCount('entity_test_mulrevpub', $id), 'An identical retry does not create another staged revision.');
    self::assertFalse($manager->getAutoSaveEntity($entity)->isEmpty(), 'The staged draft survives the retry.');

    // A genuinely different payload stages a new revision.
    $staged = $manager->getEntityForLayoutEditing($entity);
    $changed = clone $staged;
    $changed->set('name', 'draft two');
    $manager->saveEntity($changed, 'client-a');
    // Only the newest staged revision is retained, so the count stays.
    self::assertSame($baseline + 1, $this->revisionCount('entity_test_mulrevpub', $id));
    $staged = $manager->getAutoSaveEntity($entity)->entity;
    self::assertInstanceOf(EntityTestMulRevPub::class, $staged);
    self::assertSame('draft two', $staged->get('name')->value);
  }

  /**
   * A draft identical to Live but with form violations is a pending change.
   *
   * Entity form values that fail validation are stored only as violations;
   * the staged content stays equal to the Live entity. The draft must still
   * be listed so publishing can surface the violations.
   *
   * @see \Drupal\canvas\ClientDataToEntityConverter::convert()
   */
  public function testContentIdenticalDraftWithFormViolationsIsListed(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $manager = $this->autoSaveManager();
    $key = AutoSaveManager::getAutoSaveKey($entity);

    $draft = clone $entity;
    $manager->saveEntityFormViolations($draft, new ConstraintViolationList([
      new ConstraintViolation('There are no users matching "El Duderino".', NULL, [], NULL, 'name', 'El Duderino'),
    ]));
    $manager->saveEntity($draft, 'client-a');

    self::assertFalse($manager->getAutoSaveEntity($entity)->isEmpty(), 'A content-identical draft with stored form violations is a pending change.');
    self::assertArrayHasKey($key, $manager->getAllAutoSaveList(with_entities: FALSE));

    // Clearing the violations and re-sending the identical payload resets
    // the draft.
    $manager->saveEntityFormViolations($draft);
    $manager->saveEntity($draft, 'client-a');
    self::assertTrue($manager->getAutoSaveEntity($entity)->isEmpty());
    self::assertArrayNotHasKey($key, $manager->getAllAutoSaveList(with_entities: FALSE));
  }

  /**
   * Only the newest staged revision of an entity is retained.
   */
  public function testOnlyLatestStagedRevisionIsRetained(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $manager = $this->autoSaveManager();
    foreach (['first draft', 'second draft', 'third draft'] as $name) {
      $draft = clone $entity;
      $draft->set('name', $name);
      $manager->saveEntity($draft);
      self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $entity->id()));
    }
    $staged = $manager->getAutoSaveEntity($entity)->entity;
    self::assertInstanceOf(EntityTestMulRevPub::class, $staged);
    self::assertSame('third draft', $staged->get('name')->value);
  }

  /**
   * Drafts the storage layer rejects as revisions fall back to key-value.
   */
  public function testUnstorableDraftFallsBackToReadableRow(): void {
    // Plain entity_test is neither revisionable nor publishable, so core
    // Workspaces refuses to save it while a workspace is active.
    $entity = EntityTest::create(['name' => 'live']);
    $entity->save();
    $manager = $this->autoSaveManager();

    $draft = clone $entity;
    $draft->set('name', 'unstorable draft');
    $manager->saveEntity($draft, 'client-a');

    $store = $this->container->get(AutoSaveFallbackStore::class);
    $row = $store->getDraft(AutoSaveWorkspace::ID, AutoSaveFallbackStore::targetKey($entity));
    self::assertNotNull($row, 'The draft was retained as a fallback row.');
    self::assertSame('client-a', $row['client_id']);
    self::assertSame('unstorable draft', $row['data']['name'][0]['value'] ?? NULL);

    $auto_save = $manager->getAutoSaveEntity($entity);
    self::assertFalse($auto_save->isEmpty(), 'Fallback drafts are readable for any entity type.');
    self::assertInstanceOf(EntityTest::class, $auto_save->entity);
    self::assertSame('unstorable draft', $auto_save->entity->get('name')->value);

    // The pending list contains the fallback-backed entry.
    $list = $manager->getAllAutoSaveList(FALSE);
    self::assertArrayHasKey(AutoSaveManager::getAutoSaveKey($entity), $list);

    // The workspace switcher's pending count includes fallback drafts.
    $normalizer = $this->container->get(WorkspaceNormalizer::class);
    $workspace = Workspace::load(AutoSaveWorkspace::ID);
    self::assertInstanceOf(Workspace::class, $workspace);
    self::assertSame(1, $normalizer->normalize($workspace, NULL)['pendingChangesCount']);

    // Discarding removes the fallback row.
    $manager->delete($entity);
    self::assertTrue($manager->getAutoSaveEntity($entity)->isEmpty());
    self::assertNull($store->getDraft(AutoSaveWorkspace::ID, AutoSaveFallbackStore::targetKey($entity)));
    self::assertSame(0, $normalizer->normalize($workspace, NULL)['pendingChangesCount']);
  }

  /**
   * Deleting all auto-saves clears every staging store.
   */
  public function testDeleteAllClearsWorkspaceTracking(): void {
    $manager = $this->autoSaveManager();

    // One workspace-staged draft.
    $revisionable = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $revisionable->save();
    $draft = clone $revisionable;
    $draft->set('name', 'staged');
    $manager->saveEntity($draft);
    self::assertSame(1, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $revisionable->id()));

    // One fallback-staged draft.
    $unstorable = EntityTest::create(['name' => 'live']);
    $unstorable->save();
    $fallback_draft = clone $unstorable;
    $fallback_draft->set('name', 'staged');
    $manager->saveEntity($fallback_draft);

    self::assertNotSame([], $manager->getAllAutoSaveList(FALSE));

    $manager->deleteAll();

    self::assertSame([], $manager->getAllAutoSaveList(FALSE), 'No pending entries remain after deleteAll().');
    self::assertSame(0, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $revisionable->id()), 'Workspace tracking is discarded by deleteAll().');
  }

  /**
   * Staged translations are listed per language with per-language hashes.
   */
  public function testPerTranslationPendingEntries(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $manager = $this->autoSaveManager();

    $entity = EntityTestMulRevPub::create(['name' => 'english live', 'status' => TRUE]);
    $entity->addTranslation('fr', ['name' => 'french live']);
    $entity->save();

    $en_draft = clone $entity;
    $en_draft->set('name', 'english draft');
    $manager->saveEntity($en_draft);

    $staged = $manager->getEntityForLayoutEditing($entity);
    $fr_draft = (clone $staged)->getTranslation('fr');
    $fr_draft->set('name', 'french draft');
    $manager->saveEntity($fr_draft);

    $list = $manager->getAllAutoSaveList(FALSE);
    $id = $entity->id();
    // Auto-save keys are workspace-prefixed; with no active workspace they
    // resolve against the Main workspace.
    $en_key = AutoSaveWorkspace::ID . ":entity_test_mulrevpub:$id:en";
    $fr_key = AutoSaveWorkspace::ID . ":entity_test_mulrevpub:$id:fr";
    self::assertArrayHasKey($en_key, $list);
    self::assertArrayHasKey($fr_key, $list);
    self::assertNotSame($list[$en_key]['data_hash'], $list[$fr_key]['data_hash']);
    self::assertSame('en', $list[$en_key]['langcode']);
    self::assertSame('fr', $list[$fr_key]['langcode']);
  }

  /**
   * Staged dependent entities follow their host and are never listed.
   */
  public function testDependentPathAliasFollowsHost(): void {
    $manager = $this->autoSaveManager();
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();

    $draft = clone $entity;
    $draft->set('name', 'draft');
    $manager->saveEntity($draft);

    // Simulate the alias implicitly staged by saving a host whose path field
    // changed: an alias entity saved while the auto-save workspace is active.
    $host_path = '/' . $entity->toUrl()->getInternalPath();
    $workspace_manager = $this->container->get(WorkspaceManagerInterface::class);
    $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, static function () use ($host_path): void {
      PathAlias::create(['path' => $host_path, 'alias' => '/staged-alias'])->save();
    });
    self::assertNotSame(0, $this->trackedRevisionCount('path_alias', '1'));

    // Dependents are not pending changes of their own. Keys carry the
    // workspace prefix, so match on the prefixed form.
    foreach (\array_keys($manager->getAllAutoSaveList(FALSE)) as $key) {
      self::assertStringStartsNotWith(AutoSaveWorkspace::ID . ':path_alias:', $key);
    }

    // Discarding the host discards the dependent's staging with it.
    $manager->delete($entity);
    self::assertSame(0, $this->trackedRevisionCount('path_alias', '1'), 'The staged alias follows its host on discard.');
  }

  /**
   * Main workspace access follows core workspace permissions.
   */
  public function testMainWorkspaceAccess(): void {
    $workspace = Workspace::load(AutoSaveWorkspace::ID);
    self::assertNotNull($workspace);

    $editor = $this->createUser([AutoSaveManager::PUBLISH_PERMISSION]);
    self::assertInstanceOf(User::class, $editor);
    self::assertFalse($workspace->access('view', $editor), 'Canvas permissions alone grant no workspace access.');
    self::assertFalse($workspace->access('publish', $editor));

    $plain = $this->createUser(['view any workspace', 'edit any workspace']);
    self::assertInstanceOf(User::class, $plain);
    self::assertTrue($workspace->access('view', $plain));
    self::assertTrue($workspace->access('publish', $plain), 'Publish follows core workspace permissions.');
    self::assertFalse($workspace->access('delete', $plain), 'The Main workspace cannot be deleted.');

    $admin = $this->createUser(['administer workspaces']);
    self::assertInstanceOf(User::class, $admin);
    self::assertTrue($workspace->access('view', $admin));
    self::assertTrue($workspace->access('publish', $admin));
    self::assertFalse($workspace->access('delete', $admin), 'The Main workspace cannot be deleted, even by administrators.');
  }

  /**
   * Core workspace-level publishing publishes the Main workspace.
   *
   * Phase 2 removes the Phase 1 publish blockers: Workspace::publish()
   * promotes the staged revisions and the post-publish subscriber clears
   * Canvas staging.
   */
  public function testCoreWorkspacePublishPublishesMainWorkspace(): void {
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    $draft = clone $entity;
    $draft->set('name', 'staged draft');
    $this->autoSaveManager()->saveEntity($draft);

    $workspace = Workspace::load(AutoSaveWorkspace::ID);
    self::assertNotNull($workspace);
    $workspace->publish();

    $live = $this->container->get(EntityTypeManagerInterface::class)->getStorage('entity_test_mulrevpub')->loadUnchanged((string) $entity->id());
    self::assertInstanceOf(EntityTestMulRevPub::class, $live);
    self::assertSame('staged draft', $live->get('name')->value);
    self::assertTrue($this->autoSaveManager()->getAutoSaveEntity($entity)->isEmpty(), 'Staging is cleared by the publish.');
    self::assertSame(0, $this->trackedRevisionCount('entity_test_mulrevpub', (string) $entity->id()));
  }

  /**
   * A staged write for an entity owned by another workspace is rejected.
   */
  public function testCrossWorkspaceLock(): void {
    Workspace::create(['id' => 'campaign', 'label' => 'Campaign'])->save();
    $entity = EntityTestMulRevPub::create(['name' => 'live', 'status' => TRUE]);
    $entity->save();
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $workspace_manager */
    $workspace_manager = $this->container->get(WorkspaceManagerInterface::class);
    $auto_save_manager = $this->autoSaveManager();

    $workspace_manager->executeInWorkspace('campaign', function () use ($entity, $auto_save_manager): void {
      $draft = clone $entity;
      $draft->set('name', 'campaign draft');
      $auto_save_manager->saveEntity($draft);
    });

    // The same entity cannot be staged in the Main workspace while the
    // campaign owns it.
    try {
      $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $auto_save_manager): void {
        $draft = clone $entity;
        $draft->set('name', 'main draft');
        $auto_save_manager->saveEntity($draft);
      });
      $this->fail('A staged write for an entity owned by another workspace must throw.');
    }
    catch (WorkspaceEntityLockedException $e) {
      self::assertSame('campaign', $e->workspaceId);
      self::assertSame('Campaign', $e->workspaceLabel);
    }

    // Discarding the owning workspace's staging releases the entity.
    $workspace_manager->executeInWorkspace('campaign', static function () use ($entity, $auto_save_manager): void {
      $auto_save_manager->delete($entity);
    });
    $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $auto_save_manager): void {
      $draft = clone $entity;
      $draft->set('name', 'main draft');
      $auto_save_manager->saveEntity($draft);
    });
    $main_staged = $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, static fn () => $auto_save_manager->getAutoSaveEntity($entity));
    self::assertFalse($main_staged->isEmpty());
  }

}
