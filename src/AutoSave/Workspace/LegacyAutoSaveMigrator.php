<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasServiceProvider;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migrates legacy key-value auto-save entries into workspace staging.
 *
 * @see canvas_post_update_0031_migrate_auto_save_to_workspace()
 * @see canvas_post_update_0034_migrate_key_value_config_drafts()
 */
final class LegacyAutoSaveMigrator {

  public function __construct(
    // Staging bookkeeping must resolve identically in every workspace.
    // @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
    #[Autowire(service: CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE)]
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly Connection $database,
  ) {}

  public function migrateIfNeeded(EntityInterface $entity): void {
    $store = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $legacy = $store->get($key);
    if ($legacy === NULL) {
      // Rows written before auto-save keys became workspace-prefixed carry
      // the bare target key; treat them as staged in the Main workspace.
      $unprefixed = \explode(':', $key, 2)[1];
      $legacy = $store->get($unprefixed);
      if ($legacy === NULL) {
        return;
      }
      $key = $unprefixed;
    }
    if ($this->workspaceAutoSave->hasWorkspaceStaging($entity)) {
      $store->delete($key);
      return;
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->workspaceAutoSave->importLegacyArray($entity, $legacy);
      $store->delete($key);
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
  }

}
