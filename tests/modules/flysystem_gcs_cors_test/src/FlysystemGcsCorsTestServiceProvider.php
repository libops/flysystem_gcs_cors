<?php

namespace Drupal\flysystem_gcs_cors_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Overrides services for flysystem_gcs_cors browser tests.
 */
class FlysystemGcsCorsTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('flysystem_gcs_cors.gcs_bucket_resolver')) {
      $container->getDefinition('flysystem_gcs_cors.gcs_bucket_resolver')
        ->setClass(FakeGcsBucketResolver::class);
    }
  }

}
