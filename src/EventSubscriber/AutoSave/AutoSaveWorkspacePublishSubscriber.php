<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Workspace\WorkspacePublishValidationException;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\Event\WorkspacePostPublishEvent;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Validates, stages and finalizes workspace publishes for Canvas.
 *
 * Pre-publish: validates every pending change of the workspace (content
 * entities via entity validation plus recorded form violations, config
 * entities via typed data) and checks per-item update access, then stages
 * every fallback-store draft into the workspace so core's publish promotes
 * it. Any failure throws a WorkspacePublishValidationException, which core
 * does not catch: no live write happens when anything is invalid. Because
 * core dispatches this event inside every publish (Canvas API, core
 * Workspaces UI, cron), every surface is validated the same way.
 *
 * Post-publish: clears every Canvas staging store for the workspace, then
 * deletes the workspace: a published workspace is a completed unit of work.
 * The Main workspace is the one permanent workspace and survives its
 * publishes.
 *
 * Further gates (e.g. the canvas_workflows review gate) are separate
 * subscribers.
 *
 * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
 * @see \Drupal\workspaces\WorkspacePublisher::publish()
 */
final class AutoSaveWorkspacePublishSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      // After the review gate (priority 100) and before the workspace_config
      // module applies staged configuration at priority 0 without checking
      // for a stopped publish.
      WorkspacePrePublishEvent::class => ['onPrePublish', 90],
      // After core's association cleanup (priority -500), which resolves the
      // workspace tree and must still find the workspace.
      // @see \Drupal\workspaces\WorkspaceTracker::getSubscribedEvents()
      WorkspacePostPublishEvent::class => ['onPostPublish', -600],
    ];
  }

  /**
   * Validates every pending change, then stages the fallback drafts.
   *
   * @throws \Drupal\canvas\Workspace\WorkspacePublishValidationException
   */
  public function onPrePublish(WorkspacePrePublishEvent $event): void {
    $workspace_id = (string) $event->getWorkspace()->id();
    $this->workspaceAutoSave->executeInWorkspaceUnchecked($workspace_id, function () use ($workspace_id): void {
      $violation_sets = [];
      foreach ($this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE) as $entry) {
        $entity = $entry['entity'] ?? NULL;
        if (!$entity instanceof EntityInterface) {
          continue;
        }
        $entity->enforceIsNew(FALSE);
        $access = $entity->access('update', $this->currentUser, return_as_object: TRUE);
        \assert($access instanceof AccessResultInterface);
        if (!$access->isAllowed() && !self::isManifestOnlyEntity($entity)) {
          $violation_sets[] = self::accessViolation($entity);
          continue;
        }
        $item_violations = $this->validateItem($entity);
        if ($item_violations !== NULL && $item_violations->count() > 0) {
          $violation_sets[] = $item_violations;
        }
      }
      if ($violation_sets !== []) {
        throw new WorkspacePublishValidationException($violation_sets);
      }

      // Fallback drafts are invisible to core's publish until they are staged
      // into the workspace. A draft the storage layer still rejects cannot be
      // published; it stays pending and blocks the publish.
      foreach ($this->workspaceAutoSave->stageFallbackDrafts($workspace_id) as $key => $exception) {
        $violation_sets[] = self::stagingViolation($key, $exception);
      }
      if ($violation_sets !== []) {
        throw new WorkspacePublishValidationException($violation_sets);
      }
    });
  }

  public function onPostPublish(WorkspacePostPublishEvent $event): void {
    $workspace = $event->getWorkspace();
    $this->workspaceAutoSave->clearWorkspaceStores((string) $workspace->id());
    if ($workspace->id() === AutoSaveWorkspace::ID) {
      // The Main workspace is permanent: the next editing cycle starts over.
      return;
    }
    // A named workspace is a unit of work; publishing completes it. Its
    // content is live and its staging stores are cleared, so nothing is
    // lost. Sessions still pointing at it re-negotiate to no workspace and
    // the editor falls back to the Main workspace.
    $workspace->delete();
  }

  /**
   * Validates one pending change; NULL when it has no validatable form.
   */
  private function validateItem(EntityInterface $entity): ?ConstraintViolationListInterface {
    if (self::isManifestOnlyEntity($entity)) {
      return NULL;
    }
    if ($entity instanceof ConfigEntityInterface) {
      $violations = $entity->getTypedData()->validate();
      return $violations->count() > 0 ? new EntityConstraintViolationList($entity, $violations) : NULL;
    }
    if ($entity instanceof ContentEntityInterface) {
      $violations = $entity->validate();
      $form_violations = $this->autoSaveManager->getEntityFormViolations($entity);
      foreach ($form_violations as $form_violation) {
        $violations->add($form_violation);
      }
      if ($violations->count() === 0) {
        return NULL;
      }
      return ApiAutoSaveController::getViolationSetsFromPropertyPathsAndRoot($entity, $violations);
    }
    return NULL;
  }

  /**
   * Entities listed for review completeness that Canvas cannot validate.
   *
   * Simple config staged by workspace_config has no entity-level validation
   * or update access; it is applied by workspace_config's ConfigImporter at
   * publish, which enforces schema itself.
   */
  private static function isManifestOnlyEntity(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'workspace_config';
  }

  /**
   * A per-item violation set for an update-access failure.
   */
  private static function accessViolation(EntityInterface $entity): EntityConstraintViolationList {
    $message = \sprintf('You do not have permission to update %s.', (string) ($entity->label() ?? $entity->id()));
    $violation = new ConstraintViolation(
      message: $message,
      messageTemplate: $message,
      parameters: [],
      root: $entity,
      propertyPath: AutoSaveManager::getAutoSaveKey($entity),
      invalidValue: NULL,
    );
    return new EntityConstraintViolationList($entity, [$violation]);
  }

  /**
   * A violation set for a fallback draft the storage layer still rejects.
   */
  private static function stagingViolation(string $key, \Throwable $exception): ConstraintViolationListInterface {
    $message = \sprintf('The draft %s cannot be stored: %s', $key, $exception->getMessage());
    $violation = new ConstraintViolation(
      message: $message,
      messageTemplate: $message,
      parameters: [],
      root: NULL,
      propertyPath: $key,
      invalidValue: NULL,
    );
    return new ConstraintViolationList([$violation]);
  }

}
