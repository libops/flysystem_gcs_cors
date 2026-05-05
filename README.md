# flysystem_gcs_cors

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
