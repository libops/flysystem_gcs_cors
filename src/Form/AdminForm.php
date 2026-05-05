<?php

namespace Drupal\flysystem_gcs_cors\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\flysystem_gcs_cors\GcsBucketResolver;
use Drupal\flysystem_gcs_cors\GcsUploadLimits;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form.
 */
class AdminForm extends ConfigFormBase {

  /**
   * The GCS bucket resolver service.
   *
   * @var \Drupal\flysystem_gcs_cors\GcsBucketResolver
   */
  protected $gcsBucketResolver;

  /**
   * Constructs the admin form.
   */
  public function __construct(GcsBucketResolver $gcs_bucket_resolver) {
    $this->gcsBucketResolver = $gcs_bucket_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('flysystem_gcs_cors.gcs_bucket_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'flysystem_gcs_cors.admin',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'flysystem_gcs_cors_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('flysystem_gcs_cors.admin');
    $form['origin'] = [
      '#type' => 'url',
      '#title' => $this->t('Origin'),
      '#description' => $this->t('The origin that will be allowed to POST and PUT to your GCS bucket.'),
      '#default_value' => $config->get('origin'),
    ];

    $options = $this->gcsBucketResolver->getSchemeOptions();

    $form['scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Bucket'),
      '#description' => $this->t('Select which Flysystem GCS bucket to set CORS policy on.'),
      '#options' => $options,
      '#default_value' => $config->get('scheme'),
      '#required' => TRUE,
    ];

    $form['max_upload_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum upload size'),
      '#description' => $this->t('The largest file this module will allow for GCS-backed fields. Use values such as 10 GB or 500 MB. The hard maximum is 5 TiB.'),
      '#default_value' => $config->get('max_upload_size') ?: GcsUploadLimits::DEFAULT_MAX_UPLOAD_SIZE,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $max_upload_size = trim((string) $form_state->getValue('max_upload_size'));
    $max_upload_size_bytes = Bytes::toNumber($max_upload_size);
    if ($max_upload_size_bytes <= 0) {
      $form_state->setErrorByName('max_upload_size', $this->t('Enter a maximum upload size greater than zero.'));
    }
    elseif ($max_upload_size_bytes > GcsUploadLimits::GCS_MAX_UPLOAD_SIZE) {
      $form_state->setErrorByName('max_upload_size', $this->t('The maximum upload size cannot exceed 5 TiB.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $origin = $form_state->getValue('origin');
    $scheme = $form_state->getValue('scheme');
    $max_upload_size = trim((string) $form_state->getValue('max_upload_size'));

    $this->config('flysystem_gcs_cors.admin')
      ->set('origin', $origin)
      ->set('scheme', $scheme)
      ->set('max_upload_size', $max_upload_size)
      ->save();

    if (empty($origin)) {
      $cors = [];
    }
    else {
      $cors = [[
        'method' => ["POST", "PUT"],
        'origin' => [$origin],
        'responseHeader' => [
          'Content-Type',
          'Access-Control-Allow-Origin',
          'Location',
          'Range',
        ],
        'maxAgeSeconds' => 3600,
      ],
      ];
    }
    $this->gcsBucketResolver->updateBucketCors($scheme, $cors);
  }

}
