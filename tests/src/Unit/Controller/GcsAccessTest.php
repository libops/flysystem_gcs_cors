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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

    $result = $controller->access('node', 'article', 'field_upload', 0, 'report.txt');

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

    $result = $controller->access('node', 'article', 'field_upload', 0, 'image.exe');

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

    $result = $controller->access('node', 'article', 'field_upload', 0, 'report.pdf', '42');

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

    $result = $controller->access('node', 'article', 'field_upload', 0, 'report.pdf');

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests duplicate filenames receive distinct GCS object names.
   */
  public function testSignedUrlsUseUniqueObjectNamesForDuplicateFilenames(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_directory', 'browser-test'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);
    $resolver->method('generateSignedPostPolicyV4')
      ->willReturnCallback(static fn (string $scheme, string $object_name, \DateTimeInterface $valid_for): array => [
        'url' => 'https://uploads.example.test',
        'fields' => [
          'key' => $object_name,
        ],
      ]);
    $resolver->method('generateSignedResumableUploadUrl')
      ->willReturnCallback(static fn (string $scheme, string $object_name, \DateTimeInterface $valid_for): string => 'https://uploads.example.test/resumable/' . rawurlencode($object_name));

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver);

    $first = json_decode($controller->getSignedUrl('node', 'article', 'field_upload', 0, 'report.txt')->getContent(), TRUE);
    $second = json_decode($controller->getSignedUrl('node', 'article', 'field_upload', 1, 'report.txt')->getContent(), TRUE);

    $this->assertNotSame($first['object_name'], $second['object_name']);
    $this->assertStringStartsWith('browser-test/', $first['object_name']);
    $this->assertStringEndsWith('-report.txt', $first['object_name']);
    $this->assertSame($first['object_name'], $first['fields']['key']);
    $this->assertSame($second['object_name'], $second['fields']['key']);
    $this->assertStringContainsString(rawurlencode($first['object_name']), $first['resumable_url']);
  }

  /**
   * Tests signed upload URLs are not issued above the configured size limit.
   */
  public function testSignedUrlRejectsOversizeFile(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_directory', 'browser-test'],
        ['uri_scheme', 'gcs'],
        ['max_filesize', NULL],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);
    $resolver->expects($this->never())
      ->method('generateSignedPostPolicyV4');
    $resolver->expects($this->never())
      ->method('generateSignedResumableUploadUrl');

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver, NULL, [
      'file_size' => 11 * 1024 * 1024 * 1024,
    ]);

    $response = $controller->getSignedUrl('node', 'article', 'field_upload', 0, 'report.txt');
    $payload = json_decode($response->getContent(), TRUE);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('The selected file exceeds the configured upload size limit.', $payload['errmsg']);
  }

  /**
   * Tests file entities are not saved unless the GCS object exists.
   */
  public function testSaveFileRejectsMissingGcsObject(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_directory', 'browser-test'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);
    $resolver->expects($this->once())
      ->method('getObjectMetadata')
      ->with('gcs', 'browser-test/abc123-report.txt')
      ->willReturn(NULL);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver, 'browser-test/abc123-report.txt');

    $response = $controller->saveFile('node', 'article', 'field_upload', 0, 'report.txt', 123);
    $payload = json_decode($response->getContent(), TRUE);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('The uploaded object was not found in GCS.', $payload['errmsg']);
  }

  /**
   * Tests file entities are not saved when client size differs from GCS.
   */
  public function testSaveFileRejectsMismatchedObjectSize(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_directory', 'browser-test'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);
    $resolver->expects($this->once())
      ->method('getObjectMetadata')
      ->with('gcs', 'browser-test/abc123-report.txt')
      ->willReturn([
        'size' => 321,
        'contentType' => 'text/plain',
      ]);

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver, 'browser-test/abc123-report.txt');

    $response = $controller->saveFile('node', 'article', 'field_upload', 0, 'report.txt', 123);
    $payload = json_decode($response->getContent(), TRUE);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('The uploaded object size does not match the selected file.', $payload['errmsg']);
  }

  /**
   * Tests file entities are not saved for unrelated objects in the directory.
   */
  public function testSaveFileRejectsObjectNameForDifferentFilename(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->willReturnMap([
        ['file_directory', 'browser-test'],
        ['uri_scheme', 'gcs'],
      ]);

    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')
      ->with('node', 'article')
      ->willReturn([
        'field_upload' => $field_definition,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $resolver = $this->createMock(GcsBucketResolver::class);
    $resolver->method('hasBucket')
      ->with('gcs')
      ->willReturn(TRUE);
    $resolver->expects($this->never())
      ->method('getObjectMetadata');

    $controller = $this->buildController($field_manager, $entity_type_manager, $resolver, 'browser-test/abc123-other.txt');

    $response = $controller->saveFile('node', 'article', 'field_upload', 0, 'report.txt', 123);
    $payload = json_decode($response->getContent(), TRUE);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('Invalid uploaded object name.', $payload['errmsg']);
  }

  /**
   * Builds the controller with lightweight test doubles.
   */
  protected function buildController(EntityFieldManagerInterface $field_manager, EntityTypeManagerInterface $entity_type_manager, GcsBucketResolver $resolver, ?string $object_name = NULL, array $query = []): Gcs {
    $mime_type_guesser = $this->createMock('Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser');
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $token = $this->createMock(Token::class);
    $token->method('replace')
      ->willReturnArgument(0);
    $current_user = $this->createMock(AccountProxyInterface::class);
    $request_stack = new RequestStack();
    $uri = empty($query) ? '/' : '/?' . http_build_query($query);
    $request_stack->push(Request::create($uri, $object_name === NULL ? 'GET' : 'POST', $object_name === NULL ? [] : [
      'object_name' => $object_name,
    ]));

    return new Gcs(
      $mime_type_guesser,
      $field_manager,
      $entity_type_manager,
      $module_handler,
      $token,
      $current_user,
      $resolver,
      $request_stack,
    );
  }

}
