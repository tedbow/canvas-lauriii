<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspaces\WorkspaceInterface;

/**
 * Adds Canvas's review and scheduling base fields to the workspace entity.
 *
 * @see \Drupal\canvas\Workspace\WorkspaceReview
 * @see \Drupal\canvas\Workspace\WorkspaceScheduledPublish
 */
final class CanvasWorkspaceHooks {

  public function __construct(
    private readonly WorkspaceReview $workspaceReview,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_canvas_workspace_normalize_alter().
   *
   * Adds the review state, the transitions the current user may execute,
   * and the schedule to the workspace API representation.
   *
   * @param array<string, mixed> $normalized
   *   The normalized workspace.
   */
  #[Hook('canvas_workspace_normalize_alter')]
  public function workspaceNormalizeAlter(array &$normalized, WorkspaceInterface $workspace): void {
    $scheduled_at = $workspace->get('canvas_scheduled_publish_at')->value;
    $normalized += [
      'status' => $this->workspaceReview->getStatus($workspace),
      'statusLabel' => $this->workspaceReview->getStatusLabel($workspace),
      'statusIsApproved' => $this->workspaceReview->isApproved($workspace),
      'statusIsInitial' => $this->workspaceReview->isInitialState($workspace),
      'requireReview' => WorkspaceReview::requiresReview($workspace),
      'availableTransitions' => \array_values(\array_map(
        static fn ($transition): array => [
          'id' => (string) $transition->id(),
          'label' => (string) $transition->label(),
        ],
        $this->workspaceReview->getAvailableTransitions($workspace, $this->currentUser),
      )),
      'scheduledPublishAt' => $scheduled_at !== NULL ? (int) $scheduled_at : NULL,
      'scheduledPublishError' => $workspace->get('canvas_scheduled_publish_error')->value,
    ];
  }

  /**
   * Implements hook_canvas_workspace_staged_write().
   *
   * An approval covers a specific content state, not future edits: any
   * Canvas staged write demotes the workspace to its initial review state.
   *
   * @see \Drupal\canvas\Workspace\WorkspaceReview::demoteOnStagedWrite()
   */
  #[Hook('canvas_workspace_staged_write')]
  public function workspaceStagedWrite(WorkspaceInterface $workspace): void {
    // The workspace entity may not carry Canvas's base fields yet (update
    // path mid-flight).
    if ($workspace->hasField('canvas_workspace_status')) {
      $this->workspaceReview->demoteOnStagedWrite($workspace);
    }
  }

  /**
   * Implements hook_entity_base_field_info().
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   */
  #[Hook('entity_base_field_info')]
  public static function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'workspace') {
      return [];
    }
    $fields = [];
    // A state ID of the workspace's review workflow. A plain string, not a
    // list: the valid values are whatever states the workflow defines. Empty
    // resolves to the workflow's initial state.
    // @see \Drupal\canvas\Workspace\WorkspaceReview::getStatus()
    $fields['canvas_workspace_status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Review state'))
      ->setDescription(new TranslatableMarkup('The Canvas review state of the workspace.'))
      ->setDefaultValue(WorkspaceReview::STATUS_DRAFT);

    $fields['canvas_review_workflow'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Review workflow'))
      ->setDescription(new TranslatableMarkup("The workflow whose states and transitions govern this workspace's review process."))
      ->setDefaultValue(WorkspaceReviewWorkflowType::DEFAULT_WORKFLOW_ID);

    $fields['canvas_require_review'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Require review before publishing'))
      ->setDescription(new TranslatableMarkup('Whether the workspace must be approved before it can be published.'))
      ->setDefaultValueCallback(static::class . '::defaultRequireReview')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['canvas_scheduled_publish_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Scheduled publish time'))
      ->setDescription(new TranslatableMarkup('When set, cron publishes the workspace at this time.'));

    $fields['canvas_scheduled_publish_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Scheduled by'))
      ->setDescription(new TranslatableMarkup('The user who scheduled the publish; cron publishes on their behalf.'))
      ->setSetting('target_type', 'user');

    $fields['canvas_scheduled_publish_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Last scheduled publish error'))
      ->setDescription(new TranslatableMarkup('The failure that cancelled the most recent scheduled publish.'));

    return $fields;
  }

  /**
   * Default value callback for `canvas_require_review`.
   *
   * The Main workspace is the scratch space and publishes without review;
   * named workspaces require review by default, matching the designed flow
   * where "Send for review" is the primary action.
   *
   * Referenced by name in the canvas_require_review field definition.
   *
   * @return array<int, array<string, bool>>
   */
  // @phpstan-ignore shipmonk.deadMethod
  public static function defaultRequireReview(WorkspaceInterface $workspace): array {
    return [['value' => $workspace->id() !== AutoSaveWorkspace::ID]];
  }

}
