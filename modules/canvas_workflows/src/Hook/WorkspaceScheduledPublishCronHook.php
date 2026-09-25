<?php

declare(strict_types=1);

namespace Drupal\canvas_workflows\Hook;

use Drupal\canvas_workflows\WorkspaceScheduledPublish;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook publishing workspaces whose scheduled time has passed.
 *
 * @see \Drupal\canvas_workflows\WorkspaceScheduledPublish
 */
class WorkspaceScheduledPublishCronHook {

  public function __construct(
    private readonly WorkspaceScheduledPublish $scheduledPublish,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function __invoke(): void {
    $this->scheduledPublish->publishDue();
  }

}
