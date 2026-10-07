<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\WorkspaceInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes a workspace through core workspace publish, atomically.
 *
 * Core's Workspace::publish() dispatches the pre-publish event (where Canvas
 * validates every tracked item and stages its fallback drafts, the
 * workspace_config module applies staged configuration, and gates such as
 * the canvas_workflows review gate may refuse), promotes every tracked
 * revision, then dispatches the post-publish event (where Canvas clears its
 * staging stores). Core's own transaction only covers the promotion; this
 * wraps the whole publish in one, so the applied configuration rolls back
 * with a failed promotion (core's transaction becomes a savepoint).
 *
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class CanvasWorkspacePublisher {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    // NULL on a site updating from 1.x until canvas_update_11201() has enabled
    // the Workspaces modules; the container must compile before that update.
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?WorkspaceManagerInterface $workspaceManager,
  ) {}

  private function workspaceManager(): WorkspaceManagerInterface {
    return $this->workspaceManager ?? throw new \LogicException('The Workspaces module is not installed.');
  }

  /**
   * Publishes a workspace.
   *
   * @param string $workspace_id
   *   The workspace to publish.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account publishing; per-item update access is checked against the
   *   current user, which callers switch to this account.
   *
   * @return int
   *   The number of pending changes that were published.
   *
   * @throws \Drupal\canvas\Workspace\WorkspacePublishValidationException
   *   When any tracked item fails validation or update access.
   * @throws \Drupal\workspaces\WorkspacePublishException
   *   When core (or a pre-publish subscriber, e.g. the canvas_workflows
   *   review gate) refuses the publish.
   * @throws \Exception
   *   Publishing saves entities and applies staged config, running arbitrary
   *   hooks; anything they throw propagates.
   */
  public function publish(string $workspace_id, AccountInterface $account): int {
    $workspace = $this->entityTypeManager->getStorage('workspace')->load($workspace_id);
    \assert($workspace instanceof WorkspaceInterface);

    $wm = $this->workspaceManager();
    $transaction = $this->database->startTransaction();
    try {
      $published_count = $wm->executeInWorkspace($workspace_id, function () use ($workspace): int {
        $count = \count($this->autoSaveManager->getAllAutoSaveList(with_entities: FALSE));
        $workspace->publish();
        return $count;
      });
      $transaction->commitOrRelease();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    // Publishing deletes named workspaces; scrub a session (or restored
    // context) still pointing at the deleted one so the next negotiation
    // does not resolve a dead ID.
    if ($this->entityTypeManager->getStorage('workspace')->load($workspace_id) === NULL
      && $wm->getActiveWorkspace()?->id() === $workspace_id) {
      $wm->switchToLive();
    }
    return $published_count;
  }

}
