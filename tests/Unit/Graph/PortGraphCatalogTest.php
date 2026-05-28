<?php

/**
 * PortGraphCatalogTest.php
 *
 * Verifies that PortGraphCatalog produces correctly typed graph definitions
 * with the expected graph types, entity type, and binding structure.
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
use LibreNMS\Graph\Definitions\Templates\PortLineGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\DBTestCase;

final class PortGraphCatalogTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;
    private array $definitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device      = Device::factory()->create();
        $this->definitions = PortGraphCatalog::definitions();
    }

    public function testCatalogReturnsFourDefinitions(): void
    {
        $this->assertCount(4, $this->definitions);
    }

    public function testAllDefinitionsArePortLineGraphInstances(): void
    {
        foreach ($this->definitions as $def) {
            $this->assertInstanceOf(PortLineGraph::class, $def);
        }
    }

    public function testGraphTypes(): void
    {
        $types = array_map(fn ($d) => $d->graphType(), $this->definitions);
        $this->assertSame(['port_bits', 'port_packets', 'port_discards', 'port_errors'], $types);
    }

    public function testEntityTypeIsPort(): void
    {
        foreach ($this->definitions as $def) {
            $this->assertSame('port', $def->entityType(), "{$def->graphType()} entityType must be 'port'");
        }
    }

    public function testIdIncludesPortId(): void
    {
        foreach ($this->definitions as $def) {
            $query = $this->makeQuery($def->graphType());
            $this->assertSame($def->graphType() . ':42', $def->id($this->device->toArray(), $query));
        }
    }

    public function testSubtitleIncludesHostnameAndPortName(): void
    {
        foreach ($this->definitions as $def) {
            $query    = $this->makeQuery($def->graphType());
            $subtitle = $def->subtitle($this->device->toArray(), $query);
            $this->assertStringContainsString($this->device->hostname, $subtitle);
            $this->assertStringContainsString('eth0', $subtitle);
        }
    }

    public function testAllSeriesHaveVmBindings(): void
    {
        foreach ($this->definitions as $def) {
            $query  = $this->makeQuery($def->graphType());
            $series = $def->series($this->device->toArray(), $query);
            $this->assertNotEmpty($series, "{$def->graphType()} must return at least one series");

            foreach ($series as $s) {
                $this->assertNotNull(
                    $s->binding('victoriametrics'),
                    "Series '{$s->key}' of {$def->graphType()} must have a VictoriaMetrics binding"
                );
            }
        }
    }

    public function testPortBitsHasTwoSeries(): void
    {
        $def    = $this->definitionFor('port_bits');
        $query  = $this->makeQuery('port_bits');
        $series = $def->series($this->device->toArray(), $query);

        $this->assertCount(2, $series);
        $this->assertSame('bits_in', $series[0]->key);
        $this->assertSame('bits_out', $series[1]->key);
        $this->assertFalse($series[0]->negate);
        $this->assertTrue($series[1]->negate);
    }

    public function testPortBitsMarkersWithDefaultPercentile(): void
    {
        LibrenmsConfig::set('percentile_value', 95);

        $def     = $this->definitionFor('port_bits');
        $query   = $this->makeQuery('port_bits');
        $markers = $def->markers($this->device->toArray(), $query);

        $this->assertCount(4, $markers, 'port_bits must return 4 percentile markers at default percentile');
    }

    public function testPortBitsMarkersEmptyWhenPercentileZero(): void
    {
        LibrenmsConfig::set('percentile_value', 0);

        $def     = $this->definitionFor('port_bits');
        $query   = $this->makeQuery('port_bits');
        $markers = $def->markers($this->device->toArray(), $query);

        $this->assertEmpty($markers, 'port_bits must return no markers when percentile_value is 0');
    }

    public function testPortErrorsHasFourSeries(): void
    {
        $def    = $this->definitionFor('port_errors');
        $query  = $this->makeQuery('port_errors');
        $series = $def->series($this->device->toArray(), $query);

        $this->assertCount(4, $series);
    }

    public function testPortPacketsMarkersEmpty(): void
    {
        $def     = $this->definitionFor('port_packets');
        $query   = $this->makeQuery('port_packets');
        $markers = $def->markers($this->device->toArray(), $query);

        $this->assertEmpty($markers);
    }

    private function makeQuery(string $graphType): GraphQuery
    {
        return GraphQuery::fromRequest(
            'port',
            $graphType,
            ['device_id' => $this->device->device_id, 'port_id' => 42, 'port_name' => 'eth0', 'ifIndex' => 1],
            time() - 3600,
            time(),
        );
    }

    private function definitionFor(string $graphType): PortLineGraph
    {
        foreach ($this->definitions as $def) {
            if ($def->graphType() === $graphType) {
                return $def;
            }
        }
        $this->fail("No definition found for graph type '$graphType'");
    }
}
