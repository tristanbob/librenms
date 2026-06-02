<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SensorGraphVmFallbackTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create();

        LibrenmsConfig::set('victoriametrics.query_enabled', true);
    }

    public function testWirelessSensorGraphSeriesHasVmBinding(): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $definition = $registry->definitionFor('wireless_rssi');

        $query = GraphQuery::fromRequest(
            'wireless_sensor',
            'wireless_rssi',
            [
                'device_id'    => $this->device->device_id,
                'sensor_id'    => 1,
                'sensor_class' => 'rssi',
                'sensor_type'  => 'dummy',
                'sensor_index' => 1,
                'sensor_descr' => 'RSSI',
            ],
            time() - 3600,
            time(),
        );

        $series = $definition->series(new GraphContext($this->device, $query));
        $this->assertNotEmpty($series);

        $hasVmBinding = false;
        foreach ($series as $s) {
            if ($s->binding('victoriametrics') !== null) {
                $hasVmBinding = true;
            }
        }

        $this->assertTrue($hasVmBinding, 'wireless_rssi series must include a VictoriaMetrics binding');
    }
}
