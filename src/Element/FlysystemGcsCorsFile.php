<?php

namespace Drupal\flysystem_gcs_cors\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Element\ManagedFile;

/**
 * Provides an GCS Cors File Element.
 *
 * @FormElement("flysystem_gcs_cors_file")
 */
class FlysystemGcsCorsFile extends ManagedFile {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    $class = get_class($this);
    $info['#process'] = [
      [$class, 'processManagedFile'],
    ];
    $info['#attached'] = [
      'library' => [
        'flysystem_gcs_cors/upload',
      ],
    ];
    $info['loader'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'loader',
          'js-hide',
        ],
        'aria-live' => 'assertive',
      ],
      'info' => [
        '#markup' => '<span>File is uploading...</span>',
      ],
    ];

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public static function processManagedFile(&$element, FormStateInterface $form_state, &$complete_form) {
    $element = parent::processManagedFile($element, $form_state, $complete_form);

    $element['upload']['#attributes'] = ['class' => ['gcs-cors-upload']];

    $js_settings = [
      'entity_type' => $form_state->getformObject()->getEntity()->getEntityType()->id(),
      'bundle' => $form_state->getformObject()->getEntity()->bundle(),
      'entity_id' => $form_state->getformObject()->getEntity()->id(),
    ];

    $element_parents = $element['#array_parents'];
    $field_name = $element['#field_name'];
    $js_settings['element_parents'] = implode('/', $element_parents);
    $element['upload']['#attached']['drupalSettings']['gcs_flysystem_cors'][$field_name] = $js_settings;

    return $element;
  }

}
