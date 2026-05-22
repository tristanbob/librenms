<?php

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use App\Models\Device;
use LibreNMS\Tests\TestCase;
use LibreNMS\Util\VictoriaMetrics\LabelExtractor;

final class LabelExtractorTest extends TestCase
{
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        // device_id is the primary key and is not mass-assignable; assign directly
        $this->device = new Device();
        $this->device->device_id = 42;
        $this->device->hostname = 'router1.example.com';
    }

    public function testBaseLabelsAlwaysPresent(): void
    {
        $labels = LabelExtractor::extract($this->device, 'poller-perf', []);

        $this->assertSame('librenms', $labels['source']);
        $this->assertSame('42', $labels['device_id']);
        $this->assertSame('router1.example.com', $labels['hostname']);
        $this->assertArrayHasKey('entity_type', $labels);
    }

    public function testEntityTypeDefaultsToDevice(): void
    {
        $labels = LabelExtractor::extract($this->device, 'poller-perf', []);

        $this->assertSame('device', $labels['entity_type']);
    }

    public function testEntityTypeDerivesFromPortId(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', ['port_id' => 7]);

        $this->assertSame('port', $labels['entity_type']);
        $this->assertSame('7', $labels['port_id']);
    }

    public function testEntityTypeDerivesFromSensorId(): void
    {
        $labels = LabelExtractor::extract($this->device, 'sensors', ['sensor_id' => 3]);

        $this->assertSame('sensor', $labels['entity_type']);
        $this->assertSame('3', $labels['sensor_id']);
    }

    public function testEntityTypeDerivesFromServiceId(): void
    {
        $labels = LabelExtractor::extract($this->device, 'services', ['service_id' => 5]);

        $this->assertSame('service', $labels['entity_type']);
    }

    public function testEntityTypeDerivesFromAppId(): void
    {
        $labels = LabelExtractor::extract($this->device, 'apps', ['app_id' => 2]);

        $this->assertSame('app', $labels['entity_type']);
    }

    public function testEntityTypeDerivesFromBillId(): void
    {
        $labels = LabelExtractor::extract($this->device, 'billing', ['bill_id' => 1]);

        $this->assertSame('bill', $labels['entity_type']);
    }

    public function testRrdTagsAreNotIncluded(): void
    {
        $tags = [
            'rrd_def'     => 'some-rrd-def-object',
            'rrd_name'    => ['port-id7'],
            'rrd_oldname' => ['port-id6'],
            'rrd_step'    => 300,
            'port_id'     => 7,
        ];
        $labels = LabelExtractor::extract($this->device, 'ports', $tags);

        $this->assertArrayNotHasKey('rrd_def', $labels);
        $this->assertArrayNotHasKey('rrd_name', $labels);
        $this->assertArrayNotHasKey('rrd_oldname', $labels);
        $this->assertArrayNotHasKey('rrd_step', $labels);
    }

    public function testHighCardinalityTagsAreNotIncluded(): void
    {
        $tags = [
            'ifAlias'          => 'some-long-alias',
            'ifIndex'          => '3',
            'port_descr_type'  => 'something',
            'port_id'          => 7,
        ];
        $labels = LabelExtractor::extract($this->device, 'ports', $tags);

        $this->assertArrayNotHasKey('ifAlias', $labels);
        $this->assertArrayNotHasKey('ifIndex', $labels);
        $this->assertArrayNotHasKey('port_descr_type', $labels);
    }

    public function testIfNameIsIncludedWhenPresent(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', ['port_id' => 7, 'ifName' => 'Gi0/0']);

        $this->assertSame('Gi0/0', $labels['ifName']);
    }

    public function testRealPortPollerTagsKeepPortIdAndDropHighCardinalityLabels(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', [
            'port_id' => 7,
            'ifName' => 'Gi0/0',
            'ifAlias' => 'uplink to core',
            'ifIndex' => '3',
            'port_descr_type' => 'uplink',
            'rrd_name' => ['port-id7'],
            'rrd_def' => 'definition',
        ]);

        $this->assertSame('port', $labels['entity_type']);
        $this->assertSame('7', $labels['port_id']);
        $this->assertSame('Gi0/0', $labels['ifName']);
        $this->assertArrayNotHasKey('ifAlias', $labels);
        $this->assertArrayNotHasKey('ifIndex', $labels);
        $this->assertArrayNotHasKey('port_descr_type', $labels);
        $this->assertArrayNotHasKey('rrd_name', $labels);
        $this->assertArrayNotHasKey('rrd_def', $labels);
    }

    public function testSensorClassIsIncludedWhenPresent(): void
    {
        $labels = LabelExtractor::extract($this->device, 'sensors', ['sensor_id' => 1, 'sensor_class' => 'temperature']);

        $this->assertSame('temperature', $labels['sensor_class']);
    }

    public function testModuleIsIncludedWhenPresent(): void
    {
        $labels = LabelExtractor::extract($this->device, 'poller-perf', ['module' => 'ALL']);

        $this->assertSame('ALL', $labels['module']);
    }

    public function testEmptyExtraTagsAreNotIncluded(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', ['ifName' => '']);

        $this->assertArrayNotHasKey('ifName', $labels);
    }

    public function testEntityIdIsStringified(): void
    {
        $labels = LabelExtractor::extract($this->device, 'ports', ['port_id' => 99]);

        $this->assertIsString($labels['port_id']);
        $this->assertSame('99', $labels['port_id']);
    }
}
