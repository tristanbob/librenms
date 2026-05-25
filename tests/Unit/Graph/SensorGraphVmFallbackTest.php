<?php

/**
 * SensorGraphVmFallbackTest.php
 *
 * Verifies that sensor graph families remain usable when VictoriaMetrics query
 * mode is enabled but no sensor VM bindings exist yet.
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

namespace LibreNMS\Tests\Unit\Graph;

use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\Definitions\Sensor\SensorGraphDefinitionResolver;
use LibreNMS\Graph\Definitions\Wireless\WirelessGraphDefinitionResolver;
use LibreNMS\Graph\GraphDataBackendSelector;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SensorGraphVmFallbackTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;
    private GraphDataBackendSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create();

        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new SensorGraphDefinitionResolver());
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $vm = new VictoriaMetricsGraphDataProvider($registry);
        $rrd = new class implements GraphDataProvider {
            public function query(GraphQuery $query): GraphDataResult
            {
                $result = new GraphDataResult(
                    id: $query->graphType . ':' . ($query->entities['sensor_id'] ?? 0),
                    type: $query->graphType,
                    title: 'Sensor',
                    subtitle: 'stub',
                    unit: '',
                    from: $query->from,
                    to: $query->to,
                    step: $query->step,
                );
                $result->setSource('rrd');

                return $result;
            }
        };

        $this->selector = new GraphDataBackendSelector($rrd, $vm);
        LibrenmsConfig::set('victoriametrics.query_enabled', true);
    }

    #[DataProvider('sensorGraphTypesWithoutVmBindings')]
    public function testFallsBackToRrdForSensorGraphFamiliesWithoutVmBindings(string $scope, string $graphType, array $entities): void
    {
        $query = GraphQuery::fromRequest(
            $scope,
            $graphType,
            ['device_id' => $this->device->device_id] + $entities,
            time() - 3600,
            time(),
        );

        $result = $this->selector->query($query);
        $arr = $result->toArray();

        $this->assertSame('rrd', $arr['graph']['meta']['source']);
        $this->assertTrue($arr['graph']['meta']['fallback_used']);
        $this->assertNotEmpty($arr['graph']['meta']['warnings']);
    }

    public static function sensorGraphTypesWithoutVmBindings(): array
    {
        return [
            'sensor_temperature' => ['sensor', 'sensor_temperature', [
                'sensor_id' => 1,
                'sensor_class' => 'temperature',
                'sensor_type' => 'dummy',
                'sensor_index' => 1,
                'sensor_descr' => 'Temperature',
                'poller_type' => 'snmp',
            ]],
            'wireless_rssi' => ['wireless_sensor', 'wireless_rssi', [
                'sensor_id' => 2,
                'sensor_class' => 'rssi',
                'sensor_type' => 'dummy',
                'sensor_index' => 2,
                'sensor_descr' => 'RSSI',
            ]],
        ];
    }
}
