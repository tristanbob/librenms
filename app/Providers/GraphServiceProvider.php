<?php

/**
 * GraphServiceProvider.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Data\Store\Rrd;
use LibreNMS\Graph\Definitions\Device\BitsGraph as DeviceBitsGraph;
use LibreNMS\Graph\Definitions\Device\DeviceSensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Device\DiskIoGraph as DeviceDiskIoGraph;
use LibreNMS\Graph\Definitions\Device\IcmpPerfGraph;
use LibreNMS\Graph\Definitions\Device\DeviceGraphCatalog;
use LibreNMS\Graph\Definitions\Device\MempoolGraph as DeviceMempoolGraph;
use LibreNMS\Graph\Definitions\Device\PollerModulesPerfGraph;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Device\ProcessorGraph as DeviceProcessorGraph;
use LibreNMS\Graph\Definitions\Device\StorageGraph as DeviceStorageGraph;
use LibreNMS\Graph\Definitions\Device\WirelessGraphDefinitionResolver as DeviceWirelessGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Mempool\UsageGraph as MempoolUsageGraph;
use LibreNMS\Graph\Definitions\Port\PortGraphCatalog;
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
            ]);
            foreach (DeviceGraphCatalog::definitions() as $definition) {
                $registry->register($definition);
            }
            foreach (PortGraphCatalog::definitions() as $definition) {
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
