<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\canvas\AutoSave\Workspace\AutoSaveFallbackStore;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\WorkspaceInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the client-side representation of a workspace.
 *
 * Every workspace API response (list, create, activate, and any endpoint a
 * sub-module adds) returns the same shape, documented as the `Workspace`
 * schema in openapi.yml. Sub-modules add their keys through
 * hook_canvas_workspace_normalize_alter().
 *
 * @see \Drupal\canvas\Controller\ApiWorkspaceController
 * @see hook_canvas_workspace_normalize_alter()
 */
final class WorkspaceNormalizer {

  public function __construct(
    private readonly AutoSaveFallbackStore $fallbackStore,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    // NULL on a site updating from 1.x until canvas_update_11201() has enabled
    // the Workspaces modules; the container must compile before that update.
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?WorkspaceManagerInterface $workspaceManager,
    #[Autowire(service: 'workspaces.tracker')]
    private readonly ?WorkspaceTrackerInterface $workspaceTracker,
  ) {}

  private function workspaceManager(): WorkspaceManagerInterface {
    return $this->workspaceManager ?? throw new \LogicException('The Workspaces module is not installed.');
  }

  private function workspaceTracker(): WorkspaceTrackerInterface {
    return $this->workspaceTracker ?? throw new \LogicException('The Workspaces module is not installed.');
  }

  /**
   * The ID of the current user's active workspace, or NULL when on Live.
   */
  public function activeWorkspaceId(): ?string {
    return $this->workspaceManager()->hasActiveWorkspace()
      ? (string) $this->workspaceManager()->getActiveWorkspace()?->id()
      : NULL;
  }

  /**
   * The client-side representation of one workspace.
   *
   * @param \Drupal\workspaces\WorkspaceInterface $workspace
   *   The workspace to normalize.
   * @param string|null $active_id
   *   The active workspace ID, or NULL when on Live.
   *
   * @return array<string, mixed>
   *   The normalized workspace; see the `Workspace` schema in openapi.yml.
   */
  public function normalize(WorkspaceInterface $workspace, ?string $active_id): array {
    $data = [
      'id' => (string) $workspace->id(),
      'label' => (string) $workspace->label(),
      'isDefault' => $workspace->id() === AutoSaveWorkspace::ID,
      'isActive' => $active_id === (string) $workspace->id(),
      'pendingChangesCount' => $this->countPendingChanges($workspace),
      'access' => [
        'delete' => $workspace->access('delete', $this->currentUser),
        'publish' => $workspace->access('publish', $this->currentUser),
      ],
    ];
    $this->moduleHandler->alter('canvas_workspace_normalize', $data, $workspace);
    return $data;
  }

  /**
   * The number of entities the workspace tracks, plus its fallback drafts.
   *
   * An approximation for the switcher and the delete confirmation; the
   * review manifest is the authoritative list.
   */
  private function countPendingChanges(WorkspaceInterface $workspace): int {
    $count = 0;
    foreach ($this->workspaceTracker()->getTrackedEntities((string) $workspace->id()) as $entity_type_id => $revision_map) {
      if ($entity_type_id === 'path_alias') {
        continue;
      }
      $count += \count(\array_unique($revision_map));
    }
    return $count + \count($this->fallbackStore->getAllDrafts((string) $workspace->id()));
  }

}
