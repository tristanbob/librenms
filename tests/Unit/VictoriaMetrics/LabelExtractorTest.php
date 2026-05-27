<?php

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use App\Models\Device;
use LibreNMS\Data\Store\VictoriaMetrics\LabelExtractor;
use LibreNMS\Tests\TestCase;

final class LabelExtractorTest extends TestCase
{
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = new Device();
        $this->device->device_id = 42;
        $this->device->hostname = 'router1.example.com';
    }

    public function testBaseLabelsAlwaysPresent(): void
    {
        $labels = LabelExtractor::extract($this->device, 'poller-perf', []);

        $this->assertSame('librenms', $labels['source']);
        $this->assertSame('router1.example.com', $labels['hostname']);
        $this->assertSame('device', $labels['entity_type']);
        $this->assertArrayNotHasKey('device_id', $labels);
    }

    public function testDatabaseIdsAreNeverIncludedAsLabels(): void
    {
        $labels = LabelExtractor::extract($this->device, 'sensor', [
            'port_id' => 7,
            'sensor_id' => 12,
            'mempool_id' => 4,
            'storage_id' => 9,
            'service_id' => 2,
            'app_id' => 3,
            'bill_id' => 5,
            'sensor_class' => 'temperature',
            'sensor_type' => 'cisco-entity-sensor',
            'sensor_index' => '1',
        ]);

        $this->assertArrayNotHasKey('device_id', $labels);
        $this->assertArrayNotHasKey('port_id', $labels);
        $this->assertArrayNotHasKey('sensor_id', $labels);
        $this->assertArrayNotHasKey('mempool_id', $labels);
        $this->assertArrayNotHasKey('storage_id', $labels);
        $this->assertArrayNotHasKey('service_id', $labels);
        $this->assertArrayNotHasKey('app_id', $labels);
        $this->assertArrayNotHasKey('bill_id', $labels);
    }

    public function testPortIdentityUsesIfIndexAndIfName(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', [
            'port_id' => 7,
            'ifIndex' => 3,
            'ifName' => 'Gi0/0',
            'ifAlias' => 'uplink to core',
            'rrd_name' => ['port-id7'],
            'rrd_def' => 'definition',
        ]);

        $this->assertSame('port', $labels['entity_type']);
        $this->assertSame('3', $labels['ifIndex']);
        $this->assertSame('Gi0/0', $labels['ifName']);
        $this->assertArrayNotHasKey('port_id', $labels);
        $this->assertArrayNotHasKey('ifAlias', $labels);
        $this->assertArrayNotHasKey('rrd_name', $labels);
        $this->assertArrayNotHasKey('rrd_def', $labels);
    }

    public function testSensorIdentityUsesClassTypeAndIndex(): void
    {
        $labels = LabelExtractor::extract($this->device, 'sensor', [
            'sensor_id' => 12,
            'sensor_class' => 'temperature',
            'sensor_type' => 'cisco-entity-sensor',
            'sensor_descr' => 'Inlet Temperature Sensor',
            'sensor_index' => '1',
        ]);

        $this->assertSame('sensor', $labels['entity_type']);
        $this->assertSame('temperature', $labels['sensor_class']);
        $this->assertSame('cisco-entity-sensor', $labels['sensor_type']);
        $this->assertSame('1', $labels['sensor_index']);
        $this->assertArrayNotHasKey('sensor_id', $labels);
        $this->assertArrayNotHasKey('sensor_descr', $labels);
    }

    public function testMempoolIdentityUsesTypeClassAndIndex(): void
    {
        $labels = LabelExtractor::extract($this->device, 'mempool', [
            'mempool_id' => 7,
            'mempool_type' => 'system',
            'mempool_class' => 'virtual',
            'mempool_index' => '0',
        ]);

        $this->assertSame('mempool', $labels['entity_type']);
        $this->assertSame('system', $labels['mempool_type']);
        $this->assertSame('virtual', $labels['mempool_class']);
        $this->assertSame('0', $labels['mempool_index']);
        $this->assertArrayNotHasKey('mempool_id', $labels);
    }

    public function testStorageIdentityUsesTypeAndDescr(): void
    {
        $labels = LabelExtractor::extract($this->device, 'storage', [
            'storage_id' => 3,
            'type' => 'hrStorage',
            'descr' => '/',
        ]);

        $this->assertSame('storage', $labels['entity_type']);
        $this->assertSame('hrStorage', $labels['type']);
        $this->assertSame('/', $labels['descr']);
        $this->assertArrayNotHasKey('storage_id', $labels);
    }

    public function testExtraLabelsForDeviceLevelMetrics(): void
    {
        $this->assertSame('ipv4', LabelExtractor::extract($this->device, 'ipSystemStats', ['af' => 'ipv4'])['af']);
        $this->assertSame('86400', LabelExtractor::extract($this->device, 'availability', ['name' => '86400'])['name']);
        $this->assertSame('7', LabelExtractor::extract($this->device, 'sla', ['sla_nr' => 7])['sla_nr']);
    }

    public function testDiskIoDescrIsNormalised(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ucd_diskio', ['diskio_descr' => 'sda']);

        $this->assertSame('sda', $labels['descr']);
    }

    public function testProcessorLabelsIncluded(): void
    {
        $labels = LabelExtractor::extract($this->device, 'processors', [
            'processor_type' => 'hr',
            'processor_index' => '0',
        ]);

        $this->assertSame('hr', $labels['processor_type']);
        $this->assertSame('0', $labels['processor_index']);
        $this->assertSame('processor', $labels['entity_type']);
    }

    public function testEmptyExtraTagsAreNotIncluded(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', ['ifName' => '']);

        $this->assertArrayNotHasKey('ifName', $labels);
    }
}
