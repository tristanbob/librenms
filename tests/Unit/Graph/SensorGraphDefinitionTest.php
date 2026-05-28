<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Models\Device;
use App\Models\User;
use App\Models\UserPref;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\Sensor as SensorClass;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Graph\Definitions\Device\WirelessSensorGraph as DeviceWirelessSensorGraph;
use LibreNMS\Graph\Definitions\Sensor\SensorGraph;
use LibreNMS\Graph\Definitions\Wireless\WirelessSensorGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
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
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
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
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
        $markers = $graph->markers($this->device(), $query);

        $this->assertSame('m', $graph->unit($this->device(), $query));
        $this->assertSame(3500.0, ($binding->transform)(3.5));
        $this->assertSame(3500.0, $markers[0]['value']);
    }

    public function testDeviceWirelessGraphBuildsOneSeriesPerWirelessSensor(): void
    {
        Device::factory()->create(['device_id' => 1, 'hostname' => 'localhost', 'os' => 'linux']);

        DB::table('wireless_sensors')->insert([
            [
                'device_id' => 1,
                'sensor_class' => 'clients',
                'sensor_type' => 'dummy',
                'sensor_index' => '2',
                'sensor_descr' => 'Clients B',
                'sensor_oids' => '',
            ],
            [
                'device_id' => 1,
                'sensor_class' => 'clients',
                'sensor_type' => 'dummy',
                'sensor_index' => '1',
                'sensor_descr' => 'Clients A',
                'sensor_oids' => '',
            ],
            [
                'device_id' => 1,
                'sensor_class' => 'rssi',
                'sensor_type' => 'dummy',
                'sensor_index' => '1',
                'sensor_descr' => 'RSSI',
                'sensor_oids' => '',
            ],
        ]);

        $graph = new DeviceWirelessSensorGraph(WirelessSensorType::Clients);
        $series = $graph->series($this->device(), $this->sensorQuery('device_wireless_clients', 'clients'));

        $this->assertCount(2, $series);
        $this->assertSame('Clients A', $series[0]->name);
        $this->assertSame('Clients B', $series[1]->name);
        $this->assertSame('', $graph->unit($this->device(), $this->sensorQuery('device_wireless_clients', 'clients')));
        $clientsBinding = $series[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $clientsBinding);
        $this->assertSame(['wireless-sensor', 'clients', 'dummy', '1'], $clientsBinding->rrdName);
    }

    public function testDeviceWirelessGraphTransformsFrequencyToHz(): void
    {
        Device::factory()->create(['device_id' => 1, 'hostname' => 'localhost', 'os' => 'linux']);

        DB::table('wireless_sensors')->insert([
            'device_id' => 1,
            'sensor_class' => 'frequency',
            'sensor_type' => 'dummy',
            'sensor_index' => '1',
            'sensor_descr' => 'Frequency',
            'sensor_oids' => '',
        ]);

        $graph = new DeviceWirelessSensorGraph(WirelessSensorType::Frequency);
        $series = $graph->series($this->device(), $this->sensorQuery('device_wireless_frequency', 'frequency'));
        $binding = $series[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);

        $this->assertSame('Hz', $graph->unit($this->device(), $this->sensorQuery('device_wireless_frequency', 'frequency')));
        $this->assertSame(5800000000.0, ($binding->transform)(5800.0));
    }

    public function testSensorSeriesUsesThemeInkColorNoAreaFillAndTwoPxLine(): void
    {
        $graph  = new SensorGraph(SensorClass::Temperature);
        $query  = $this->sensorQuery('sensor_temperature', 'temperature');
        $series = $graph->series($this->device(), $query)[0];

        $this->assertSame('theme-ink', $series->color);
        $this->assertFalse($series->area);
        $this->assertSame(2.0, $series->lineWidth);
    }

    public function testSensorMarkersUseDirectionAwareSeverity(): void
    {
        $graph = new SensorGraph(SensorClass::Temperature);
        $query = $this->sensorQuery('sensor_temperature', 'temperature', [
            'sensor_limit'         => 80.0,
            'sensor_limit_warn'    => 70.0,
            'sensor_limit_low'     => 10.0,
            'sensor_limit_low_warn' => 15.0,
        ]);
        $markers = $graph->markers($this->device(), $query);

        $severities = array_column($markers, 'severity');
        $this->assertContains('low_critical',  $severities);
        $this->assertContains('low_warning',   $severities);
        $this->assertContains('high_warning',  $severities);
        $this->assertContains('high_critical', $severities);
        $this->assertNotContains('critical', $severities);
        $this->assertNotContains('warning',  $severities);
    }

    public function testWirelessSeriesUsesBlueColorAndOneAndHalfPxLine(): void
    {
        $graph  = new WirelessSensorGraph(WirelessSensorType::Rssi);
        $query  = $this->sensorQuery('wireless_rssi', 'rssi');
        $series = $graph->series($this->device(), $query)[0];

        $this->assertSame('0000cc', $series->color);
        $this->assertSame(1.5, $series->lineWidth);
        $this->assertSame(0.333, $series->areaOpacity);
    }

    #[DataProvider('wirelessAreaFillProvider')]
    public function testWirelessAreaFillMatchesScaleMinimumBehavior(WirelessSensorType $type, bool $expectedArea): void
    {
        $graph  = new WirelessSensorGraph($type);
        $query  = $this->sensorQuery('wireless_' . $type->value, $type->value);
        $series = $graph->series($this->device(), $query)[0];

        $this->assertSame($expectedArea, $series->area, "Unexpected area fill for {$type->value}");
    }

    public static function wirelessAreaFillProvider(): array
    {
        return [
            'ap-count' => [WirelessSensorType::ApCount, true],
            'clients' => [WirelessSensorType::Clients, true],
            'quality' => [WirelessSensorType::Quality, true],
            'capacity' => [WirelessSensorType::Capacity, true],
            'utilization' => [WirelessSensorType::Utilization, true],
            'rate' => [WirelessSensorType::Rate, true],
            'mcs' => [WirelessSensorType::Mcs, false],
            'ccq' => [WirelessSensorType::Ccq, true],
            'snr' => [WirelessSensorType::Snr, true],
            'sinr' => [WirelessSensorType::Sinr, true],
            'rsrp' => [WirelessSensorType::Rsrp, true],
            'rsrq' => [WirelessSensorType::Rsrq, true],
            'ssr' => [WirelessSensorType::Ssr, false],
            'mse' => [WirelessSensorType::Mse, false],
            'xpi' => [WirelessSensorType::Xpi, false],
            'rssi' => [WirelessSensorType::Rssi, true],
            'power' => [WirelessSensorType::Power, false],
            'noise-floor' => [WirelessSensorType::NoiseFloor, false],
            'errors' => [WirelessSensorType::Errors, true],
            'error-ratio' => [WirelessSensorType::ErrorRatio, true],
            'error-rate' => [WirelessSensorType::ErrorRate, true],
            'frequency' => [WirelessSensorType::Frequency, true],
            'distance' => [WirelessSensorType::Distance, true],
            'cell' => [WirelessSensorType::Cell, true],
            'channel' => [WirelessSensorType::Channel, false],
        ];
    }

    public function testWirelessAreaFillProviderCoversEveryWirelessSensorType(): void
    {
        $covered = array_values(array_map(
            static fn (array $case): string => $case[0]->value,
            self::wirelessAreaFillProvider()
        ));

        $this->assertSame(
            WirelessSensorType::values(),
            $covered,
            'Update wirelessAreaFillProvider() when adding wireless sensor types.'
        );
    }

    public function testWirelessMarkersUseLimitSeverityForBothLimits(): void
    {
        $graph = new WirelessSensorGraph(WirelessSensorType::Rssi);
        $query = $this->sensorQuery('wireless_rssi', 'rssi', [
            'sensor_limit'      => 0.0,
            'sensor_limit_warn' => -10.0,
            'sensor_limit_low'  => -90.0,
            'sensor_limit_low_warn' => -80.0,
        ]);
        $markers = $graph->markers($this->device(), $query);

        $this->assertCount(2, $markers, 'Wireless markers should include only sensor_limit and sensor_limit_low');
        foreach ($markers as $marker) {
            $this->assertSame('limit', $marker['severity']);
        }
        $names = array_column($markers, 'name');
        $this->assertContains('Low limit',  $names);
        $this->assertContains('High limit', $names);
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
