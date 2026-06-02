<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\Definitions\Processor\UsageGraph as ProcessorUsageGraph;
use LibreNMS\Graph\Definitions\Storage\UsageGraph as StorageUsageGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EntityGraphVmBindingTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = Device::factory()->create();
    }

    public function testProcessorUsageSeriesHasVmBinding(): void
    {
        $definition = new ProcessorUsageGraph();

        $query = GraphQuery::fromRequest(
            'processor',
            'processor_usage',
            [
                'device_id'       => $this->device->device_id,
                'hostname'        => $this->device->hostname,
                'processor_id'    => 1,
                'processor_type'  => 'hr',
                'processor_index' => 0,
                'processor_descr' => 'CPU0',
            ],
            time() - 3600,
            time(),
        );

        $series = $definition->series(new GraphContext($this->device, $query));
        $this->assertNotEmpty($series);

        foreach ($series as $s) {
            $this->assertNotNull(
                $s->binding('victoriametrics'),
                "processor_usage series '{$s->key}' must have a VictoriaMetrics binding"
            );
        }
    }

    public function testStorageUsageSeriesHasVmExpression(): void
    {
        $definition = new StorageUsageGraph();

        $query = GraphQuery::fromRequest(
            'storage',
            'storage_usage',
            [
                'device_id'     => $this->device->device_id,
                'hostname'      => $this->device->hostname,
                'storage_id'    => 1,
                'type'          => 'hrStorageFixedDisk',
                'descr'         => '/var',
                'storage_descr' => '/var',
            ],
            time() - 3600,
            time(),
        );

        $series = $definition->series(new GraphContext($this->device, $query));
        $this->assertNotEmpty($series);

        foreach ($series as $s) {
            $this->assertNotNull(
                $s->binding('victoriametrics'),
                "storage_usage series '{$s->key}' must have a VictoriaMetrics binding"
            );
        }
    }

    #[DataProvider('wirelessGraphTypes')]
    public function testWirelessSensorSeriesHasVmBinding(string $graphType, array $entities): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $definition = $registry->definitionFor($graphType);

        $query = GraphQuery::fromRequest(
            'wireless_sensor',
            $graphType,
            ['device_id' => $this->device->device_id] + $entities,
            time() - 3600,
            time(),
        );

        $series = $definition->series(new GraphContext($this->device, $query));
        $this->assertNotEmpty($series);

        foreach ($series as $s) {
            $this->assertNotNull(
                $s->binding('victoriametrics'),
                "$graphType series '{$s->key}' must have a VictoriaMetrics binding"
            );
        }
    }

    public static function wirelessGraphTypes(): array
    {
        $base = ['sensor_id' => 1, 'sensor_type' => 'dummy', 'sensor_index' => 1, 'sensor_descr' => 'test'];

        return [
            'wireless_rssi'    => ['wireless_rssi',    ['sensor_class' => 'rssi'] + $base],
            'wireless_clients' => ['wireless_clients', ['sensor_class' => 'clients'] + $base],
            'wireless_snr'     => ['wireless_snr',     ['sensor_class' => 'snr'] + $base],
        ];
    }
}
