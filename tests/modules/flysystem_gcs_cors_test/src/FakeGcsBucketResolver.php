<?php

namespace Drupal\flysystem_gcs_cors_test;

use Drupal\Core\Url;
use Drupal\flysystem_gcs_cors\GcsBucketResolver;

/**
 * Replaces GCS interactions with local test endpoints.
 */
class FakeGcsBucketResolver extends GcsBucketResolver {

  /**
   * {@inheritdoc}
   */
  public function getSchemeOptions(): array {
    return [
      'public' => 'public:// -> Test upload bucket',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function hasBucket(string $scheme): bool {
    return $scheme === 'public';
  }

  /**
   * {@inheritdoc}
   */
  public function generateSignedPostPolicyV4(string $scheme, string $object_name, \DateTimeInterface $valid_for): array {
    return [
      'url' => Url::fromRoute('flysystem_gcs_cors_test.fake_upload', [], ['absolute' => TRUE])->toString(),
      'fields' => [
        'key' => $object_name,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function updateBucketCors(string $scheme, array $cors): void {
  }

}
