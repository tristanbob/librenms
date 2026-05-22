<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Port\BitsGraph;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\RrdGraphDataProvider;

class GraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GraphDefinitionRegistry::class, fn () => new GraphDefinitionRegistry([
            PollerPerfGraph::class,
            BitsGraph::class,
        ]));

        $this->app->singleton(GraphDataProvider::class, fn (Application $app) => new RrdGraphDataProvider(
            $app->make(Rrd::class),
            $app->make(GraphDefinitionRegistry::class),
        ));
    }
}
