<?php

/**
 * DiskIoGraph.php
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

use App\Facades\LibrenmsConfig;
use App\Models\DiskIo;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class DiskIoGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'device_diskio';

    // Matches graph_colours.greens / graph_colours.blues from config_definitions.json
    private const GREENS = ['CAE853', 'B2D849', '94C63D', '75BA30', '49A81E', '0C990C'];
    private const BLUES  = ['A9A9F2', '9696DD', '8080C9', '6A6AB7', '5151A3', '3D3D99'];

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string { return 'Disk I/O'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string { return 'ops/s'; }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $disks = DiskIo::where('device_id', $device['device_id'])
            ->orderBy('diskio_descr')
            ->get();

        // Mirror the generic_multi_seperated stacked/negate behaviour:
        // stacked=true  → mirror style (both positive, area with 53% opacity)
        // stacked=false → reads up / writes negated (below 0), full opacity
        $mirrorStacked = (bool) LibrenmsConfig::get('webui.graph_stacked', false);
        $areaOpacity   = $mirrorStacked ? (0x88 / 0xff) : 1.0;

        $readSeries  = [];
        $writeSeries = [];

        foreach ($disks as $i => $disk) {
            $readColor  = self::GREENS[$i % count(self::GREENS)];
            $writeColor = self::BLUES[$i % count(self::BLUES)];
            $rrdName    = ['ucd_diskio', $disk->diskio_descr];

            $readSeries[] = new GraphSeriesDefinition(
                name:        $disk->diskio_descr . ' Reads',
                key:         'diskio_' . $disk->diskio_id . '_reads',
                unit:        'ops/s',
                area:        true,
                color:       $readColor,
                lineColor:   $readColor,
                areaOpacity: $areaOpacity,
                lineOpacity: $areaOpacity,
                stack:       'diskio_reads',
                bindings:    [new RrdMetricBinding($rrdName, 'reads')],
            );

            $writeSeries[] = new GraphSeriesDefinition(
                name:        $disk->diskio_descr . ' Writes',
                key:         'diskio_' . $disk->diskio_id . '_writes',
                unit:        'ops/s',
                area:        true,
                color:       $writeColor,
                lineColor:   $writeColor,
                areaOpacity: $areaOpacity,
                lineOpacity: $areaOpacity,
                stack:       'diskio_writes',
                negate:      ! $mirrorStacked,
                bindings:    [new RrdMetricBinding($rrdName, 'writes')],
            );
        }

        // Reads first (positive), then writes (negative) — matches RRD render order
        return [...$readSeries, ...$writeSeries];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    public function thresholds(array $device, GraphQuery $query): array { return []; }
}
