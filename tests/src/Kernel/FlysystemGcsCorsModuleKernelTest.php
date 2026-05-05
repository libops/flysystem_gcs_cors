<?php

namespace Drupal\Tests\flysystem_gcs_cors\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies Drupal discovers the module's field type overrides.
 */
#[CoversNothing]
#[Group('flysystem_gcs_cors')]
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

}
