<?php

declare(strict_types=1);

namespace Drupal\canvas_workflows;

/**
 * Thrown when an account lacks the permission for a review transition.
 *
 * @see \Drupal\canvas_workflows\WorkspaceReview::transition()
 */
final class WorkspaceReviewAccessException extends \RuntimeException {
}
