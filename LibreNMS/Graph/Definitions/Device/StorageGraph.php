<?php

/**
 * StorageGraph.php
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

namespace LibreNMS\Graph\Definitions\Device;

use App\Models\Storage;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class StorageGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'device_storage';

    // Matches the iter-based 7-color cycle in includes/html/graphs/device/storage.inc.php
    private const COLORS = ['CC0000', '008C00', '4096EE', '73880A', 'D01F3C', '36393D', 'FF0084'];

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string { return 'Disk Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => false, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $storages  = Storage::where('device_id', $device['device_id'])
            ->orderBy('storage_descr')
            ->get();
        $usedEntry = VictoriaMetricsMetricCatalog::get('storage.used');
        $freeEntry = VictoriaMetricsMetricCatalog::get('storage.free');

        return $storages->values()->map(function (Storage $storage, int $i) use ($usedEntry, $freeEntry) {
            $color        = self::COLORS[$i % count(self::COLORS)];
            $storageType  = $storage->type;
            $storageDescr = $storage->storage_descr;

            return new GraphSeriesDefinition(
                name:      $storage->storage_descr,
                key:       'storage_' . $storage->storage_id,
                unit:      '%',
                area:      false,
                color:     $color,
                lineWidth: 1.25,
                bindings:  MetricSeries::expression(
                    new RrdMetricBinding(
                        rrdName:   ['storage', $storage->type, $storage->storage_descr],
                        ds:        ['used', 'free'],
                        transform: fn (array $v) => ($v['used'] + $v['free']) > 0
                            ? ($v['used'] / ($v['used'] + $v['free']) * 100)
                            : null,
                    ),
                    function (array $entities) use ($storageType, $storageDescr, $usedEntry, $freeEntry): string {
                        $labels = [
                            'hostname' => $entities['hostname'],
                            'type'     => $storageType,
                            'descr'    => $storageDescr,
                        ];
                        $usedSel = VictoriaMetricsGraphDataProvider::buildSelector($usedEntry->definition->name, $usedEntry->identityLabels, $labels);
                        $freeSel = VictoriaMetricsGraphDataProvider::buildSelector($freeEntry->definition->name, $freeEntry->identityLabels, $labels);

                        return "100 * {$usedSel} / ({$usedSel} + {$freeSel})";
                    },
                    ['hostname'],
                ),
            );
        })->all();
    }

    public function markers(array $device, GraphQuery $query): array { return []; }
}
