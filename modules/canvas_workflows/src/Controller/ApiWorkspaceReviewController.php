<?php

declare(strict_types=1);

namespace Drupal\canvas_workflows\Controller;

use Drupal\canvas\Workspace\WorkspaceNormalizer;
use Drupal\canvas_workflows\WorkspaceReview;
use Drupal\canvas_workflows\WorkspaceReviewAccessException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\WorkspaceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Review transition and scheduled publish endpoints for the Canvas UI.
 *
 * Responses use the same workspace representation as the Canvas workspace
 * endpoints, with the review and schedule keys this module adds.
 *
 * @see \Drupal\canvas\Controller\ApiWorkspaceController
 * @see \Drupal\canvas_workflows\Hook\CanvasWorkflowsHooks::workspaceNormalizeAlter()
 *
 * @internal This HTTP API is intended only for the Canvas UI. These
 *   controllers and associated routes may change at any time.
 */
final class ApiWorkspaceReviewController {

  public function __construct(
    private readonly WorkspaceReview $workspaceReview,
    private readonly WorkspaceNormalizer $normalizer,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Executes a review workflow transition on the workspace.
   *
   * The body's "transition" is a transition ID of the workspace's review
   * workflow.
   */
  public function status(WorkspaceInterface $workspace, Request $request): JsonResponse {
    $body = \json_decode($request->getContent(), TRUE);
    $transition_id = \is_array($body) ? (string) ($body['transition'] ?? '') : '';
    if ($transition_id === '') {
      throw new BadRequestHttpException('A non-empty "transition" is required.');
    }
    if (!$workspace->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to act on this workspace.');
    }
    try {
      $this->workspaceReview->transition($workspace, $transition_id, $this->currentUser);
    }
    catch (WorkspaceReviewAccessException $e) {
      throw new AccessDeniedHttpException($e->getMessage(), $e);
    }
    catch (\InvalidArgumentException $e) {
      throw new ConflictHttpException($e->getMessage(), $e);
    }
    return $this->workspaceResponse($workspace);
  }

  /**
   * Schedules the workspace to publish at a given time.
   */
  public function schedule(WorkspaceInterface $workspace, Request $request): JsonResponse {
    if (!$workspace->access('publish', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to publish this workspace.');
    }
    $body = \json_decode($request->getContent(), TRUE);
    $publish_at = \is_array($body) ? $body['publishAt'] ?? NULL : NULL;
    if (!\is_int($publish_at)) {
      throw new BadRequestHttpException('An integer "publishAt" timestamp is required.');
    }
    if ($publish_at <= $this->time->getRequestTime()) {
      throw new BadRequestHttpException('The "publishAt" timestamp must be in the future.');
    }
    // Scheduling inherits the review gate: a review-required workspace must
    // already be approved, exactly as if it were being published now.
    if ($this->workspaceReview->isPublishBlocked($workspace)) {
      throw new ConflictHttpException(\sprintf(
        'The workspace must be approved before it can be scheduled; its review state is "%s".',
        $this->workspaceReview->getStatusLabel($workspace),
      ));
    }
    $workspace->set('canvas_scheduled_publish_at', $publish_at);
    $workspace->set('canvas_scheduled_publish_by', $this->currentUser->id());
    $workspace->set('canvas_scheduled_publish_error', NULL);
    $workspace->save();
    return $this->workspaceResponse($workspace);
  }

  /**
   * Cancels the workspace's scheduled publish.
   */
  public function unschedule(WorkspaceInterface $workspace): JsonResponse {
    if (!$workspace->access('publish', $this->currentUser)) {
      throw new AccessDeniedHttpException('You do not have permission to publish this workspace.');
    }
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->save();
    return $this->workspaceResponse($workspace);
  }

  private function workspaceResponse(WorkspaceInterface $workspace): JsonResponse {
    return new JsonResponse(
      data: $this->normalizer->normalize($workspace, $this->normalizer->activeWorkspaceId()),
      status: Response::HTTP_OK,
    );
  }

}
