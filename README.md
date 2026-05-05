# flysystem_gcs_cors

## Testing locally

The CI tests that run on pull requests in GitHub leverage the docker images built in [islandora/islandora_ci](https://github.com/Islandora/islandora_ci). The CI tests a wide range of PHP and Drupal version combinations. You can run these same tests locally if you have docker installed.

Set which Drupal and PHP version you want to test and setup the docker network

```bash
DRUPAL_VERSION=11.3
PHP_VERSION=8.3
export ENABLE_MODULES=flysystem_gcs_cors
```

Run the tests

```bash
docker run \
    --name drupal-ci-$DRUPAL_VERSION-$PHP_VERSION \
    --hostname drupal \
    --rm \
    --volume $(pwd):/var/www/drupal/web/modules/contrib/"$ENABLE_MODULES":ro \
    --env ENABLE_MODULES \
    --env TEST_SUITE="${TEST_SUITE:-}" \
    ghcr.io/islandora/ci:$DRUPAL_VERSION-php$PHP_VERSION
```

