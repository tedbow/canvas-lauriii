<?php

declare(strict_types=1);

namespace Drupal\canvas_workflows\Hook;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas_workflows\WorkspaceReview;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspaces\Hook\EntityOperations;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for the Canvas Workflows module.
 *
 * Adds the review and scheduling base fields to the workspace entity, exposes
 * the review state through the Canvas workspace API, and demotes a
 * workspace's review state whenever work is staged into it.
 *
 * @see \Drupal\canvas_workflows\WorkspaceReview
 * @see \Drupal\canvas_workflows\WorkspaceScheduledPublish
 */
final class CanvasWorkflowsHooks {

  public function __construct(
    private readonly WorkspaceReview $workspaceReview,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'workspaces.manager')]
    private readonly WorkspaceManagerInterface $workspaceManager,
    #[Autowire(service: 'workspaces.information')]
    private readonly WorkspaceInformationInterface $workspaceInformation,
  ) {}

  /**
   * Implements hook_entity_presave().
   *
   * Demotes the active workspace to its initial review state when this save
   * stages work in it. Covers non-Canvas writes too (node forms,
   * workspace_config rows for config edits): anything core tracks in the
   * workspace is a staged write. Canvas's own fallback-store staging
   * demotes via hook_canvas_workspace_staged_write().
   *
   * Runs after the Workspaces module has decided the save is a pending
   * revision.
   *
   * @see \Drupal\canvas_workflows\WorkspaceReview::demoteOnStagedWrite()
   * @see ::workspaceStagedWrite()
   */
  #[Hook('entity_presave', order: new OrderAfter(classesAndMethods: [[EntityOperations::class, 'entityPresave']]))]
  public function demoteReviewStateOnStagedWrite(EntityInterface $entity): void {
    // Demotion saves the workspace itself, which re-enters this hook.
    if ($entity instanceof WorkspaceInterface) {
      return;
    }
    if (!$entity instanceof ContentEntityInterface || $entity->isSyncing()) {
      return;
    }
    if (!$this->workspaceManager->hasActiveWorkspace()) {
      return;
    }
    // Only writes the workspace actually captures demote it: supported
    // entity types are tracked as pending revisions, and workspace_config
    // rows carry config staged by the workspace_config module.
    if (!$this->workspaceInformation->isEntitySupported($entity)
      && $entity->getEntityTypeId() !== 'workspace_config') {
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceInterface $active */
    $active = $this->workspaceManager->getActiveWorkspace();
    $this->workspaceStagedWrite($active);
  }

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
   * @see \Drupal\canvas_workflows\WorkspaceReview::demoteOnStagedWrite()
   * @see hook_canvas_workspace_staged_write()
   */
  #[Hook('canvas_workspace_staged_write')]
  public function workspaceStagedWrite(WorkspaceInterface $workspace): void {
    // The workspace entity may not carry this module's base fields yet
    // (module install in progress).
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
    // No stored defaults: a workspace row only carries values this module
    // wrote, so the Main workspace stays free of module data and the module
    // remains uninstallable (core refuses to uninstall a module whose base
    // fields on another module's entity type hold data).
    // @see \Drupal\Core\Field\FieldModuleUninstallValidator
    //
    // A state ID of the workspace's review workflow. A plain string, not a
    // list: the valid values are whatever states the workflow defines. Empty
    // resolves to the workflow's initial state.
    // @see \Drupal\canvas_workflows\WorkspaceReview::getStatus()
    $fields['canvas_workspace_status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Review state'))
      ->setDescription(new TranslatableMarkup('The Canvas review state of the workspace.'));

    // Empty resolves to the workflow this module ships.
    // @see \Drupal\canvas_workflows\WorkspaceReview::getWorkflow()
    $fields['canvas_review_workflow'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Review workflow'))
      ->setDescription(new TranslatableMarkup("The workflow whose states and transitions govern this workspace's review process."));

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
   * where "Send for review" is the primary action. Workspaces that existed
   * before this module was installed store no value and do not require
   * review until a site builder enables it on the workspace form.
   *
   * Referenced by name in the canvas_require_review field definition.
   *
   * @return array<int, array<string, bool>>
   */
  // @phpstan-ignore shipmonk.deadMethod
  public static function defaultRequireReview(WorkspaceInterface $workspace): array {
    // The Main workspace stores no value: empty resolves to FALSE.
    // @see \Drupal\canvas_workflows\WorkspaceReview::requiresReview()
    return $workspace->id() === AutoSaveWorkspace::ID ? [] : [['value' => TRUE]];
  }

}
