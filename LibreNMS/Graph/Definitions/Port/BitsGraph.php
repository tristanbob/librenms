<?php

/**
 * BitsGraph.php
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

namespace LibreNMS\Graph\Definitions\Port;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\SeriesDefinition;

class BitsGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'port_bits';

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $query->entities['port_id'];
    }

    public function title(array $device): string
    {
        return 'Traffic';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        $port = \App\Models\Port::findOrFail($query->entities['port_id']);

        return $device['hostname'] . ' ' . ($port->ifName ?: $port->ifDescr);
    }

    public function unit(): string
    {
        return 'bps';
    }

    public function series(array $device, GraphQuery $query): array
    {
        $portId  = $query->entities['port_id'];
        $rrd     = app(\LibreNMS\Data\Store\Rrd::class);
        $rrdFile = $rrd->name($device['hostname'], $rrd->portName($portId));
        $toBits  = fn ($v) => $v * 8;

        return [
            new SeriesDefinition(
                name: 'In',
                key: 'bits_in',
                rrdFile: $rrdFile,
                ds: 'INOCTETS',
                color: '90B040',
                lineColor: '608720',
                area: true,
                transform: $toBits,
            ),
            new SeriesDefinition(
                name: 'Out',
                key: 'bits_out',
                rrdFile: $rrdFile,
                ds: 'OUTOCTETS',
                color: '8080C0',
                lineColor: '606090',
                area: true,
                transform: $toBits,
                negate: true,
            ),
        ];
    }
}
