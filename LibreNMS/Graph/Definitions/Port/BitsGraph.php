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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Graph\Definitions\Port;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsMetricBinding;

class BitsGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'port_bits';

    public function graphType(): string
    {
        return self::GRAPH_TYPE;
    }

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
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $device['hostname'] . ' ' . $portName;
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return 'bps';
    }

    public function entityType(): string
    {
        return 'port';
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $portId  = $query->entities['port_id'];
        $rrdName = "port-id$portId";
        $toBits  = fn ($v) => $v * 8;

        return [
            new GraphSeriesDefinition(
                name:      'In',
                key:       'bits_in',
                unit:      $this->unit($device, $query),
                color:     '90B040',
                lineColor: '608720',
                area:      true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INOCTETS', transform: $toBits),
                    new VictoriaMetricsMetricBinding(
                        metricName: 'librenms_port_if_in_bits_per_second',
                        labelKeys:  ['device_id', 'port_id'],
                    ),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Out',
                key:       'bits_out',
                unit:      $this->unit($device, $query),
                color:     '8080C0',
                lineColor: '606090',
                area:      true,
                negate:    true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTOCTETS', transform: $toBits),
                    new VictoriaMetricsMetricBinding(
                        metricName: 'librenms_port_if_out_bits_per_second',
                        labelKeys:  ['device_id', 'port_id'],
                    ),
                ],
            ),
        ];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

}
