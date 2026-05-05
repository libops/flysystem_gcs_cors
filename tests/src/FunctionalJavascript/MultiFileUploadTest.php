<?php

namespace Drupal\Tests\flysystem_gcs_cors\FunctionalJavascript;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * Tests multi-file browser uploads with a fake GCS backend.
 */
#[CoversNothing]
#[Group('flysystem_gcs_cors')]
#[IgnoreDeprecations]
class MultiFileUploadTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'file',
    'token',
    'flysystem_gcs_cors',
    'flysystem_gcs_cors_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user who can create test content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $file_system = \Drupal::service('file_system');
    $public_directory = 'public://';
    $file_system->prepareDirectory(
      $public_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    $upload_directory = 'public://browser-test';
    $file_system->prepareDirectory(
      $upload_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    \Drupal::service('file.htaccess_writer')->write('public://', FALSE);

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_upload',
      'entity_type' => 'node',
      'type' => 'file',
      'cardinality' => 2,
      'settings' => [
        'target_type' => 'file',
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_upload',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Upload',
      'settings' => [
        'file_directory' => 'browser-test',
        'file_extensions' => 'txt',
        'uri_scheme' => 'public',
      ],
    ])->save();

    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article', 'default')
      ->setComponent('field_upload', [
        'type' => 'flysystem_gcs_cors_file_widget',
      ])
      ->save();

    $this->author = $this->drupalCreateUser([
      'access content',
      'create article content',
    ]);
    $this->drupalLogin($this->author);
  }

  /**
   * Fails when the browser recorded JavaScript console errors.
   */
  #[After]
  protected function failOnJavaScriptErrors(): void {
    if ($this->failOnJavascriptConsoleErrors) {
      try {
        $errors = $this->getSession()
          ->evaluateScript("JSON.parse(sessionStorage.getItem('js_testing_log_test.errors') || JSON.stringify([]))");
      }
      catch (\Exception) {
        return;
      }
      if (!empty($errors)) {
        $this->fail(implode("\n", $errors));
      }
    }
  }

  /**
   * Tests two selected files survive the asynchronous upload flow.
   */
  #[WithoutErrorHandler]
  public function testMultipleFilesUploadDistinctly(): void {
    $this->drupalGet('node/add/article');

    $first_path = $this->createTemporaryFile('first-browser-upload');
    $second_path = $this->createTemporaryFile('second-browser-upload');

    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $remote_paths = [
      $driver->uploadFileAndGetRemoteFilePath($first_path),
      $driver->uploadFileAndGetRemoteFilePath($second_path),
    ];

    $input = $this->getSession()->getPage()->find('css', 'input[type="file"]');
    $this->assertNotNull($input);
    $target_id = $input->getAttribute('id');
    $status_id = preg_replace('/--.*$/', '', $target_id);
    $this->getSession()->executeScript(sprintf(
      'window.__gcsCorsUploadChangeSeen = false; const input = document.getElementById(%s); if (input) { input.addEventListener("change", () => { window.__gcsCorsUploadChangeSeen = true; }, { once: true }); }',
      json_encode($target_id)
    ));
    $input->setValue(implode("\n", $remote_paths));
    $change_seen = $this->getSession()->wait(1000, sprintf(
      'window.__gcsCorsUploadChangeSeen === true || (window.Drupal && Drupal.gcsCors && Drupal.gcsCors.uploadStatus && Drupal.gcsCors.uploadStatus[%s])',
      json_encode($status_id)
    ));
    if (!$change_seen) {
      $this->getSession()->executeScript(sprintf(
        'const input = document.getElementById(%s); if (input) { input.dispatchEvent(new Event("change", { bubbles: true })); }',
        json_encode($target_id)
      ));
    }

    $uploads_saved = $this->getSession()->wait(10000, sprintf(
      'window.Drupal && Drupal.gcsCors && Drupal.gcsCors.uploadStatus && Drupal.gcsCors.uploadStatus[%s] && Drupal.gcsCors.uploadStatus[%s].fids.length === 2',
      json_encode($status_id),
      json_encode($status_id)
    ));
    if (!$uploads_saved) {
      $upload_status = $this->getSession()->evaluateScript(sprintf(
        'window.Drupal && Drupal.gcsCors && Drupal.gcsCors.uploadStatus ? Drupal.gcsCors.uploadStatus[%s] : null',
        json_encode($status_id)
      ));
      $this->fail('Timed out waiting for both GCS CORS uploads to be saved. Upload status: ' . json_encode($upload_status));
    }
    $this->assertTrue(
      $this->getSession()->wait(10000, '!document.querySelector(\'.loader:not(.js-hide)\')'),
      'Timed out waiting for the upload widget AJAX refresh to complete.'
    );

    $uploaded_fids = $this->getSession()->evaluateScript(sprintf(
      'Drupal.gcsCors.uploadStatus[%s].fids',
      json_encode($status_id)
    ));
    $this->assertCount(2, $uploaded_fids);
    foreach ($uploaded_fids as $uploaded_fid) {
      $this->assertMatchesRegularExpression('/^\d+$/', (string) $uploaded_fid);
    }

    $storage = \Drupal::entityTypeManager()->getStorage('file');
    $query = \Drupal::entityQuery('file')
      ->accessCheck(FALSE)
      ->condition('filename', [basename($first_path), basename($second_path)], 'IN');
    $files = $storage->loadMultiple($query->execute());

    $filenames = array_map(static fn (File $file) => $file->getFilename(), $files);
    $this->assertCount(2, $filenames);
    $this->assertEqualsCanonicalizing([basename($first_path), basename($second_path)], $filenames);
  }

  /**
   * Creates a local temporary file for browser upload.
   */
  protected function createTemporaryFile(string $prefix): string {
    $path = tempnam(sys_get_temp_dir(), $prefix);
    $txt_path = $path . '.txt';
    rename($path, $txt_path);
    file_put_contents($txt_path, $prefix . ' content');
    return $txt_path;
  }

}
