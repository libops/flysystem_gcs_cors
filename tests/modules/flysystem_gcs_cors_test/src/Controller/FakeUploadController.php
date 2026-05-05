<?php

namespace Drupal\flysystem_gcs_cors_test\Controller;

use Drupal\Core\Url;
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

  /**
   * Starts a fake resumable upload session.
   */
  public function startResumableUpload(Request $request): Response {
    $key = $request->query->get('key');
    if (!$key || $request->headers->get('x-goog-resumable') !== 'start') {
      return new Response('', 400);
    }

    $upload_id = bin2hex(random_bytes(8));
    $sessions = \Drupal::state()->get('flysystem_gcs_cors_test.resumable_sessions', []);
    $sessions[$upload_id] = [
      'key' => $key,
      'contentType' => $request->headers->get('Content-Type') ?: 'application/octet-stream',
      'chunks' => [],
    ];
    \Drupal::state()->set('flysystem_gcs_cors_test.resumable_sessions', $sessions);

    return new Response('', 201, [
      'Location' => Url::fromRoute('flysystem_gcs_cors_test.fake_resumable_chunk', [
        'upload_id' => $upload_id,
      ], ['absolute' => TRUE])->toString(),
    ]);
  }

  /**
   * Accepts one fake resumable upload chunk.
   */
  public function uploadResumableChunk(Request $request, string $upload_id): Response {
    $sessions = \Drupal::state()->get('flysystem_gcs_cors_test.resumable_sessions', []);
    if (!isset($sessions[$upload_id])) {
      return new Response('', 404);
    }

    $content_range = $request->headers->get('Content-Range');
    if (!preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', (string) $content_range, $matches)) {
      return new Response('', 400);
    }

    $start = (int) $matches[1];
    $end = (int) $matches[2];
    $total = (int) $matches[3];
    if (strlen($request->getContent()) !== ($end - $start + 1)) {
      return new Response('', 400);
    }

    $session = $sessions[$upload_id];
    $session['chunks'][] = [
      'start' => $start,
      'end' => $end,
    ];

    if ($end + 1 < $total) {
      $sessions[$upload_id] = $session;
      \Drupal::state()->set('flysystem_gcs_cors_test.resumable_sessions', $sessions);
      return new Response('', 308, [
        'Range' => 'bytes=0-' . $end,
      ]);
    }

    $objects = \Drupal::state()->get('flysystem_gcs_cors_test.objects', []);
    $objects[$session['key']] = [
      'size' => $total,
      'contentType' => $session['contentType'],
    ];
    \Drupal::state()->set('flysystem_gcs_cors_test.objects', $objects);

    $resumable_objects = \Drupal::state()->get('flysystem_gcs_cors_test.resumable_objects', []);
    $resumable_objects[$session['key']] = [
      'size' => $total,
      'chunkCount' => count($session['chunks']),
      'chunks' => $session['chunks'],
    ];
    \Drupal::state()->set('flysystem_gcs_cors_test.resumable_objects', $resumable_objects);

    unset($sessions[$upload_id]);
    \Drupal::state()->set('flysystem_gcs_cors_test.resumable_sessions', $sessions);

    return new Response('', 201);
  }

}
