# flysystem_gcs_cors

## Field type behavior

This module intentionally alters Drupal's core `file` and `image` field type
definitions when it is enabled. The altered classes keep the core storage shape
and field semantics, but adjust upload validation for fields whose `uri_scheme`
is backed by a Flysystem GCS configuration. Those GCS-backed fields can accept
uploads larger than PHP's normal upload limit because the browser uploads the
object directly to GCS instead of streaming the file through PHP.

The behavior is site-wide because Drupal upload validators are resolved from the
field item class, not only from the selected widget. Non-GCS `file` and `image`
fields still use PHP's upload limit. GCS-backed fields still respect a field's
configured `max_filesize` when one is set.

Site builders should enable this module only when the site expects core file or
image fields that use a GCS-backed Flysystem scheme to participate in this
direct-upload behavior. Custom field type replacements or other modules that
also alter the core `file` or `image` field classes can conflict with this
module and should be reviewed before enabling both modules together.

## Testing locally

The CI tests that run on pull requests in GitHub leverage the docker images built in [islandora/islandora_ci](https://github.com/Islandora/islandora_ci). The CI tests a wide range of PHP and Drupal version combinations. You can run these same tests locally if you have docker installed.

Run the default suite with:

```bash
make test
```

Override the Drupal version, PHP version, or PHPUnit test suite as needed:

```bash
make test DRUPAL_VERSION=11.3 PHP_VERSION=8.3
make test TEST_SUITE=FunctionalJavascript
```

The `test` target will:

- create the `ci-default` Docker network if needed
- start a `chromedriver` container if one is not already running
- run the Islandora CI container with this module mounted read-only

Stop the background chromedriver container with:

```bash
make test-stop
```
