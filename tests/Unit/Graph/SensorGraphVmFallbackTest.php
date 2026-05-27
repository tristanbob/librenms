<?php

/**
 * SensorGraphVmFallbackTest.php
 *
 * Verifies that wireless sensor graph families remain usable when
 * VictoriaMetrics query mode is enabled but no VM bindings exist yet.
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

    public function testWirelessSensorGraphSeriesHasVmBinding(): void
    {
        $registry = new GraphDefinitionRegistry();
        $registry->registerResolver(new WirelessGraphDefinitionResolver());

        $definition = $registry->definitionFor('wireless_rssi');
        $this->assertNotNull($definition, 'wireless_rssi must be registered');

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

        $series = $definition->series($this->device->toArray(), $query);
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
