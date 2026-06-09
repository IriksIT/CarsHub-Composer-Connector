<?php

declare(strict_types=1);

namespace CarsHub\Connector\Tests;

use CarsHub\Connector\CarsHubConnectorServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CarsHubConnectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('carshub.api_key', 'test-api-key');
        $app['config']->set('carshub.crew_slug', 'test-crew');
        $app['config']->set('carshub.api_base_url', 'https://carshub.nl/api');
        $app['config']->set('carshub.sync_on_boot', false);
        $app['config']->set('carshub.cache.path', 'carshub-test');
        $app['config']->set('carshub.cache.ttl', [
            'pages'    => 86400,
            'settings' => 86400,
            'events'   => 3600,
            'members'  => 3600,
            'cars'     => 3600,
            'stats'    => 3600,
        ]);
    }
}
