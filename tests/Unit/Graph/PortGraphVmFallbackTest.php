<?php

/**
 * PortGraphVmFallbackTest.php
 *
 * Verifies that when VictoriaMetrics query is enabled, graph types that have no
 * VM bindings (port_packets, port_errors, port_discards) are handled by RRD
 * without marking the response as a VictoriaMetrics failure fallback.
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
use LibreNMS\Graph\Definitions\Port\DiscardsGraph;
use LibreNMS\Graph\Definitions\Port\ErrorsGraph;
use LibreNMS\Graph\Definitions\Port\PacketsGraph;
use LibreNMS\Graph\GraphDataBackendSelector;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PortGraphVmFallbackTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;
    private GraphDataBackendSelector $selector;
    private GraphDataResult $rrdResult;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create();

        $registry = new GraphDefinitionRegistry([
            PacketsGraph::class,
            ErrorsGraph::class,
            DiscardsGraph::class,
        ]);

        $vm = new VictoriaMetricsGraphDataProvider($registry);

        $rrdResult = new GraphDataResult(
            id: 'stub', type: 'stub', title: 'Stub', subtitle: 'stub',
            unit: 'pps', from: time() - 3600, to: time(), step: 300,
        );
        $rrdResult->setSource('rrd');
        $this->rrdResult = $rrdResult;

        $rrd = new class($rrdResult) implements GraphDataProvider {
            public function __construct(private readonly GraphDataResult $result) {}

            public function query(GraphQuery $query): GraphDataResult
            {
                return $this->result;
            }
        };

        $this->selector = new GraphDataBackendSelector($rrd, $vm);

        LibrenmsConfig::set('victoriametrics.query_enabled', true);
    }

    #[DataProvider('portGraphTypesWithoutVmBindings')]
    public function testFallsBackToRrdForGraphTypeWithNoVmBindings(string $graphType): void
    {
        $query = GraphQuery::fromRequest(
            'port',
            $graphType,
            ['device_id' => $this->device->device_id, 'port_id' => 1],
            time() - 3600,
            time(),
        );

        $result = $this->selector->query($query);
        $arr    = $result->toArray();

        $this->assertSame('rrd', $arr['graph']['meta']['source'],
            "Expected RRD source for $graphType");
        $this->assertFalse($arr['graph']['meta']['fallback_used'],
            "Expected fallback_used=false for $graphType when VM has no bindings");
        $this->assertEmpty($arr['graph']['meta']['warnings'],
            "Expected no warning when $graphType has no VM bindings");
    }

    public static function portGraphTypesWithoutVmBindings(): array
    {
        return [
            'port_packets' => ['port_packets'],
            'port_errors'  => ['port_errors'],
            'port_discards' => ['port_discards'],
        ];
    }
}
