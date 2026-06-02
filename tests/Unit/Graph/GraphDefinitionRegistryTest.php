<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\Definitions\Device\BitsGraph;
use LibreNMS\Graph\Definitions\Device\DeviceGraphCatalog;
use LibreNMS\Graph\Definitions\Device\IcmpPerfGraph;
use LibreNMS\Graph\Definitions\Device\MempoolGraph as DeviceMempoolGraph;
use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Device\ProcessorGraph as DeviceProcessorGraph;
use LibreNMS\Graph\Definitions\Device\WirelessGraphDefinitionResolver as DeviceWirelessGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Device\WirelessSensorGraph as DeviceWirelessSensorGraph;
use LibreNMS\Graph\Definitions\Mempool\UsageGraph as MempoolUsageGraph;
use LibreNMS\Graph\Definitions\Processor\UsageGraph as ProcessorUsageGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Storage\UsageGraph as StorageUsageGraph;
use LibreNMS\Graph\Definitions\Toner\UsageGraph as TonerUsageGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Wireless\WirelessSensorGraph;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Tests\TestCase;

final class GraphDefinitionRegistryTest extends TestCase
{
    public function testResolvesKnownGraphType(): void
    {
        $registry = new GraphDefinitionRegistry([BitsGraph::class, PollerPerfGraph::class]);

        $this->assertTrue($registry->supports('device_bits'));
        $this->assertTrue($registry->supports('device_poller_perf'));
        $this->assertInstanceOf(BitsGraph::class, $registry->definitionFor('device_bits'));
        $this->assertInstanceOf(PollerPerfGraph::class, $registry->definitionFor('device_poller_perf'));
    }

    public function testRejectsUnsupportedGraphType(): void
    {
        $this->expectException(\RuntimeException::class);

        (new GraphDefinitionRegistry())->definitionFor('missing_graph');
    }

    public function testRegistriesDoNotShareStaticState(): void
    {
        $withDefinition = new GraphDefinitionRegistry([PollerPerfGraph::class]);
        $empty = new GraphDefinitionRegistry();

        $this->assertTrue($withDefinition->supports('device_poller_perf'));
        $this->assertFalse($empty->supports('device_poller_perf'));
    }

    public function testResolvesKnownSensorGraphFamilyTypes(): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new DeviceWirelessGraphDefinitionResolver());
        $registry->registerResolver(new SensorGraphDefinitionResolver());
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $this->assertTrue($registry->supports('sensor_temperature'));
        $this->assertTrue($registry->supports('device_wireless_clients'));
        $this->assertTrue($registry->supports('wireless_rssi'));
        $this->assertInstanceOf(SensorGraph::class, $registry->definitionFor('sensor_temperature'));
        $this->assertInstanceOf(DeviceWirelessSensorGraph::class, $registry->definitionFor('device_wireless_clients'));
        $this->assertInstanceOf(WirelessSensorGraph::class, $registry->definitionFor('wireless_rssi'));
    }

    public function testRejectsUnknownSensorGraphFamilyTypes(): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new DeviceWirelessGraphDefinitionResolver());
        $registry->registerResolver(new SensorGraphDefinitionResolver());
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $this->assertFalse($registry->supports('sensor_not_real'));
        $this->assertFalse($registry->supports('device_wireless_not_real'));
        $this->assertFalse($registry->supports('wireless_not_real'));
    }

    public function testResolvesOverviewHealthGraphTypes(): void
    {
        $registry = new GraphDefinitionRegistry([
            DeviceProcessorGraph::class,
            DeviceMempoolGraph::class,
            IcmpPerfGraph::class,
            ProcessorUsageGraph::class,
            MempoolUsageGraph::class,
            StorageUsageGraph::class,
            TonerUsageGraph::class,
        ]);

        $this->assertInstanceOf(DeviceProcessorGraph::class, $registry->definitionFor('device_processor'));
        $this->assertInstanceOf(DeviceMempoolGraph::class, $registry->definitionFor('device_mempool'));
        $this->assertInstanceOf(IcmpPerfGraph::class, $registry->definitionFor('device_icmp_perf'));
        $this->assertInstanceOf(ProcessorUsageGraph::class, $registry->definitionFor('processor_usage'));
        $this->assertInstanceOf(MempoolUsageGraph::class, $registry->definitionFor('mempool_usage'));
        $this->assertInstanceOf(StorageUsageGraph::class, $registry->definitionFor('storage_usage'));
        $this->assertInstanceOf(TonerUsageGraph::class, $registry->definitionFor('toner_usage'));
    }

    public function testResolvesDeviceGraphCatalogTypes(): void
    {
        $registry = new GraphDefinitionRegistry(DeviceGraphCatalog::definitions());

        foreach ([
            'device_availability',
            'device_hr_processes',
            'device_ipsystemstats_ipv4',
            'device_netstat_tcp',
            'device_ucd_cpu',
            'device_uptime',
        ] as $type) {
            $this->assertTrue($registry->supports($type), "$type should be registered");
            $this->assertSame($type, $registry->definitionFor($type)->graphType());
        }
    }
}
