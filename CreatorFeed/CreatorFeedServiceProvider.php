<?php

namespace Modules\Gateways\CreatorFeed;

use Illuminate\Support\ServiceProvider;

class CreatorFeedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadViewsFrom(__DIR__.'/views', 'creatorfeed');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
