<?php

/**
 * PollerPerfGraph.php
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
 * @copyright  2024 LibreNMS Contributors
 */

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\SeriesDefinition;

class PollerPerfGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'device_poller_perf';

    private const PALETTE = 'rainbow_stats_purple';

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string
    {
        return 'Poller Time';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'];
    }

    public function unit(): string
    {
        return 'seconds';
    }

    public function series(array $device, GraphQuery $query): array
    {
        $rrdFile  = app(\LibreNMS\Data\Store\Rrd::class)->name($device['hostname'], 'poller-perf');
        $timeDiff = $query->to - $query->from;
        $p        = self::PALETTE;

        $series = [
            new SeriesDefinition(
                name:        'Poller time',
                key:         'poller_time',
                rrdFile:     $rrdFile,
                ds:          'poller',
                color:       LibrenmsConfig::get("graph_colours.$p.0"),
                area:        true,
                areaOpacity: 0.2,
            ),
            new SeriesDefinition(
                name:    '1 hour avg',
                key:     'poller_time_1h',
                rrdFile: $rrdFile,
                ds:      'poller',
                color:   LibrenmsConfig::get("graph_colours.$p.4"),
                step:    3600,
            ),
        ];

        // Mirrors generic_stats.inc.php: daily line only shown for windows > ~36 hours
        if ($timeDiff >= 129600) {
            $series[] = new SeriesDefinition(
                name:    '1 day avg',
                key:     'poller_time_1d',
                rrdFile: $rrdFile,
                ds:      'poller',
                color:   LibrenmsConfig::get("graph_colours.$p.5"),
                step:    86400,
            );
        }

        // Weekly line only shown for windows > 8 days
        if ($timeDiff >= 691200) {
            $series[] = new SeriesDefinition(
                name:    '1 week avg',
                key:     'poller_time_1w',
                rrdFile: $rrdFile,
                ds:      'poller',
                color:   LibrenmsConfig::get("graph_colours.$p.6"),
                step:    604800,
            );
        }

        return $series;
    }
}
