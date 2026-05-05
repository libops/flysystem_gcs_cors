<?php

namespace Drupal\flysystem_gcs_cors;

use Drupal\Core\Site\Settings;
use Google\Cloud\Storage\StorageClient;

/**
 * Resolves configured Flysystem GCS buckets by scheme.
 */
class GcsBucketResolver {

  /**
   * Returns the configured GCS bucket labels keyed by Flysystem scheme.
   *
   * @return string[]
   *   An array of human-readable labels keyed by scheme.
   */
  public function getSchemeOptions(): array {
    $options = [];
    foreach ($this->getFlysystemSettings() as $scheme => $settings) {
      if ($this->isValidGcsConfiguration($settings)) {
        $options[$scheme] = $scheme . ':// -> ' . $settings['config']['bucket'];
      }
    }
    return $options;
  }

  /**
   * Checks whether the scheme resolves to a configured GCS backend.
   */
  public function hasBucket(string $scheme): bool {
    return $this->getBucketConfig($scheme) !== NULL;
  }

  /**
   * Returns the configured bucket name for a scheme.
   */
  public function getBucketName(string $scheme): string {
    $config = $this->getBucketConfig($scheme);
    if ($config === NULL) {
      throw new \InvalidArgumentException(sprintf('The "%s" scheme is not configured as a GCS Flysystem backend.', $scheme));
    }
    return $config['bucket'];
  }

  /**
   * Generates signed POST policy data for an object upload.
   *
   * @return array
   *   The signed POST policy response structure.
   */
  public function generateSignedPostPolicyV4(string $scheme, string $object_name, \DateTimeInterface $valid_for): array {
    $bucket = $this->buildBucket($scheme);
    return $bucket->generateSignedPostPolicyV4($object_name, $valid_for);
  }

  /**
   * Generates a signed URL that can initiate a resumable upload session.
   */
  public function generateSignedResumableUploadUrl(string $scheme, string $object_name, \DateTimeInterface $valid_for): string {
    $bucket = $this->buildBucket($scheme);
    return $bucket->object($object_name)->signedUploadUrl($valid_for, [
      'version' => 'v4',
    ]);
  }

  /**
   * Returns metadata for an uploaded object, or NULL if it does not exist.
   */
  public function getObjectMetadata(string $scheme, string $object_name): ?array {
    $bucket = $this->buildBucket($scheme);
    $object = $bucket->object($object_name);
    if (!$object->exists()) {
      return NULL;
    }
    return $object->info();
  }

  /**
   * Updates the configured bucket CORS policy for a scheme.
   */
  public function updateBucketCors(string $scheme, array $cors): void {
    $bucket = $this->buildBucket($scheme);
    $bucket->update([
      'cors' => $cors,
    ]);
  }

  /**
   * Builds a Google Cloud Storage bucket client for a scheme.
   */
  protected function buildBucket(string $scheme) {
    $config = $this->getBucketConfig($scheme);
    if ($config === NULL) {
      throw new \InvalidArgumentException(sprintf('The "%s" scheme is not configured as a GCS Flysystem backend.', $scheme));
    }

    $storage = new StorageClient($config);
    return $storage->bucket($config['bucket']);
  }

  /**
   * Returns the GCS config array for a scheme when valid.
   */
  protected function getBucketConfig(string $scheme): ?array {
    $settings = $this->getFlysystemSettings();
    if (!isset($settings[$scheme]) || !$this->isValidGcsConfiguration($settings[$scheme])) {
      return NULL;
    }
    return $settings[$scheme]['config'];
  }

  /**
   * Returns Flysystem settings from Drupal settings.php.
   */
  protected function getFlysystemSettings(): array {
    return Settings::get('flysystem', []);
  }

  /**
   * Validates the minimum configuration required for a GCS backend.
   */
  protected function isValidGcsConfiguration(array $settings): bool {
    return ($settings['driver'] ?? NULL) === 'gcs'
      && !empty($settings['config'])
      && !empty($settings['config']['bucket']);
  }

}
