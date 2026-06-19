<?php

namespace LeonardGanyire\Paypal;

use Illuminate\Support\ServiceProvider;

final class PayPalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/paypal.php', 'paypal');

        $this->app->singleton('paypal', fn (): PayPalClient => new PayPalClient);
        $this->app->alias('paypal', PayPalClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/paypal.php' => config_path('paypal.php'),
            ], 'paypal-config');
        }
    }
}
