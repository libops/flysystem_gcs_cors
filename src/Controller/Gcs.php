<?php

namespace Drupal\flysystem_gcs_cors\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\flysystem_gcs_cors\GcsBucketResolver;
use Drupal\flysystem_gcs_cors\GcsUploadLimits;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * AJAX responses for module.
 */
class Gcs extends ControllerBase {

  /**
   * The mime type guesser service.
   *
   * @var \Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser
   */
  protected $mimeTypeGuesser;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The token service.
   *
   * @var \Drupal\token\Token
   */
  protected $token;

  /**
   * The current_user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The GCS bucket resolver service.
   *
   * @var \Drupal\flysystem_gcs_cors\GcsBucketResolver
   */
  protected $gcsBucketResolver;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The upload limits service.
   *
   * @var \Drupal\flysystem_gcs_cors\GcsUploadLimits
   */
  protected $uploadLimits;

  /**
   * The private key service.
   *
   * @var \Drupal\Core\PrivateKey
   */
  protected $privateKey;

  /**
   * {@inheritdoc}
   */
  public function __construct(MimeTypeGuesser $mime_type_guesser, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, Token $token, AccountProxyInterface $current_user, GcsBucketResolver $gcs_bucket_resolver, RequestStack $request_stack, GcsUploadLimits $upload_limits, PrivateKey $private_key) {
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->currentUser = $current_user;
    $this->gcsBucketResolver = $gcs_bucket_resolver;
    $this->requestStack = $request_stack;
    $this->uploadLimits = $upload_limits;
    $this->privateKey = $private_key;
  }

  /**
   * Constructs a new GcsController object.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file.mime_type.guesser'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('token'),
      $container->get('current_user'),
      $container->get('flysystem_gcs_cors.gcs_bucket_resolver'),
      $container->get('request_stack'),
      $container->get('flysystem_gcs_cors.upload_limits'),
      $container->get('private_key')
    );
  }

  /**
   * Generate a GCS signed URL to upload a file.
   */
  public function getSignedUrl($entity_type, $bundle, $field, $delta, $file_name, $entity_id = NULL) : JsonResponse {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($fields[$field])) {
      return new JsonResponse(['errmsg' => 'Invalid upload field.'], 400);
    }

    $file_directory_untokenized = $fields[$field]->getSetting('file_directory');
    $scheme = $fields[$field]->getSetting('uri_scheme');
    if (!$this->gcsBucketResolver->hasBucket($scheme)) {
      return new JsonResponse(['errmsg' => 'The upload field is not backed by a configured GCS scheme.'], 400);
    }

    $file_size = $this->requestStack->getCurrentRequest()->query->get('file_size');
    if ($file_size === NULL || !is_numeric($file_size) || (int) $file_size < 0) {
      return new JsonResponse(['errmsg' => 'Invalid upload size.'], 400);
    }
    if (!$this->isAllowedUploadSize($fields[$field], $file_size)) {
      return new JsonResponse(['errmsg' => 'The selected file exceeds the configured upload size limit.'], 400);
    }

    $object_name = $this->buildObjectName($file_directory_untokenized, $entity_type, $entity_id, $file_name);
    $validFor = new \DateTime('10 min');
    $expires = time() + 600;
    $response = $this->gcsBucketResolver->generateSignedPostPolicyV4(
      $scheme,
      $object_name,
      $validFor
    );
    $response['resumable_url'] = $this->gcsBucketResolver->generateSignedResumableUploadUrl(
      $scheme,
      $object_name,
      $validFor
    );
    $response['object_name'] = $object_name;
    $response['upload_token'] = $this->buildUploadToken($object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size, $expires);
    $response['upload_token_expires'] = $expires;

    return new JsonResponse($response);
  }

  /**
   * After the file is uploaded as a GCS object, save the file entity in Drupal.
   */
  public function saveFile($entity_type, $bundle, $field, $delta, $file_name, $file_size, $entity_id = NULL) : JsonResponse {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($fields[$field])) {
      return new JsonResponse(['errmsg' => 'Invalid upload field.'], 400);
    }

    $scheme = $fields[$field]->getSetting('uri_scheme');
    if (!$this->gcsBucketResolver->hasBucket($scheme)) {
      return new JsonResponse(['errmsg' => 'The upload field is not backed by a configured GCS scheme.'], 400);
    }

    if (!$this->isAllowedUploadSize($fields[$field], $file_size)) {
      return new JsonResponse(['errmsg' => 'The selected file exceeds the configured upload size limit.'], 400);
    }

    $object_name = $this->requestStack->getCurrentRequest()->request->get('object_name');
    $upload_token = $this->requestStack->getCurrentRequest()->request->get('upload_token');
    if (!$this->isValidUploadToken($upload_token, $object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size)) {
      return new JsonResponse(['errmsg' => 'Invalid upload token.'], 400);
    }
    if (!$this->isValidObjectName($object_name, $file_name)) {
      return new JsonResponse(['errmsg' => 'Invalid uploaded object name.'], 400);
    }
    $object_metadata = $this->gcsBucketResolver->getObjectMetadata($scheme, $object_name);
    if ($object_metadata === NULL) {
      return new JsonResponse(['errmsg' => 'The uploaded object was not found in GCS.'], 404);
    }
    if (!isset($object_metadata['size'])) {
      return new JsonResponse(['errmsg' => 'The uploaded object metadata did not include a size.'], 400);
    }
    if ((int) $object_metadata['size'] !== (int) $file_size) {
      return new JsonResponse(['errmsg' => 'The uploaded object size does not match the selected file.'], 400);
    }

    $file_mime = $object_metadata['contentType'] ?? $this->mimeTypeGuesser->guessMimeType($file_name);
    $values = [
      'uid' => $this->currentUser->id(),
      'status' => 0,
      'filename' => $file_name,
      'uri' => $scheme . '://' . $object_name,
      'filesize' => (int) $object_metadata['size'],
      'filemime' => $file_mime,
      'source' => $field,
    ];
    $file = File::create($values);

    $errors = [];
    $errors = array_merge($errors, $this->moduleHandler->invokeAll('file_validate', [$file]));

    if (empty($errors)) {
      $file->save();
      $values['fid'] = $file->id();
      $values['uuid'] = $file->uuid();
    }
    else {
      $values['errmsg'] = implode("\n", $errors);
    }

    return new JsonResponse($values);
  }

  /**
   * Custom access function for AJAX routes.
   */
  public function access($entity_type, $bundle, $field, $delta, $file_name, $entity_id = NULL, $file_size = FALSE) {
    $access_controller = $this->entityTypeManager->getAccessControlHandler($entity_type);
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($fields[$field])) {
      return AccessResult::forbidden();
    }

    $field_definition = $fields[$field];
    $scheme = $field_definition->getSetting('uri_scheme');

    // Make sure the account has access to edit or create the entity type.
    if ($entity_id !== NULL) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      $entity_access = $entity
        ? $access_controller->access($entity, 'update', $this->currentUser, TRUE)
        : AccessResult::forbidden();
    }
    else {
      $entity_access = $access_controller->createAccess($bundle, $this->currentUser, [], TRUE);
    }

    // Make sure the file extension is allowed.
    $extension = substr($file_name, strrpos($file_name, '.') + 1);
    $extensions = $field_definition->getSetting('file_extensions');
    $extensions = explode(' ', $extensions);

    return $entity_access
      ->andIf($access_controller->fieldAccess('edit', $field_definition, $this->currentUser, NULL, TRUE))
      ->andIf(AccessResult::allowedIf($this->gcsBucketResolver->hasBucket($scheme)))
      ->andIf(AccessResult::allowedIf(in_array($extension, $extensions, TRUE)));
  }

  /**
   * Helper function. Get the directory based on the entity ID.
   *
   * Contextualize the entity that is having a file uploaded
   *   on behalf of this module with that entity's metadata.
   */
  private function getDirectory($file_directory_untokenized, $entity_type, $entity_id) {
    $data = [];
    if ($entity_id !== NULL) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if ($entity) {
        $data[$entity_type] = $entity;
      }
    }
    return $this->token->replace($file_directory_untokenized, $data);
  }

  /**
   * Builds a unique object name for a pending browser upload.
   */
  private function buildObjectName($file_directory_untokenized, $entity_type, $entity_id, $file_name): string {
    $directory = trim($this->getDirectory($file_directory_untokenized, $entity_type, $entity_id), '/');
    $prefix = $directory === '' ? '' : $directory . '/';
    return $prefix . bin2hex(random_bytes(16)) . '-' . $file_name;
  }

  /**
   * Checks that the submitted object name is safe for the expected file.
   */
  private function isValidObjectName($object_name, $file_name): bool {
    if (!is_string($object_name) || $object_name === '') {
      return FALSE;
    }
    return str_ends_with($object_name, '-' . $file_name)
      && !in_array('..', explode('/', $object_name), TRUE);
  }

  /**
   * Checks the selected file size against module and field limits.
   */
  private function isAllowedUploadSize($field_definition, $file_size): bool {
    if (!is_numeric($file_size) || (int) $file_size < 0) {
      return FALSE;
    }
    $max_upload_size = GcsUploadLimits::applyFieldMaxFilesizeSetting(
      $this->uploadLimits->getConfiguredMaxUploadSize(),
      $field_definition->getSetting('max_filesize')
    );
    return (int) $file_size <= $max_upload_size;
  }

  /**
   * Builds an upload token for the exact object and upload context.
   */
  private function buildUploadToken($object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size, int $expires): string {
    $payload = $this->buildUploadTokenPayload($object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size, $expires);
    return $expires . ':' . hash_hmac('sha256', $payload, $this->privateKey->get());
  }

  /**
   * Validates an upload token.
   */
  private function isValidUploadToken($upload_token, $object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size): bool {
    if (!is_string($upload_token) || !preg_match('/^(\d+):([a-f0-9]{64})$/', $upload_token, $matches)) {
      return FALSE;
    }
    $expires = (int) $matches[1];
    if ($expires < time()) {
      return FALSE;
    }
    $expected = $this->buildUploadToken($object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size, $expires);
    return hash_equals($expected, $upload_token);
  }

  /**
   * Builds the stable upload token payload.
   */
  private function buildUploadTokenPayload($object_name, $entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size, int $expires): string {
    return json_encode([
      'object_name' => $object_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'entity_id' => $entity_id,
      'field' => $field,
      'delta' => (string) $delta,
      'file_name' => $file_name,
      'file_size' => (string) $file_size,
      'uid' => (string) $this->currentUser->id(),
      'expires' => (string) $expires,
    ]);
  }

}
