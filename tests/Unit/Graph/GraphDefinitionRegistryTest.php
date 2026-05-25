<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Wireless\WirelessSensorGraph;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Tests\TestCase;

final class GraphDefinitionRegistryTest extends TestCase
{
    public function testResolvesKnownGraphType(): void
    {
        $registry = new GraphDefinitionRegistry([PollerPerfGraph::class]);

        $this->assertTrue($registry->supports('device_poller_perf'));
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
        $registry->registerResolver(new SensorGraphDefinitionResolver());
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $this->assertTrue($registry->supports('sensor_temperature'));
        $this->assertTrue($registry->supports('wireless_rssi'));
        $this->assertInstanceOf(SensorGraph::class, $registry->definitionFor('sensor_temperature'));
        $this->assertInstanceOf(WirelessSensorGraph::class, $registry->definitionFor('wireless_rssi'));
    }

    public function testRejectsUnknownSensorGraphFamilyTypes(): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new SensorGraphDefinitionResolver());
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $this->assertFalse($registry->supports('sensor_not_real'));
        $this->assertFalse($registry->supports('wireless_not_real'));
    }
}
