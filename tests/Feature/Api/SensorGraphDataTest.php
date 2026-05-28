<?php

/**
 * SensorGraphDataTest.php
 *
 * Tests for the sensor JSON graph data API endpoints introduced in Stage 7.
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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\Sensor;
use App\Models\User;
use App\Models\WirelessSensor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeries;
use LibreNMS\Tests\DBTestCase;

class SensorGraphDataTest extends DBTestCase
{
    use DatabaseTransactions;

    private User     $adminUser;
    private ApiToken $adminToken;
    private Device   $device;
    private Sensor   $sensor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser  = User::factory()->admin()->create(['enabled' => 1]);
        $this->adminToken = ApiToken::generateToken($this->adminUser);
        $this->device     = Device::factory()->create();

        $this->sensor = Sensor::factory()->create([
            'device_id'          => $this->device->device_id,
            'sensor_class'       => 'temperature',
            'sensor_type'        => 'dummy',
            'sensor_index'       => 1,
            'sensor_descr'       => 'CPU Temperature',
            'poller_type'        => 'snmp',
            'sensor_limit'       => 80.0,
            'sensor_limit_warn'  => 70.0,
            'sensor_limit_low'   => null,
            'sensor_limit_low_warn' => null,
        ]);

        // Stub GraphDataProvider: returns a realistic sensor result without hitting rrdtool.
        $this->app->bind(GraphDataProvider::class, fn() => new class implements GraphDataProvider {
            public function query(GraphQuery $query): GraphDataResult
            {
                $isSensor   = str_starts_with($query->graphType, 'sensor_');
                $isWireless = str_starts_with($query->graphType, 'wireless_');

                if (! $isSensor && ! $isWireless) {
                    throw new \RuntimeException("Graph type '{$query->graphType}' is not supported by this stub.");
                }

                $result = new GraphDataResult(
                    id:       $query->graphType . ':' . $query->entities['sensor_id'],
                    type:     $query->graphType,
                    title:    'Sensor',
                    subtitle: ($query->entities['sensor_descr'] ?? 'sensor'),
                    unit:     $query->entities['sensor_class'] === 'temperature' ? '°C' : '',
                    from:     $query->from,
                    to:       $query->to,
                    step:     $query->step,
                );
                $result->setSource('rrd');
                $result->setDisplay(['renderer' => 'timeseries', 'kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true, 'tooltip' => true]);

                $series = new GraphSeries(name: $query->entities['sensor_descr'] ?? 'sensor', key: 'sensor', unit: '°C', area: true);
                $series->addPoint($query->from * 1000, 42.5);
                $result->addSeries($series);

                // Include threshold markers when limits are configured
                if (isset($query->entities['sensor_limit'])) {
                    $result->addMarker(['type' => 'horizontal_line', 'name' => 'High critical', 'value' => (float) $query->entities['sensor_limit'], 'severity' => 'critical']);
                }
                if (isset($query->entities['sensor_limit_warn'])) {
                    $result->addMarker(['type' => 'horizontal_line', 'name' => 'High warning', 'value' => (float) $query->entities['sensor_limit_warn'], 'severity' => 'warning']);
                }

                return $result;
            }
        });
    }

    public function testSensorEndpointReturnsSeriesAndThresholdMarkers(): void
    {
        $hostname = $this->device->hostname;
        $sensorId = $this->sensor->sensor_id;

        $response = $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/sensors/{$sensorId}/graphs/sensor_temperature/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'graph' => [
                    'id', 'type', 'title', 'subtitle', 'unit',
                    'from', 'to', 'step',
                    'series',
                    'markers',
                    'meta' => ['source', 'fallback_used', 'empty_reason', 'warnings', 'generated_at'],
                ],
            ])
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "sensor_temperature:{$sensorId}",
                    'type' => 'sensor_temperature',
                    'meta' => ['source' => 'rrd'],
                ],
            ]);

        $data       = $response->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('sensor', $seriesKeys);

        $markers = $data['graph']['markers'];
        $this->assertNotEmpty($markers, 'Expected threshold markers when sensor_limit is configured');

        $markerNames = array_column($markers, 'name');
        $this->assertContains('High critical', $markerNames);
        $this->assertContains('High warning',  $markerNames);

        $severities = array_column($markers, 'severity');
        $this->assertContains('critical', $severities);
        $this->assertContains('warning',  $severities);
    }

    public function testSensorEndpointRequiresAuth(): void
    {
        $hostname = $this->device->hostname;
        $sensorId = $this->sensor->sensor_id;

        $this->json('GET', "/api/v0/devices/{$hostname}/sensors/{$sensorId}/graphs/sensor_temperature/data")
             ->assertStatus(401);
    }

    public function testSensorEndpointReturnsErrorForNonExistentSensor(): void
    {
        $hostname = $this->device->hostname;

        $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/sensors/999999999/graphs/sensor_temperature/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testSensorEndpointRejectsGraphTypeThatDoesNotMatchSensorClass(): void
    {
        $hostname = $this->device->hostname;
        $sensorId = $this->sensor->sensor_id;

        $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/sensors/{$sensorId}/graphs/sensor_voltage/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testSensorEndpointRejectsUnknownGraphType(): void
    {
        $hostname = $this->device->hostname;
        $sensorId = $this->sensor->sensor_id;

        $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/sensors/{$sensorId}/graphs/sensor_not_real/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testSensorEndpointVerifiesSensorBelongsToDevice(): void
    {
        $otherDevice = Device::factory()->create();
        $otherSensor = Sensor::factory()->create([
            'device_id'    => $otherDevice->device_id,
            'sensor_class' => 'temperature',
            'sensor_type'  => 'dummy',
        ]);

        // Request this other device's sensor via the original device's hostname — should 404
        $hostname = $this->device->hostname;

        $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/sensors/{$otherSensor->sensor_id}/graphs/sensor_temperature/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testWebSensorEndpointUsesDeviceRouteContext(): void
    {
        $url = GraphDataUrl::sensor($this->device->device_id, $this->sensor->sensor_id, 'sensor_temperature');

        $this->actingAs($this->adminUser)
            ->json('GET', $url)
            ->assertStatus(200)
            ->assertJson([
                'graph' => [
                    'id' => "sensor_temperature:{$this->sensor->sensor_id}",
                    'type' => 'sensor_temperature',
                ],
            ]);
    }

    public function testWirelessSensorEndpointReturnsSeries(): void
    {
        $wirelessSensor = WirelessSensor::forceCreate([
            'device_id'    => $this->device->device_id,
            'sensor_class' => 'rssi',
            'sensor_type'  => 'dummy',
            'sensor_index' => 1,
            'sensor_descr' => 'RSSI AP1',
            'sensor_oids'  => json_encode(['.1.3.6.1']),
        ]);

        $hostname = $this->device->hostname;
        $sensorId = $wirelessSensor->sensor_id;

        $response = $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/wireless/{$sensorId}/graphs/wireless_rssi/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "wireless_rssi:{$sensorId}",
                    'type' => 'wireless_rssi',
                ],
            ]);

        $data       = $response->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('sensor', $seriesKeys);
    }

    public function testWirelessClientsEndpointReturnsSeries(): void
    {
        $wirelessSensor = WirelessSensor::forceCreate([
            'device_id'    => $this->device->device_id,
            'sensor_class' => 'clients',
            'sensor_type'  => 'dummy',
            'sensor_index' => 2,
            'sensor_descr' => 'Client Count',
            'sensor_oids'  => json_encode(['.1.3.6.2']),
        ]);

        $hostname = $this->device->hostname;
        $sensorId = $wirelessSensor->sensor_id;

        $response = $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/wireless/{$sensorId}/graphs/wireless_clients/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "wireless_clients:{$sensorId}",
                    'type' => 'wireless_clients',
                ],
            ]);
    }

    public function testWirelessSensorEndpointRejectsGraphTypeThatDoesNotMatchSensorClass(): void
    {
        $wirelessSensor = WirelessSensor::forceCreate([
            'device_id'    => $this->device->device_id,
            'sensor_class' => 'rssi',
            'sensor_type'  => 'dummy',
            'sensor_index' => 3,
            'sensor_descr' => 'RSSI AP2',
            'sensor_oids'  => json_encode(['.1.3.6.3']),
        ]);

        $hostname = $this->device->hostname;
        $sensorId = $wirelessSensor->sensor_id;

        $this->json(
            'GET',
            "/api/v0/devices/{$hostname}/wireless/{$sensorId}/graphs/wireless_clients/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testWebWirelessEndpointUsesDeviceRouteContext(): void
    {
        $wirelessSensor = WirelessSensor::forceCreate([
            'device_id'    => $this->device->device_id,
            'sensor_class' => 'rssi',
            'sensor_type'  => 'dummy',
            'sensor_index' => 4,
            'sensor_descr' => 'RSSI AP3',
            'sensor_oids'  => json_encode(['.1.3.6.4']),
        ]);

        $url = GraphDataUrl::wireless($this->device->device_id, $wirelessSensor->sensor_id, 'wireless_rssi');

        $this->actingAs($this->adminUser)
            ->json('GET', $url)
            ->assertStatus(200)
            ->assertJson([
                'graph' => [
                    'id' => "wireless_rssi:{$wirelessSensor->sensor_id}",
                    'type' => 'wireless_rssi',
                ],
            ]);
    }
}
