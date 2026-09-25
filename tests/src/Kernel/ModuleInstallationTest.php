<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas_workflows\Plugin\WorkflowType\WorkspaceReviewWorkflowType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\workspaces\WorkspaceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests module installation.
 *
 * Note this cannot use CanvasKernelTestBase because it needs to test
 * installation and uninstallation of the module, which is not possible when the
 * module is already installed for the test class.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ModuleInstallationTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'entity_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('entity_test');
  }

  public function testModuleInstallation(): void {
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    self::assertFalse($this->container->get(ThemeHandlerInterface::class)->themeExists('canvas_stark'));

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();

    // The optional canvas_workflows sub-module adds base fields to the
    // workspace entity and ships a workflow; both must install cleanly on
    // top of Canvas and leave no trace after uninstall.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_workflows']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas_workflows'));
    $workspace_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('workspace');
    $main = $workspace_storage->loadUnchanged(AutoSaveWorkspace::ID);
    self::assertInstanceOf(WorkspaceInterface::class, $main);
    self::assertTrue($main->hasField('canvas_workspace_status'));
    // The Main workspace stores no review data, which keeps the sub-module
    // uninstallable: core refuses to uninstall a module whose base fields
    // hold data.
    // @see \Drupal\Core\Field\FieldModuleUninstallValidator
    foreach (['canvas_workspace_status', 'canvas_review_workflow', 'canvas_require_review', 'canvas_scheduled_publish_at'] as $field_name) {
      self::assertTrue($main->get($field_name)->isEmpty(), "$field_name is empty on the Main workspace.");
    }
    $workflow_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('workflow');
    self::assertNotNull($workflow_storage->load(WorkspaceReviewWorkflowType::DEFAULT_WORKFLOW_ID));

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_workflows']);
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas_workflows'));
    $main = $this->container->get(EntityTypeManagerInterface::class)->getStorage('workspace')->loadUnchanged(AutoSaveWorkspace::ID);
    self::assertInstanceOf(WorkspaceInterface::class, $main);
    self::assertFalse($main->hasField('canvas_workspace_status'), 'The review base fields are removed with the sub-module.');
    self::assertNull($this->container->get(EntityTypeManagerInterface::class)->getStorage('workflow')->load(WorkspaceReviewWorkflowType::DEFAULT_WORKFLOW_ID), 'The shipped workflow is removed with the sub-module.');

    $test_entity = EntityTest::create([
      'name' => 'Test entity',
    ]);
    $test_entity->save();

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    // Update a value to allow auto-save to be stored.
    $test_entity->set('name', 'I can haz auto save');
    $autoSave->saveEntity($test_entity);
    self::assertCount(1, $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));

    // Core's content uninstall validator prevents uninstalling a module that
    // provides a content entity type while content of that type exists.
    // Auto-save snapshots are content entities, so all auto-save data must be
    // deleted before Canvas can be uninstalled.
    // @see \Drupal\Core\Entity\ContentUninstallValidator
    $autoSave->deleteAll();

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas']);
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();
    self::assertCount(0, $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE), 'Auto-save items are removed after uninstallation.');

    // Installing the module after uninstallation does not lead to errors.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();
  }

  private function assertTCanvasStarkThemeExists(): void {
    $this->container->get(ThemeHandlerInterface::class)->reset();
    self::assertTrue($this->container->get(ThemeHandlerInterface::class)->themeExists('canvas_stark'));
  }

}
