<?php

namespace Drupal\Tests\flysystem_gcs_cors\Unit\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\flysystem_gcs_cors\Controller\Gcs;
use Drupal\flysystem_gcs_cors\GcsBucketResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests controller access rules for GCS upload routes.
 */
#[CoversClass(Gcs::class)]
#[Group('flysystem_gcs_cors')]
class GcsAccessTest extends UnitTestCase {

  /**
   * Tests create access uses AccessResult objects correctly.
   */
  public function testCreateAccessDenied(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_extensions', 'txt pdf'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $access_handler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $access_handler->method('createAccess')
      ->with('article', $this->anything(), [], TRUE)
      ->willReturn(AccessResult::forbidden());
    $access_handler->expects($this->never())
      ->method('access');
    $access_handler->method('fieldAccess')
      ->with('edit', $field_definition, $this->anything(), NULL, TRUE)
      ->willReturn(AccessResult::allowed());

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getAccessControlHandler')
      ->with('node')
      ->willReturn($access_handler);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver);

    $result = $controller->access('node', 'article', 'null', 'field_upload', 0, 'report.txt');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests field and extension checks are enforced.
   */
  public function testDisallowedExtensionDenied(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_extensions', 'txt pdf'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $access_handler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $access_handler->method('createAccess')
      ->willReturn(AccessResult::allowed());
    $access_handler->method('fieldAccess')
      ->willReturn(AccessResult::allowed());

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getAccessControlHandler')
      ->with('node')
      ->willReturn($access_handler);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver);

    $result = $controller->access('node', 'article', 'null', 'field_upload', 0, 'image.exe');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests update access loads the entity and allows valid uploads.
   */
  public function testUpdateAccessAllowed(): void {
    $entity = $this->createMock(EntityInterface::class);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_extensions', 'txt pdf'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('42')
      ->willReturn($entity);

    $access_handler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $access_handler->method('access')
      ->with($entity, 'update', $this->anything(), TRUE)
      ->willReturn(AccessResult::allowed());
    $access_handler->method('fieldAccess')
      ->with('edit', $field_definition, $this->anything(), NULL, TRUE)
      ->willReturn(AccessResult::allowed());

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getAccessControlHandler')
      ->with('node')
      ->willReturn($access_handler);
    $entity_type_manager->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver);

    $result = $controller->access('node', 'article', '42', 'field_upload', 0, 'report.pdf');

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests non-GCS-backed fields are denied.
   */
  public function testInvalidSchemeDenied(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_extensions', 'txt pdf'],
        ['uri_scheme', 'public'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $access_handler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $access_handler->method('createAccess')
      ->willReturn(AccessResult::allowed());
    $access_handler->method('fieldAccess')
      ->willReturn(AccessResult::allowed());

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getAccessControlHandler')
      ->with('node')
      ->willReturn($access_handler);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('public')
      ->willReturn(FALSE);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver);

    $result = $controller->access('node', 'article', 'null', 'field_upload', 0, 'report.pdf');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Builds the controller with lightweight test doubles.
   */
  protected function buildController(EntityFieldManagerInterface $field_manager, EntityTypeManagerInterface $entity_type_manager, GcsBucketResolver $resolver): Gcs {
    $mime_type_guesser = $this->createMock('Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser');
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $token = $this->createMock(Token::class);
    $current_user = $this->createMock(AccountProxyInterface::class);

    return new Gcs(
      $mime_type_guesser,
      $field_manager,
      $entity_type_manager,
      $module_handler,
      $token,
      $current_user,
      $resolver,
    );
  }

}
