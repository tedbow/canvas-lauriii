<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\CanvasServiceProvider;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspaces\WorkspaceManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Auto-save staging bookkeeping resolves the same in every workspace.
 *
 * The workspace_config module turns every key-value collection into a
 * per-workspace overlay while a workspace is active. Canvas staging rows are
 * written inside the auto-save workspace but read, deleted and migrated
 * outside it, so they must never land in an overlay.
 *
 * Kernel tests wire the pristine staging factory through the test trait, so
 * what is covered here is the invariant, not CanvasServiceProvider's own
 * container plumbing, which only has anything to bypass on a real site.
 *
 * @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
 */
#[Group('canvas')]
#[Group('canvas_auto_save')]
final class WorkspaceInvariantStagingStoreTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'workspaces',
    'workspace_config',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');

    $account = $this->createUser(['administer workspaces']);
    \assert($account instanceof AccountInterface);
    $this->setCurrentUser($account);
  }

  /**
   * The base must reproduce the overlay a workspace_config site really has.
   *
   * The overlay applies to the collections workspace_config lists (core's
   * config entity key store among them), not to every collection.
   *
   * @see \Drupal\workspace_config\KeyValue\WorkspaceConfigKeyValueInformation
   */
  public function testKeyValueOverlayIsActiveForOtherCollections(): void {
    $factory = $this->container->get('keyvalue');
    \assert($factory instanceof KeyValueFactoryInterface);

    $this->executeInAutoSaveWorkspace(function () use ($factory): void {
      $factory->get('config.entity.key_store.canvas_test')->set('key', 'staged');
    });

    // Written inside the workspace, so Live must not see it: this is the
    // behavior Canvas staging must be insulated from.
    self::assertNull($factory->get('config.entity.key_store.canvas_test')->get('key'));
  }

  /**
   * Rows written inside the workspace must not be trapped in its partition.
   */
  public function testStagingRowsWrittenInTheWorkspaceAreReadableInLive(): void {
    $staging = $this->container->get(CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE);
    \assert($staging instanceof KeyValueFactoryInterface);
    $store = $staging->get(AutoSaveManager::AUTO_SAVE_STORE);

    $this->executeInAutoSaveWorkspace(static function () use ($store): void {
      $store->set('canvas_page:1', ['data' => 'draft']);
    });

    self::assertSame(['data' => 'draft'], $store->get('canvas_page:1'));
    self::assertSame(['canvas_page:1' => ['data' => 'draft']], $store->getAll());
  }

  /**
   * Deleting inside the workspace must remove the row, not tombstone it.
   *
   * The legacy key-value migration deletes the row it migrated while the
   * auto-save workspace is active. An overlay would only tombstone the key and
   * leave the global row behind, so the row would migrate again on every read.
   */
  public function testStagingRowsDeletedInTheWorkspaceAreGoneInLive(): void {
    $staging = $this->container->get(CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE);
    \assert($staging instanceof KeyValueFactoryInterface);
    $store = $staging->get(AutoSaveManager::AUTO_SAVE_STORE);
    $store->set('canvas_page:1', ['data' => 'legacy']);

    $this->executeInAutoSaveWorkspace(static function () use ($store): void {
      self::assertSame(['data' => 'legacy'], $store->get('canvas_page:1'));
      $store->delete('canvas_page:1');
    });

    self::assertNull($store->get('canvas_page:1'));
    self::assertSame([], $store->getAll());
  }

  private function executeInAutoSaveWorkspace(callable $callback): void {
    $workspace_manager = $this->container->get(WorkspaceManagerInterface::class);
    \assert($workspace_manager instanceof WorkspaceManagerInterface);
    $workspace_manager->executeInWorkspace(AutoSaveWorkspace::ID, $callback);
  }

}
