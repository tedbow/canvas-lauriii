<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Workspace-related entity type alterations.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave
 */
final class WorkspaceAutoSaveHooks {

  /**
   * Implements hook_entity_type_build().
   */
  #[Hook('entity_type_build')]
  public static function entityTypeBuild(array &$entity_types): void {
    if (!\class_exists(IgnoredWorkspaceHandler::class)) {
      return;
    }
    // Canvas config entities (components, code components, asset libraries,
    // patterns, folders, staged config updates, personalization segments, …)
    // are staged by the Workspace Config module or Canvas's fallback store,
    // not by Workspaces itself. Without this, core's workspace provider
    // forbids saving them while a workspace is active during Canvas API
    // requests.
    // @see \Drupal\workspaces\Provider\WorkspaceProviderBase::entityPresave()
    foreach ($entity_types as $entity_type) {
      if (!$entity_type instanceof ConfigEntityTypeInterface || $entity_type->hasHandlerClass('workspace')) {
        continue;
      }
      $provider = $entity_type->getProvider();
      if ($provider === 'canvas' || \str_starts_with($provider, 'canvas_')) {
        $entity_type->setHandlerClass('workspace', IgnoredWorkspaceHandler::class);
      }
    }
  }

  /**
   * Implements hook_workspace_config_safe_list_alter().
   *
   * The Workspace Config module ships a built-in list of Canvas config entity
   * types that stage per workspace; page variants replaced page regions after
   * that list was written, so they are declared here. Without this, saving a
   * page variant while a workspace is active is refused outright.
   *
   * @see \Drupal\workspace_config\Hook\WorkspaceConfigSchemaHooks
   * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::usesWorkspaceConfigStaging()
   */
  #[Hook('workspace_config_safe_list_alter')]
  public static function workspaceConfigSafeListAlter(array &$patterns): void {
    $patterns[] = 'canvas.page_variant.*';
  }

}
