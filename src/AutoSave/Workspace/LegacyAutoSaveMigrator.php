<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasServiceProvider;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migrates 1.x key-value auto-save entries into workspace staging.
 *
 * Each key-value row is persisted through the staged write path into the
 * Main workspace and then deleted. The row's key is the 1.x auto-save key
 * (`{type}:{id}[:{langcode}]`, no workspace prefix).
 *
 * @see canvas_post_update_0031_migrate_auto_save_to_workspace()
 */
final class LegacyAutoSaveMigrator {

  public function __construct(
    // Staging bookkeeping must resolve identically in every workspace.
    // @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
    #[Autowire(service: CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE)]
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly Connection $database,
  ) {}

  /**
   * Migrates one key-value row and removes it.
   *
   * Rows that cannot be migrated (malformed, or whose target entity no longer
   * exists) are removed as well: 1.x already dropped a draft with its entity.
   */
  public function migrate(string $key): void {
    $store = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $entry = $store->get($key);
    if (!\is_array($entry) || !isset($entry['entity_type'], $entry['entity_id']) || !\is_array($entry['data'] ?? NULL)) {
      $store->delete($key);
      return;
    }
    $entity = $this->resolveTarget($entry);
    if ($entity === NULL) {
      $store->delete($key);
      return;
    }

    // The migration is not a user action: it switches into the Main
    // workspace regardless of the updating account's workspace permissions.
    $this->workspaceAutoSave->executeInWorkspaceUnchecked(AutoSaveWorkspace::ID, function () use ($store, $key, $entity, $entry): void {
      if ($this->workspaceAutoSave->hasWorkspaceStaging($entity)) {
        $store->delete($key);
        return;
      }
      $transaction = $this->database->startTransaction();
      try {
        $this->workspaceAutoSave->importLegacyArray($entity, $entry);
        $store->delete($key);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    });
  }

  /**
   * The entity (translation) a key-value row is a draft of.
   *
   * @param array<string, mixed> $entry
   *   The key-value row.
   */
  private function resolveTarget(array $entry): ?EntityInterface {
    if (!$this->entityTypeManager->hasDefinition($entry['entity_type'])) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage($entry['entity_type']);
    $entity = $storage->load($entry['entity_id']);
    if ($entity === NULL) {
      // Config entities that exist only as a draft (staged configuration
      // translations) have no stored copy: reconstruct them from the row.
      if (!$storage->getEntityType() instanceof ConfigEntityTypeInterface) {
        return NULL;
      }
      $entity = $storage->create($entry['data']);
      $entity->enforceIsNew(FALSE);
      return $entity;
    }
    // Entries are per translation; the staged write derives the key from the
    // entity object, so it must receive the matching translation.
    if (isset($entry['langcode'])
      && $entity instanceof TranslatableInterface
      && $entity->hasTranslation($entry['langcode'])) {
      $entity = $entity->getTranslation($entry['langcode']);
    }
    return $entity;
  }

}
