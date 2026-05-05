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
    return new Response('', 204);
  }

}
