<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Graph\Definitions\Device\BitsGraph as DeviceBitsGraph;
use LibreNMS\Graph\Definitions\Device\DeviceSensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Device\DiskIoGraph as DeviceDiskIoGraph;
use LibreNMS\Graph\Definitions\Device\IcmpPerfGraph;
use LibreNMS\Graph\Definitions\Device\LegacyDeviceGraphCatalog;
use LibreNMS\Graph\Definitions\Device\MempoolGraph as DeviceMempoolGraph;
use LibreNMS\Graph\Definitions\Device\PollerModulesPerfGraph;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Device\ProcessorGraph as DeviceProcessorGraph;
use LibreNMS\Graph\Definitions\Device\StorageGraph as DeviceStorageGraph;
use LibreNMS\Graph\Definitions\Device\WirelessGraphDefinitionResolver as DeviceWirelessGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Mempool\UsageGraph as MempoolUsageGraph;
use LibreNMS\Graph\Definitions\Port\BitsGraph;
use LibreNMS\Graph\Definitions\Port\DiscardsGraph;
use LibreNMS\Graph\Definitions\Port\ErrorsGraph;
use LibreNMS\Graph\Definitions\Port\PacketsGraph;
use LibreNMS\Graph\Definitions\Processor\UsageGraph as ProcessorUsageGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Storage\UsageGraph as StorageUsageGraph;
use LibreNMS\Graph\Definitions\Toner\UsageGraph as TonerUsageGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
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
                DeviceBitsGraph::class,
                IcmpPerfGraph::class,
                DeviceProcessorGraph::class,
                DeviceMempoolGraph::class,
                DeviceStorageGraph::class,
                DeviceDiskIoGraph::class,
                PollerPerfGraph::class,
                PollerModulesPerfGraph::class,
                ProcessorUsageGraph::class,
                MempoolUsageGraph::class,
                StorageUsageGraph::class,
                TonerUsageGraph::class,
                BitsGraph::class,
                PacketsGraph::class,
                ErrorsGraph::class,
                DiscardsGraph::class,
            ]);
            foreach (LegacyDeviceGraphCatalog::definitions() as $definition) {
                $registry->register($definition);
            }
            $registry->registerResolver(new DeviceWirelessGraphDefinitionResolver());
            $registry->registerResolver(new DeviceSensorGraphDefinitionResolver());
            $registry->registerResolver(new SensorGraphDefinitionResolver());
            $registry->registerResolver(new WirelessGraphDefinitionResolver());
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
