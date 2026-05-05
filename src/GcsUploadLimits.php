<?php

namespace Drupal\flysystem_gcs_cors;

use Drupal\Component\Utility\Bytes;

/**
 * Shared upload size limits for direct GCS uploads.
 */
class GcsUploadLimits {

  /**
   * The default maximum upload size for GCS-backed fields.
   */
  public const DEFAULT_MAX_UPLOAD_SIZE = '10 GB';

  /**
   * The maximum object size supported by Google Cloud Storage, in bytes.
   */
  public const GCS_MAX_UPLOAD_SIZE = 5 * 1024 * 1024 * 1024 * 1024;

  /**
   * Returns the configured module-wide maximum upload size.
   */
  public static function getConfiguredMaxUploadSize(): int {
    try {
      $configured = \Drupal::config('flysystem_gcs_cors.admin')->get('max_upload_size') ?: self::DEFAULT_MAX_UPLOAD_SIZE;
    }
    catch (\Throwable) {
      $configured = self::DEFAULT_MAX_UPLOAD_SIZE;
    }
    $bytes = Bytes::toNumber($configured);
    if ($bytes <= 0) {
      $bytes = Bytes::toNumber(self::DEFAULT_MAX_UPLOAD_SIZE);
    }
    return min($bytes, self::GCS_MAX_UPLOAD_SIZE);
  }

  /**
   * Applies a field's max_filesize setting to an existing byte limit.
   */
  public static function applyFieldMaxFilesizeSetting(int $max_filesize, ?string $field_max_filesize): int {
    if (!empty($field_max_filesize)) {
      $max_filesize = min($max_filesize, Bytes::toNumber($field_max_filesize));
    }
    return $max_filesize;
  }

}
