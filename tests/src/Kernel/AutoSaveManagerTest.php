<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\User;
use Drupal\workspaces\WorkspaceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests Drupal\canvas\AutoSave\AutoSaveManager.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(AutoSaveManager::class)]
#[Group('canvas')]
class AutoSaveManagerTest extends CanvasKernelTestBase {

  use CanvasFieldCreationTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;

  private const string UUID_IN_ROOT = '78c73c1d-4988-4f9b-ad17-f7e337d40c29';

  protected static $modules = [
    'language',
    'node',
    'field',
  ];

  private static function recursiveReverseSort(array $data): array {
    // If $data is associative array reverse it, but preserve the keys.
    if (!array_is_list($data)) {
      $data = array_reverse($data, TRUE);
    }
    foreach ($data as $key => $value) {
      if (\is_array($value)) {
        $data[$key] = self::recursiveReverseSort($value);
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    $container->getDefinition('datetime.time')
      ->setClass(AutoSaveManagerTestTime::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.theme')->set('default', 'stark')->save();
    // URLs are generated during some of these kernel tests. Canvas depends on
    // the `path` module, so the PathAlias entity type must be installed. URL
    // generation fails without this.
    $this->installEntitySchema('path_alias');
    $this->generateComponentConfig();
  }

  private static function convertClientData(EntityInterface $entity, array $data): EntityInterface {
    if ($entity instanceof FieldableEntityInterface) {
      $data['model'] = (array) $data['model'];
      $layout = $data['layout'];
      $content = NULL;
      foreach ($layout as $region_node) {
        $client_side_region_id = $region_node['id'];
        if ($client_side_region_id === CanvasPageVariant::MAIN_CONTENT_REGION) {
          $content = $region_node;
        }
      }
      \assert($content !== NULL);
      \Drupal::service(ClientDataToEntityConverter::class)->convert(['layout' => $content] + $data, $entity, validate: FALSE);
      return $entity;
    }
    if ($entity instanceof PageRegion) {
      $entity = $entity->forAutoSaveData($data, validate: FALSE);
      return $entity;
    }
    \assert($entity instanceof CanvasHttpApiEligibleConfigEntityInterface);
    $updated_entity = $entity::create($entity->toArray());
    $updated_entity->updateFromClientSide($data);
    return $updated_entity;
  }

  private function assertAutoSaveCreated(EntityInterface $entity, array $matching_client_data, array $updated_client_data): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $autoSaveEntity = $this->convertClientData($entity, $matching_client_data);
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());
    // Reversing the order of the data should not trigger an auto-save entry either.
    $autoSaveEntity = $this->convertClientData($entity, self::recursiveReverseSort($matching_client_data));
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());

    // Now update the entity.
    $autoSaveEntity = $this->convertClientData($entity, $updated_client_data);
    $autoSave->saveEntity($autoSaveEntity);

    self::assertFalse($autoSave->getAutoSaveEntity($entity)->isEmpty());
    $autoSaveKey = AutoSaveManager::getAutoSaveKey($entity);
    $autoSaveEntry = $autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashInitial = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashInitial);

    // Reversing the order of the data should result in the exact same hash.
    $autoSaveEntity = $this->convertClientData($entity, self::recursiveReverseSort($updated_client_data));
    $autoSave->saveEntity($autoSaveEntity);
    self::assertFalse($autoSave->getAutoSaveEntity($entity)->isEmpty());
    $autoSaveEntry = $autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashReversedData = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashReversedData);
    self::assertSame($hashInitial, $hashReversedData);

    if ($entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      // Conflict detection, and with it the stored-entity hash bookkeeping,
      // only exists for content entities so far.
      // @see https://www.drupal.org/project/canvas/issues/3591544
      // Modifying the (config) entity `status` key does NOT result in the
      // auto-save being wiped, but in it being updated.
      $status_key = $entity->getEntityType()->getKey('status');
      if ($status_key) {
        self::assertTrue($autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey]['data'][$status_key]);
        $entity->disable()->save();
        self::assertFalse($autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey]['data'][$status_key]);
        // We also have to update the original client data so that a new auto
        // save entry deletes the existing (matching) data.
        $matching_client_data[$status_key] = FALSE;
      }

      // Modifying the (config) entity `label` key does NOT result in the
      // auto-save being wiped, but in it being updated.
      $label_key = $entity->getEntityType()->getKey('label');
      if ($label_key) {
        self::assertSame($updated_client_data[$label_key], $autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey]['data'][$label_key]);
        $entity->set($label_key, 'magic 🪄')->save();
        self::assertSame('magic 🪄', $autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey]['data'][$label_key]);
        // We also have to update the original client data so that a new auto
        // save entry deletes the existing (matching) data.
        $matching_client_data[$label_key] = 'magic 🪄';
      }
    }

    // Resaving the initial state should delete the auto-save entry.
    $autoSaveEntity = $this->convertClientData($entity, $matching_client_data);
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());
  }

  public function testCanvasPage(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $canvas_page = Page::create([
      'title' => '5 amazing uses for old toothbrushes',
      'components' => [],
    ]);
    self::assertEntityIsValid($canvas_page);
    self::assertSame(SAVED_NEW, $canvas_page->save());

    $request = Request::create('/api/canvas/content/canvas_page/' . $canvas_page->id());
    $envelope = \Drupal::classResolver(ApiLayoutController::class)->get(request: $request, entity: $canvas_page);
    \assert($envelope instanceof PreviewEnvelope);
    $matching_client_data = \array_intersect_key(
      $envelope->additionalData,
      \array_flip(['layout', 'model', 'entity_form_fields'])
    );
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($canvas_page, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component triggers an auto-save entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      // This is intentionally missing a version AND a non-existent component to
      // confirm that auto-saves do not perform validation.
      'type' => 'sdc.canvas_test_sdc.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($canvas_page, $matching_client_data, $new_component_client_data);
  }

  /**
   * Memoizes the reconstructed auto-save entity without serializing it.
   *
   * Repeated reads returning the same object instance proves no serialize /
   * unserialize round-trip happens (which would recompute computed fields like
   * metatag's and recurse).
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveEntity()
   */
  public function testGetAutoSaveEntityCachesWithoutSerialization(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page = Page::create([
      'title' => 'Original title',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $page->set('title', 'Changed title');
    $autoSave->saveEntity($page);

    $first = $autoSave->getAutoSaveEntity($page);
    self::assertFalse($first->isEmpty());
    $second = $autoSave->getAutoSaveEntity($page);
    self::assertSame($first, $second);
    self::assertSame($first->entity, $second->entity);
  }

  /**
   * Auto-save retains a NULL entity-reference target_uuid and still resolves.
   *
   * A reference set by target_id alone leaves target_uuid NULL. That NULL must
   * survive the auto-save round-trip (not be cast to '') so the reconstructed
   * entity still resolves the referenced entity.
   *
   * @see \Drupal\canvas\Utility\TypedDataHelper::castRawPhpTypes()
   */
  public function testNullCarryingPropertiesArePersisted(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $user = User::create([
      'name' => 'test_owner',
      'status' => 1,
    ]);
    self::assertSame(SAVED_NEW, $user->save());

    $page = Page::create([
      'title' => 'Original title',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);

    // Set the reference by `target_id` only, leaving `target_uuid` NULL.
    $page->set('owner', ['target_id' => (int) $user->id()]);
    $autoSave->saveEntity($page);

    $autoSaveKey = AutoSaveManager::getAutoSaveKey($page);
    $data = $autoSave->getAllAutoSaveList(with_entities: FALSE)[$autoSaveKey]['data'];

    // The stored item carries `target_id`, retaining `NULL` value.
    self::assertEquals($user->id(), $data['owner'][0]['target_id']);
    self::assertArrayHasKey('target_uuid', $data['owner'][0]);
    self::assertNull($data['owner'][0]['target_uuid']);

    // The reconstructed entity still resolves the reference.
    $reconstructed = $autoSave->getAutoSaveEntity($page)->entity;
    \assert($reconstructed instanceof Page);
    self::assertEquals($user->id(), $reconstructed->get('owner')->target_id);
    self::assertNotNull($reconstructed->get('owner')->entity);
  }

  /**
   * Tests that auto-saves for different Page translations are stored independently.
   *
   * Verifies that:
   * - Auto-saves for different translations use distinct keys.
   * - Saving/loading auto-saves in different languages doesn't interfere with each other
   */
  public function testPageAutoSaveTranslationBehavior(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['language']);

    // Create French language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Create the English page (default language).
    $page_en = Page::create([
      'title' => 'English page title',
      'langcode' => 'en',
      'components' => [],
    ]);
    self::assertEntityIsValid($page_en);
    self::assertSame(SAVED_NEW, $page_en->save());

    // Add French translation.
    $page_fr = $page_en->addTranslation('fr', [
      'title' => 'Titre de la page en français',
    ]);
    $page_fr->save();

    // Verify auto-save keys are different for each translation.
    $key_en = AutoSaveManager::getAutoSaveKey($page_en);
    $key_fr = AutoSaveManager::getAutoSaveKey($page_fr);
    self::assertNotEquals($key_en, $key_fr);

    // Confirm no auto-saves exist initially.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Make a change to the English page and save auto-save.
    $page_en->set('title', 'Modified English title');
    $auto_save_manager->saveEntity($page_en);

    // Verify English auto-save exists and French is unaffected.
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Verify only English auto-save is in the list.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE);
    self::assertEquals([$key_en], \array_keys($list));
    self::assertEquals('Modified English title', $list[$key_en]['label']);

    // Make a change to the French page and save auto-save.
    $page_fr->set('title', 'This is the French title');
    $auto_save_manager->saveEntity($page_fr);

    // Verify both auto-saves exist independently.
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Verify both auto-saves are in the list with correct labels.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE);
    $keys = \array_keys($list);
    asort($keys);
    self::assertEquals([$key_en, $key_fr], $keys);
    self::assertEquals('Modified English title', $list[$key_en]['label']);
    self::assertEquals('This is the French title', $list[$key_fr]['label']);

    // Verify language codes are stored correctly.
    self::assertEquals('en', $list[$key_en]['langcode']);
    self::assertEquals('fr', $list[$key_fr]['langcode']);

    // Delete the English auto-save by restoring original title.
    $page_en->set('title', 'English page title');
    $auto_save_manager->saveEntity($page_en);

    // Verify English auto-save is gone but French remains.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE);
    self::assertEquals([$key_fr], \array_keys($list));

    // Delete the French auto-save.
    $auto_save_manager->delete($page_fr);

    // Verify all auto-saves are gone.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());
    self::assertEmpty($auto_save_manager->getAllAutoSaveList(with_entities: FALSE));

    // Recreate an auto-save for each translation, then delete the entity. The
    // hook_entity_delete implementation must cascade and discard every
    // translation's auto-save, not just the default translation's, so no
    // orphaned sibling draft is left behind.
    // @see \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
    // @see \Drupal\Tests\canvas\Kernel\ComponentSource\ConfigEntitySymmetricalTranslationPropagationTestBase::testEntityDeleteDiscardsStagedOverrides()
    $page_en->set('title', 'Modified English title again');
    $auto_save_manager->saveEntity($page_en);
    $page_fr->set('title', 'Titre français à nouveau');
    $auto_save_manager->saveEntity($page_fr);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    $page_en->delete();
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());
    self::assertEmpty($auto_save_manager->getAllAutoSaveList(with_entities: FALSE));
  }

  public function testPageRegion(): void {
    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_IN_ROOT,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => 'world',
          ],
        ],
      ],
    ]);
    \assert($page_region instanceof PageRegion);
    $this->assertSame(SAVED_NEW, $page_region->save());
    $page_region_matching_client_data = $page_region->getComponentTree()->getClientSideRepresentation();
    $non_matching_region_client_data = $page_region_matching_client_data;
    $non_matching_region_client_data['model'][self::UUID_IN_ROOT]['resolved']['heading'] = 'This is a different heading.';
    $this->assertAutoSaveCreated($page_region, $page_region_matching_client_data, $non_matching_region_client_data);
  }

  public function testJsComponent(): void {
    $js_component = JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ]);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $js_component_matching_client_data = $js_component->normalizeForClientSide()->values;
    $js_component_matching_client_data['importedJsComponents'] = [];
    $non_matching_js_component_client_data = $js_component_matching_client_data;
    $non_matching_js_component_client_data['props']['text']['examples'][] = 'Press, or don\'t. Whatever.';
    $this->assertAutoSaveCreated($js_component, $js_component_matching_client_data, $non_matching_js_component_client_data);
  }

  public function testAssetLibrary(): void {
    $asset_library = AssetLibrary::load('global');
    \assert($asset_library instanceof AssetLibrary);
    $asset_library_matching_client_data = $asset_library->normalizeForClientSide()->values;
    $non_matching_asset_library_client_data = $asset_library_matching_client_data;
    $non_matching_asset_library_client_data['label'] = 'Slightly less boring label';
    $non_matching_asset_library_client_data['css']['original'] = $non_matching_asset_library_client_data['css']['original'] . '/**/';
    $this->assertAutoSaveCreated($asset_library, $asset_library_matching_client_data, $non_matching_asset_library_client_data);
  }

  public function testNode(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->createComponentTreeField('node', 'article', 'field_component_tree');
    $this->setUpImages();
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'status' => FALSE,
      'field_hero' => $this->referencedImage,
      'field_canvas_demo' => [],
      'body' => [
        'value' => '',
        'summary' => '',
      ],
    ]);
    self::assertEntityIsValid($node);
    $this->assertSame(SAVED_NEW, $node->save());

    $request = Request::create('/api/canvas/content/canvas_page/' . $node->id());
    $envelope = \Drupal::classResolver(ApiLayoutController::class)->get(request: $request, entity: $node);
    \assert($envelope instanceof PreviewEnvelope);
    $matching_client_data = \array_intersect_key(
      $envelope->additionalData,
      \array_flip(['layout', 'model', 'entity_form_fields'])
    );
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component to the node via the client also triggers an auto-save entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      'type' => 'sdc.canvas_test_sdc.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_component_client_data);
  }

  public function testStagedConfigUpdate(): void {
    $sut = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $sut);
    $key = AutoSaveWorkspace::ID . ':staged_config_update:canvas_change_site_name';
    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_change_site_name',
      'label' => 'Change the site name',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['name' => 'My awesome site'],
        ],
      ],
    ])->save();

    $list = $sut->getAllAutoSaveList(with_entities: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($key, $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My awesome site'],
      ],
    ], $list[$key]['data']['actions']);

    // Prove duplicated saves overwrite the previous one.
    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_change_site_name',
      'label' => 'Change the site name',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['name' => 'My SUPER AWESOME site'],
        ],
      ],
    ])->save();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($key, $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My SUPER AWESOME site'],
      ],
    ], $list[$key]['data']['actions']);

    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_set_homepage',
      'label' => 'Update the front page',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['page.front' => '/home'],
        ],
      ],
    ])->save();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE);
    self::assertCount(2, $list);
    self::assertArrayHasKey(AutoSaveWorkspace::ID . ':staged_config_update:canvas_set_homepage', $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My SUPER AWESOME site'],
      ],
    ], $list[$key]['data']['actions']);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['page.front' => '/home'],
      ],
    ], $list[AutoSaveWorkspace::ID . ':staged_config_update:canvas_set_homepage']['data']['actions']);

    // On config delete, auto-saved staged config updates targeting that config
    // should be deleted. In the current state, that's everything.
    $config_manager = $this->container->get(ConfigManagerInterface::class);
    \assert($config_manager instanceof ConfigManagerInterface);
    $config_manager->getConfigFactory()->getEditable('system.site')->delete();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE);
    self::assertEmpty($list);
  }

  public function testComponentFormViolationsTempStore(): void {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $uuid = 'b26efbd7-f711-481c-a001-947396ed6ad3';
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(0, $violations);
    $violations->add(new ConstraintViolation(
      'Bending Hectic',
      NULL,
      [],
      NULL,
      'strange.weather',
      'Grand Illusion',
    ));
    $auto_save_manager->saveComponentInstanceFormViolations($uuid, $violations);
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(1, $violations);
    $violation = $violations[0];
    \assert($violation instanceof ConstraintViolationInterface);
    self::assertEquals('Bending Hectic', $violation->getMessage());
    self::assertEquals('strange.weather', $violation->getPropertyPath());

    $page = Page::create([
      'title' => 'Immortal Love',
      'components' => [
        [
          'uuid' => $uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Cinnamon Temple',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $auto_save_manager->delete($page);
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(0, $violations);
  }

  /**
   * Tests that auto-save entries do not expire.
   *
   * Verifies that auto-save entries stored in the key-value store remain
   * accessible over extended periods of time.
   *
   * @legacy-covers ::saveEntity
   * @legacy-covers ::getAutoSaveEntity
   * @legacy-covers ::getAllAutoSaveList
   */
  public function testAutoSaveDoesNotExpire(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Create a page entity.
    $page = Page::create([
      'title' => 'Test page for persistence',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    // Make a change to trigger an auto-save.
    $page->set('title', 'Updated title');
    $auto_save_manager->saveEntity($page);

    // Verify the auto-save exists.
    $auto_save_key = AutoSaveManager::getAutoSaveKey($page);
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($auto_save_key, $list);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page)->isEmpty());
    self::assertEquals('Updated title', $list[$auto_save_key]['label']);

    $tempstore_expire = \Drupal::getContainer()->getParameter('tempstore.expire');
    self::assertIsInt($tempstore_expire);
    // Advance time so the tempstore has expired.
    AutoSaveManagerTestTime::$offset = $tempstore_expire + 24 * 60;

    // Verify the auto-save entry still persists after the tempstore has expired.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($auto_save_key, $list);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page)->isEmpty());
    self::assertEquals('Updated title', $list[$auto_save_key]['label']);
  }

  /**
   * Tests AutoSaveManager::getAllAutoSaveList parameters.
   *
   * @param bool $with_entities
   *   Whether the items in auto-save list should have 'entity' property with
   *   instances of EntityInterface.
   * @param int $total_items
   *   Total expected count of items in the auto-save item list.
   * @param int $items_with_entity_instance
   *   Expected count of items with 'entity' properties containing instances of
   *   EntityInterface.
   *
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::getAllAutoSaveList
   */
  #[TestWith([FALSE, 5, 0])]
  #[TestWith([TRUE, 5, 5])]
  public function testGetAllAutoSaveList(
    bool $with_entities,
    int $total_items,
    int $items_with_entity_instance,
  ): void {
    // Create 3 Page content entities.
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page1 = Page::create([
      'title' => 'Test Page 1, please ignore',
      'components' => [],
    ]);
    \assert($page1 instanceof Page);
    self::assertEntityIsValid($page1);
    self::assertSame(SAVED_NEW, $page1->save());

    $page2 = Page::create([
      'title' => 'Test Page 2, please ignore',
      'components' => [],
    ]);
    \assert($page2 instanceof Page);
    self::assertEntityIsValid($page2);
    self::assertSame(SAVED_NEW, $page2->save());

    $page3 = Page::create([
      'title' => 'Test Page 3, please ignore',
      'components' => [],
    ]);
    \assert($page3 instanceof Page);
    self::assertEntityIsValid($page3);
    self::assertSame(SAVED_NEW, $page3->save());

    // Create 2 PageRegion config entities.
    $component_tree_1 = [
      [
        'uuid' => self::UUID_IN_ROOT,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => 'Test heading, please ignore',
        ],
      ],
    ];
    $page_region_1 = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => $component_tree_1,
    ]);
    \assert($page_region_1 instanceof PageRegion);
    self::assertEntityIsValid($page_region_1);
    $this->assertSame(SAVED_NEW, $page_region_1->save());

    $component_tree_2 = [
      [
        'uuid' => self::UUID_IN_ROOT,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => 'Test heading, please ignore',
        ],
      ],
    ];
    $page_region_2 = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_second',
      'component_tree' => $component_tree_2,
    ]);
    \assert($page_region_2 instanceof PageRegion);
    self::assertEntityIsValid($page_region_2);
    $this->assertSame(SAVED_NEW, $page_region_2->save());

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities);
    self::assertIsArray($list);
    self::assertEmpty($list);

    // Modify all Page entities and add them to the auto-save.
    $page1->set('title', 'Updated title 1');
    $auto_save_manager->saveEntity($page1);

    $page2->set('title', 'Updated title 2');
    $auto_save_manager->saveEntity($page2);
    $page3->set('title', 'Updated title 3');
    $auto_save_manager->saveEntity($page3);

    // Modify all PageRegion entities and add them to the auto-save.
    $component_tree_1[0]['inputs']['heading'] = 'Updated heading, please ignore';
    $page_region_1->set('component_tree', $component_tree_1);
    self::assertEntityIsValid($page_region_1);
    $auto_save_manager->saveEntity($page_region_1);
    $component_tree_2[0]['inputs']['heading'] = 'Updated heading, please ignore';
    $page_region_2->set('component_tree', $component_tree_2);
    self::assertEntityIsValid($page_region_2);
    $auto_save_manager->saveEntity($page_region_2);

    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities);
    self::assertIsArray($list);
    self::assertCount($total_items, $list);
    // The 'entity' property is always set.
    self::assertCount($total_items, \array_column($list, 'entity'));
    // But $with_entities controls if it contains null or entity instance.
    self::assertCount($items_with_entity_instance, \array_filter($list, fn(array $item) => $item['entity'] instanceof EntityInterface));
    if (!$with_entities) {
      self::assertCount($total_items, \array_filter($list, fn(array $item) => \is_null($item['entity'])));
    }
  }

  /**
   * Tests that migrateLangcode() moves an auto-save entry to the new key.
   */
  public function testMigrateLangcodeMovesEntry(): void {
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('user');
    $this->installConfig(['language']);
    \Drupal::service(ModuleInstallerInterface::class)->install(['language', 'content_translation']);
    ConfigurableLanguage::createFromLangcode('de')->save();

    $page = Page::create([
      'title' => 'Untitled Page',
      'status' => FALSE,
      'components' => [],
    ]);
    $page->save();

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);

    // Simulate content typed before the switch: create an auto-save entry under
    // the original (English) key.
    $page->set('title', 'Draft content');
    $autoSave->saveEntity($page);

    $old_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertSame(AutoSaveWorkspace::ID . ':canvas_page:1:en', $old_key);
    // Reading the entry memoizes it under the old key.
    self::assertFalse($autoSave->getAutoSaveEntity($page)->isEmpty(), 'Auto-save exists under old key before migration.');

    // Change the entity langcode and migrate the auto-save. Like the langcode
    // PATCH, the save happens inside the staging workspace: an entity with a
    // pending revision in a workspace cannot be saved outside it.
    $page->set('langcode', 'de');
    $this->container->get(WorkspaceManagerInterface::class)->executeInWorkspace(AutoSaveWorkspace::ID, static fn () => $page->save());
    $checksum_provider = $this->container->get(CacheTagsChecksumInterface::class);
    \assert($checksum_provider instanceof CacheTagsChecksumInterface);
    $checksum = (int) $checksum_provider->getCurrentChecksum([AutoSaveManager::CACHE_TAG]);
    $autoSave->migrateLangcode($page, 'en');
    self::assertFalse($checksum_provider->isValid($checksum, [AutoSaveManager::CACHE_TAG]), 'Migrating an entry invalidates the auto-save cache tag.');

    $new_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertSame(AutoSaveWorkspace::ID . ':canvas_page:1:de', $new_key);

    // The memoized entry under the old key must be gone too, not just the
    // stored one.
    $old_language_page = clone $page;
    $old_language_page->set('langcode', 'en');
    self::assertSame($old_key, AutoSaveManager::getAutoSaveKey($old_language_page));
    self::assertTrue($autoSave->getAutoSaveEntity($old_language_page)->isEmpty(), 'No auto-save is memoized under the old key after migration.');

    // Old key must be gone; the new key must carry the entry with an updated
    // langcode, in its metadata and in the serialized field data.
    $list = $autoSave->getAllAutoSaveList(with_entities: FALSE);
    self::assertArrayNotHasKey($old_key, $list, 'Old auto-save key was deleted after migration.');
    self::assertArrayHasKey($new_key, $list, 'Auto-save entry exists under new key.');
    self::assertSame('de', $list[$new_key]['langcode'], 'Migrated entry langcode is updated to de.');
    self::assertSame('de', $list[$new_key]['data']['langcode'][0]['value'] ?? NULL, 'Serialized langcode field value is updated to de.');

    // Retrieving through AutoSaveManager must work via the new key.
    self::assertFalse($autoSave->getAutoSaveEntity($page)->isEmpty(), 'AutoSaveManager finds the entry under the new key.');
  }

  /**
   * Tests that migrateLangcode() is a no-op when no auto-save entry exists.
   */
  public function testMigrateLangcodeNoOpWithoutEntry(): void {
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('user');
    $this->installConfig(['language']);
    \Drupal::service(ModuleInstallerInterface::class)->install(['language', 'content_translation']);
    ConfigurableLanguage::createFromLangcode('de')->save();

    $page = Page::create([
      'title' => 'Untitled Page',
      'status' => FALSE,
      'components' => [],
    ]);
    $page->save();

    $page->set('langcode', 'de');
    $page->save();

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);

    // Calling migrate when there is no existing auto-save must not throw, must
    // not create an entry, and must not invalidate the auto-save cache tag.
    $checksum_provider = $this->container->get(CacheTagsChecksumInterface::class);
    \assert($checksum_provider instanceof CacheTagsChecksumInterface);
    $checksum = (int) $checksum_provider->getCurrentChecksum([AutoSaveManager::CACHE_TAG]);
    $autoSave->migrateLangcode($page, 'en');
    self::assertTrue($autoSave->getAutoSaveEntity($page)->isEmpty(), 'No auto-save entry is created by a no-op migration.');
    self::assertTrue($checksum_provider->isValid($checksum, [AutoSaveManager::CACHE_TAG]), 'A no-op migration does not invalidate the auto-save cache tag.');

    // The same holds when the langcode did not change at all.
    $autoSave->migrateLangcode($page, 'de');
    self::assertTrue($checksum_provider->isValid($checksum, [AutoSaveManager::CACHE_TAG]), 'Migrating to the same langcode does not invalidate the auto-save cache tag.');
  }

}

/**
 * Test time service that allows time offset for testing.
 */
class AutoSaveManagerTestTime extends Time {

  /**
   * An offset to add to the request time.
   *
   * @var int
   */
  public static $offset = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return parent::getRequestTime() + static::$offset;
  }

}
