<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\workspaces\Hook\EntityOperations;
use Drupal\workspaces\WorkspaceInterface;

/**
 * Reacts to entity saves that stage work into the active workspace.
 *
 * Keeps revision metadata accurate for staged content entity saves, and
 * keeps Canvas staging bookkeeping in step with workspace deletion and
 * bookkeeping switches.
 */
final class WorkspaceAutoSaveRevisionHooks {

  public function __construct(
    private readonly WorkspaceAutoSave $workspaceAutoSave,
  ) {}

  /**
   * Runs after workspaces sets ::setNewRevision(TRUE) on pending saves.
   */
  #[Hook('entity_presave', order: new OrderAfter(classesAndMethods: [[EntityOperations::class, 'entityPresave']]))]
  public function stampRevisionMetadataForAutoSaveWorkspace(EntityInterface $entity): void {
    $this->workspaceAutoSave->stampAutoSaveWorkspaceRevisionMetadata($entity);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for workspace entities.
   *
   * A deleted workspace's staged Canvas data goes with it, whichever surface
   * performed the deletion (Canvas API, core Workspaces UI): a workspace
   * later created with the same machine name must not inherit it.
   */
  #[Hook('workspace_delete')]
  public function workspaceDelete(WorkspaceInterface $workspace): void {
    $this->workspaceAutoSave->clearWorkspaceStores((string) $workspace->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_access() for workspace entities.
   *
   * Grants the view access core requires to switch into a workspace while
   * Canvas staging bookkeeping is switching into it on the user's behalf.
   *
   * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::executeInWorkspaceUnchecked()
   */
  #[Hook('workspace_access')]
  public function workspaceAccess(WorkspaceInterface $workspace, string $operation): AccessResultInterface {
    if ($operation === 'view' && $this->workspaceAutoSave->isUncheckedSwitchInto((string) $workspace->id())) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    return AccessResult::neutral();
  }

}
