<?php

declare(strict_types=1);

namespace Drupal\canvas_workflows\EventSubscriber;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas_workflows\WorkspaceReview;
use Drupal\workspaces\Event\WorkspacePostPublishEvent;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces the review gate and resets the Main workspace after a publish.
 *
 * Pre-publish: a review-required workspace must sit in an approved state.
 * Core dispatches this event inside every publish (Canvas API, core
 * Workspaces UI, cron), so no surface can bypass the gate.
 *
 * Post-publish: the Main workspace is the one permanent workspace; a publish
 * consumes its approval and any schedule. Named workspaces are deleted by
 * the Canvas post-publish subscriber, so they need no reset.
 *
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class WorkspaceReviewPublishSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly WorkspaceReview $workspaceReview,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      // Core's stopPublishing() does not stop propagation, and the
      // workspace_config module applies staged configuration at priority 0
      // without checking for a stopped publish: the gate must run first.
      WorkspacePrePublishEvent::class => ['onPrePublish', 100],
      WorkspacePostPublishEvent::class => 'onPostPublish',
    ];
  }

  public function onPrePublish(WorkspacePrePublishEvent $event): void {
    $workspace = $event->getWorkspace();
    if ($this->workspaceReview->isPublishBlocked($workspace)) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason(\sprintf(
        'The "%s" workspace requires review: it must be approved before it can be published. Its current review state is "%s".',
        (string) $workspace->label(),
        $this->workspaceReview->getStatusLabel($workspace),
      ));
    }
  }

  public static function onPostPublish(WorkspacePostPublishEvent $event): void {
    $workspace = $event->getWorkspace();
    if ($workspace->id() !== AutoSaveWorkspace::ID || !$workspace->hasField('canvas_workspace_status')) {
      return;
    }
    // An empty state resolves to the review workflow's initial state.
    // @see \Drupal\canvas_workflows\WorkspaceReview::getStatus()
    $workspace->set('canvas_workspace_status', NULL);
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->set('canvas_scheduled_publish_error', NULL);
    $workspace->save();
  }

}
