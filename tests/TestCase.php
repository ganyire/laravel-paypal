<?php

namespace LeonardGanyire\Paypal\Tests;

use LeonardGanyire\Paypal\PayPalServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PayPalServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('paypal.client_id', 'paypal-client');
        $app['config']->set('paypal.client_secret', 'paypal-secret');
        $app['config']->set('paypal.base_url', 'https://api-m.sandbox.paypal.com');
        $app['config']->set('paypal.webhook_id', 'WH-123');
        $app['config']->set('cache.default', 'array');
    }
}
