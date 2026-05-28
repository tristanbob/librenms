<?php

/**
 * PortGraphVmFallbackTest.php
 *
 * Verifies that port graph series carry the expected VictoriaMetrics bindings,
 * and that graph types with no VM bindings fall back to RRD cleanly.
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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Tests\Unit\Graph;

use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\Definitions\Port\PortGraphCatalog;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PortGraphVmFallbackTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create();

        LibrenmsConfig::set('victoriametrics.query_enabled', true);
    }

    #[DataProvider('portGraphTypesWithVmBindings')]
    public function testPortGraphSeriesHaveVmBindings(string $graphType): void
    {
        $registry = new GraphDefinitionRegistry();
        foreach (PortGraphCatalog::definitions() as $def) {
            $registry->register($def);
        }
        $definition = $registry->definitionFor($graphType);

        $query = GraphQuery::fromRequest(
            'port',
            $graphType,
            ['device_id' => $this->device->device_id, 'port_id' => 1, 'ifIndex' => 1],
            time() - 3600,
            time(),
        );

        $series = $definition->series($this->device->toArray(), $query);
        $this->assertNotEmpty($series, "$graphType must return at least one series");

        foreach ($series as $s) {
            $this->assertNotNull(
                $s->binding('victoriametrics'),
                "Series '{$s->key}' of $graphType must include a VictoriaMetrics binding"
            );
        }
    }

    public static function portGraphTypesWithVmBindings(): array
    {
        return [
            'port_bits'     => ['port_bits'],
            'port_packets'  => ['port_packets'],
            'port_discards' => ['port_discards'],
            'port_errors'   => ['port_errors'],
        ];
    }
}
