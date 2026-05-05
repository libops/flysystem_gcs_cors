<?php

namespace Drupal\flysystem_gcs_cors_test\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts fake uploads during browser tests.
 */
class FakeUploadController {

  /**
   * Returns a successful upload response.
   */
  public function upload(Request $request): Response {
    $key = $request->request->get('key');
    $file = $request->files->get('file');
    if ($key && $file) {
      $objects = \Drupal::state()->get('flysystem_gcs_cors_test.objects', []);
      $objects[$key] = [
        'size' => $file->getSize(),
        'contentType' => $file->getMimeType() ?: 'application/octet-stream',
      ];
      \Drupal::state()->set('flysystem_gcs_cors_test.objects', $objects);
    }
    return new Response('', 204);
  }

}
