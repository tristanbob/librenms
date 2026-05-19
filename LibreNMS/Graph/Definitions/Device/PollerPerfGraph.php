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

class PollerPerfGraph
{
    public const GRAPH_TYPE = 'device_poller_perf';

    public function title(array $device): string
    {
        return 'Poller Performance';
    }

    public function unit(): string
    {
        return 'seconds';
    }

    public function rrdFile(array $device): string
    {
        return app(\LibreNMS\Data\Store\Rrd::class)->name($device['hostname'], 'poller-perf');
    }

    public function dataSource(): string
    {
        return 'poller';
    }

    public function seriesName(): string
    {
        return 'Poller time';
    }
}
