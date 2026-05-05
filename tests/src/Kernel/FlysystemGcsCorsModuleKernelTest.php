<?php

namespace Drupal\Tests\flysystem_gcs_cors\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies Drupal discovers the module's field type overrides.
 */
#[CoversNothing]
#[Group('flysystem_gcs_cors')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
class FlysystemGcsCorsModuleKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'token',
    'flysystem_gcs_cors',
  ];

  /**
   * Tests core file and image field definitions are altered.
   */
  public function testFieldTypeDefinitionsAreAltered(): void {
    $definitions = $this->container
      ->get('plugin.manager.field.field_type')
      ->getDefinitions();

    $this->assertSame(
      '\Drupal\flysystem_gcs_cors\Plugin\Field\FieldType\FlysystemGcsCorsFile',
      $definitions['file']['class'],
    );
    $this->assertSame(
      '\Drupal\flysystem_gcs_cors\Plugin\Field\FieldType\FlysystemGcsCorsImage',
      $definitions['image']['class'],
    );
  }

  /**
   * Tests admin config defaults and schema are available.
   */
  public function testAdminConfigSchemaAndDefaults(): void {
    $this->installConfig(['flysystem_gcs_cors']);

    $config = $this->config('flysystem_gcs_cors.admin');
    $this->assertSame('', $config->get('origin'));
    $this->assertSame('', $config->get('scheme'));

    $definition = $this->container
      ->get('config.typed')
      ->getDefinition('flysystem_gcs_cors.admin');

    $this->assertArrayHasKey('mapping', $definition);
    $this->assertArrayHasKey('origin', $definition['mapping']);
    $this->assertArrayHasKey('scheme', $definition['mapping']);
    $this->assertSame('string', $definition['mapping']['origin']['type']);
    $this->assertSame('string', $definition['mapping']['scheme']['type']);
  }

  /**
   * Tests the save endpoint is protected from CSRFable GET requests.
   */
  public function testSaveRouteRequiresPostAndCsrfHeader(): void {
    $route_provider = $this->container->get('router.route_provider');
    foreach (['flysystem_gcs_cors.save', 'flysystem_gcs_cors.save_existing'] as $route_name) {
      $route = $route_provider->getRouteByName($route_name);

      $this->assertSame(['POST'], $route->getMethods());
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
    }
  }

  /**
   * Tests create routes omit entity IDs and update routes require numeric IDs.
   */
  public function testUploadRoutesSeparateCreateAndUpdateEntityIds(): void {
    $route_provider = $this->container->get('router.route_provider');

    $this->assertStringNotContainsString(
      '{entity_id}',
      $route_provider->getRouteByName('flysystem_gcs_cors.get')->getPath(),
    );
    $this->assertStringNotContainsString(
      '{entity_id}',
      $route_provider->getRouteByName('flysystem_gcs_cors.save')->getPath(),
    );

    foreach (['flysystem_gcs_cors.get_existing', 'flysystem_gcs_cors.save_existing'] as $route_name) {
      $route = $route_provider->getRouteByName($route_name);
      $this->assertStringContainsString('{entity_id}', $route->getPath());
      $this->assertSame('\d+', $route->getRequirement('entity_id'));
    }
  }

}
