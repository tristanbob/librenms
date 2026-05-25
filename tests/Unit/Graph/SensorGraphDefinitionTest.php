<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Models\User;
use App\Models\UserPref;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\Definitions\Sensor\SensorGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessSensorGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Tests\DBTestCase;

final class SensorGraphDefinitionTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testSensorGraphUsesConcreteGraphTypeAndRrdBinding(): void
    {
        $graph = new SensorGraph(SensorClass::Temperature);
        $query = $this->sensorQuery('sensor_temperature', 'temperature');

        $this->assertSame('sensor_temperature', $graph->graphType());
        $this->assertSame('sensor_temperature:123', $graph->id($this->device(), $query));
        $this->assertSame('°C', $graph->unit($this->device(), $query));

        $binding = $graph->series($this->device(), $query)[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
        $this->assertSame(['sensor', 'temperature', 'dummy', 5], $binding->rrdName);
        $this->assertSame('sensor', $binding->ds);
    }

    public function testTemperatureGraphTransformsValuesAndMarkersForFahrenheitPreference(): void
    {
        $user = User::factory()->admin()->create(['enabled' => 1]);
        UserPref::setPref($user, 'temp_units', 'f');
        $this->actingAs($user);

        $graph = new SensorGraph(SensorClass::Temperature);
        $query = $this->sensorQuery('sensor_temperature', 'temperature', ['sensor_limit' => 80.0]);
        $binding = $graph->series($this->device(), $query)[0]->binding(RrdMetricBinding::SOURCE);
        $markers = $graph->markers($this->device(), $query);

        $this->assertSame('°F', $graph->unit($this->device(), $query));
        $this->assertSame(212.0, ($binding->transform)(100.0));
        $this->assertSame(176.0, $markers[0]['value']);
    }

    public function testWirelessFrequencyUnitIsMhzWithNoTransform(): void
    {
        $graph = new WirelessSensorGraph(WirelessSensorType::Frequency);
        $query = $this->sensorQuery('wireless_frequency', 'frequency', ['sensor_limit' => 5800.0]);
        $binding = $graph->series($this->device(), $query)[0]->binding(RrdMetricBinding::SOURCE);
        $markers = $graph->markers($this->device(), $query);

        $this->assertSame('wireless_frequency', $graph->graphType());
        $this->assertSame('MHz', $graph->unit($this->device(), $query));
        $this->assertNull($binding->transform);
        $this->assertSame(5800.0, $markers[0]['value']);
    }

    public function testSensorGraphTitleReturnsClassLabel(): void
    {
        $this->assertSame('Temperature', (new SensorGraph(SensorClass::Temperature))->title($this->device()));
        $this->assertSame('Voltage', (new SensorGraph(SensorClass::Voltage))->title($this->device()));
    }

    public function testWirelessGraphTitleReturnsLongLabel(): void
    {
        $this->assertSame('Frequency', (new WirelessSensorGraph(WirelessSensorType::Frequency))->title($this->device()));
        $this->assertSame('Received Signal Strength Indicator', (new WirelessSensorGraph(WirelessSensorType::Rssi))->title($this->device()));
    }

    public function testWirelessDistanceGraphTransformsKmBackedValuesToMeters(): void
    {
        $graph = new WirelessSensorGraph(WirelessSensorType::Distance);
        $query = $this->sensorQuery('wireless_distance', 'distance', ['sensor_limit' => 3.5]);
        $binding = $graph->series($this->device(), $query)[0]->binding(RrdMetricBinding::SOURCE);
        $markers = $graph->markers($this->device(), $query);

        $this->assertSame('m', $graph->unit($this->device(), $query));
        $this->assertSame(3500.0, ($binding->transform)(3.5));
        $this->assertSame(3500.0, $markers[0]['value']);
    }

    private function sensorQuery(string $graphType, string $sensorClass, array $overrides = []): GraphQuery
    {
        return GraphQuery::fromRequest('sensor', $graphType, array_replace([
            'device_id' => 1,
            'sensor_id' => 123,
            'sensor_class' => $sensorClass,
            'sensor_type' => 'dummy',
            'sensor_index' => 5,
            'sensor_descr' => 'Sensor',
            'poller_type' => 'snmp',
            'sensor_limit' => null,
            'sensor_limit_warn' => null,
            'sensor_limit_low' => null,
            'sensor_limit_low_warn' => null,
        ], $overrides), time() - 3600, time());
    }

    private function device(): array
    {
        return ['device_id' => 1, 'hostname' => 'localhost', 'os' => 'linux'];
    }
}
