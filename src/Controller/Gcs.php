<?php

namespace Drupal\flysystem_gcs_cors\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\token\TokenEntityMapperInterface;
use Google\Cloud\Storage\StorageClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\token\Token;
use Symfony\Component\HttpFoundation\JsonResponse;

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
   * {@inheritdoc}
   */
  public function __construct(MimeTypeGuesser $mime_type_guesser, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, Token $token, AccountProxyInterface $current_user) {
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->currentUser = $current_user;
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
      $container->get('current_user')
    );
  }

  /**
   * Generate a GCS signed URL to upload a file.
   */
  public function getSignedUrl($entity_type, $bundle, $entity_id, $field, $delta, $file_name) : JsonResponse {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $file_directory_untokenized = $fields[$field]->getSetting('file_directory');
    $scheme = $fields[$field]->getSetting('uri_scheme');
    $flysystem_settings = Settings::get('flysystem', []);
    $config = $flysystem_settings[$scheme]['config'];
    $bucket_name = $config['bucket'];

    $storage = new StorageClient($config);
    $bucket = $storage->bucket($bucket_name);

    $validFor = new \DateTime('10 min');
    $response = $bucket->generateSignedPostPolicyV4(
      $this->getDirectory($file_directory_untokenized, $entity_type, $entity_id) . '/' . $file_name,
      $validFor
    );

    return new JsonResponse($response);
  }

  /**
   * After the file is uploaded as a GCS object, save the file entity in Drupal.
   */
  public function saveFile($entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size) : JsonResponse {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    $file_directory_untokenized = $fields[$field]->getSetting('file_directory');

    $file_mime = $this->mimeTypeGuesser->guessMimeType($file_name);
    $values = [
      'uid' => $this->currentUser->id(),
      'status' => 0,
      'filename' => $file_name,
      'uri' => $fields[$field]->getSetting('uri_scheme') . '://' . $this->getDirectory($file_directory_untokenized, $entity_type, $entity_id) . '/' . $file_name,
      'filesize' => $file_size,
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
      $file->delete();
      $values['errmsg'] = implode("\n", $errors);
    }

    return new JsonResponse($values);
  }

  /**
   * Custom access function for AJAX routes.
   */
  public function access($entity_type, $bundle, $entity_id, $field, $delta, $file_name, $file_size = FALSE) {
    $access_controller = $this->entityTypeManager->getAccessControlHandler($entity_type);
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    // Make sure the account has access to edit or create the entity type.
    if ($entity_id !== "null") {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      $has_access = $entity && $access_controller->access($entity, 'update');
    }
    else {
      $has_access = $access_controller->createAccess($bundle);
    }

    // Make sure the file extension is allowed.
    $extension = substr($file_name, strrpos($file_name, '.') + 1);
    $extensions = $fields[$field]->getSetting('file_extensions');
    $extensions = explode(' ', $extensions);

    return AccessResult::allowedIf($has_access &&
      isset($fields[$field]) &&
      $access_controller->fieldAccess('edit', $fields[$field]) &&
      in_array($extension, $extensions));
  }

  /**
   * Helper function. Get the directory based on the entity ID.
   *
   * Contextualize the entity that is having a file uploaded
   *   on behalf of this module with that entity's metadata.
   */
  private function getDirectory($file_directory_untokenized, $entity_type, $entity_id) {
    $data = [];
    if ($entity_id !== "null") {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if ($entity) {
        $data[$entity_type] = $entity;
      }
    }
    return $this->token->replace($file_directory_untokenized, $data);
  }

}
