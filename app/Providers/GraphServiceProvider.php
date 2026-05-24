<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Graph\Definitions\Device\PollerModulesPerfGraph;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Port\BitsGraph;
use LibreNMS\Graph\Definitions\Port\DiscardsGraph;
use LibreNMS\Graph\Definitions\Port\ErrorsGraph;
use LibreNMS\Graph\Definitions\Port\PacketsGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessSensorGraph;
use LibreNMS\Graph\GraphDataBackendSelector;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\RrdGraphDataProvider;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class GraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GraphDefinitionRegistry::class, function () {
            $registry = new GraphDefinitionRegistry([
                PollerPerfGraph::class,
                PollerModulesPerfGraph::class,
                BitsGraph::class,
                PacketsGraph::class,
                ErrorsGraph::class,
                DiscardsGraph::class,
            ]);
            $registry->registerPrefix('sensor_',   new SensorGraph());
            $registry->registerPrefix('wireless_', new WirelessSensorGraph());
            return $registry;
        });

        $this->app->singleton(RrdGraphDataProvider::class, fn (Application $app) => new RrdGraphDataProvider(
            $app->make(Rrd::class),
            $app->make(GraphDefinitionRegistry::class),
        ));

        $this->app->singleton(VictoriaMetricsGraphDataProvider::class, fn (Application $app) =>
            new VictoriaMetricsGraphDataProvider($app->make(GraphDefinitionRegistry::class))
        );

        $this->app->singleton(GraphDataProvider::class, fn (Application $app) => new GraphDataBackendSelector(
            $app->make(RrdGraphDataProvider::class),
            $app->make(VictoriaMetricsGraphDataProvider::class),
        ));
    }
}
