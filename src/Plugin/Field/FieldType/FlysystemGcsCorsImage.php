<?php

namespace Drupal\flysystem_gcs_cors\Plugin\Field\FieldType;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Site\Settings;
use Drupal\image\Plugin\Field\FieldType\ImageItem;

/**
 * Extend the 'image' field type, overriding the max upload size.
 *
 * @FieldType(
 *   id = "flysystem_gcs_cors_image",
 *   label = @Translation("Image"),
 *   description = @Translation("This field stores the ID of an image file as an integer value."),
 *   category = "Reference",
 *   default_widget = "flysystem_gcs_cors_image_widget",
 *   default_formatter = "image",
 *   column_groups = {
 *     "file" = {
 *       "label" = @Translation("File"),
 *       "columns" = {
 *         "target_id", "width", "height"
 *       },
 *       "require_all_groups_for_translation" = TRUE
 *     },
 *     "alt" = {
 *       "label" = @Translation("Alt"),
 *       "translatable" = TRUE
 *     },
 *     "title" = {
 *       "label" = @Translation("Title"),
 *       "translatable" = TRUE
 *     },
 *   },
 *   list_class = "\Drupal\file\Plugin\Field\FieldType\FileFieldItemList",
 *   constraints = {"ReferenceAccess" = {}, "FileValidation" = {}}
 * )
 */
class FlysystemGcsCorsImage extends ImageItem {

  /**
   * {@inheritdoc}
   */
  public function getUploadValidators() {
    $validators = [];
    $settings = $this->getSettings();
    $scheme = $settings['uri_scheme'];
    $flysystem_settings = Settings::get('flysystem', []);

    // Cap the upload size according to the PHP limit.
    $max_filesize = Bytes::toNumber(Environment::getUploadMaxSize());

    // If this field is using GCS, up the max upload size.
    if (isset($flysystem_settings[$scheme]) && $flysystem_settings[$scheme]['driver'] == 'gcs') {
      $max_filesize = Bytes::toNumber('5 GB');
    }

    if (!empty($settings['max_filesize'])) {
      $max_filesize = min($max_filesize, Bytes::toNumber($settings['max_filesize']));
    }

    // There is always a file size limit.
    $validators['FileSizeLimit'] = ['fileLimit' => $max_filesize];

    // Add the extension check if necessary.
    if (!empty($settings['file_extensions'])) {
      $validators['FileExtension'] = ['extensions' => $settings['file_extensions']];
    }

    return $validators;
  }

}
