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
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class StorageGraph implements GraphDefinition
{
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
        $storages = Storage::where('device_id', $device['device_id'])
            ->orderBy('storage_descr')
            ->get();

        return $storages->values()->map(function (Storage $storage, int $i) {
            $color = self::COLORS[$i % count(self::COLORS)];

            return new GraphSeriesDefinition(
                name:      $storage->storage_descr,
                key:       'storage_' . $storage->storage_id,
                unit:      '%',
                area:      false,
                color:     $color,
                lineWidth: 1.25,
                bindings:  [new RrdMetricBinding(
                    rrdName:   ['storage', $storage->type, $storage->storage_descr],
                    ds:        ['used', 'free'],
                    transform: fn (array $v) => ($v['used'] + $v['free']) > 0
                        ? ($v['used'] / ($v['used'] + $v['free']) * 100)
                        : null,
                )],
            );
        })->all();
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    public function thresholds(array $device, GraphQuery $query): array { return []; }
}
