<?php

/**
 * DiscardsGraph.php
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

class DiscardsGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'port_discards';

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
        return 'Discards';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $device['hostname'] . ' ' . $portName;
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return 'dps';
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

        return [
            new GraphSeriesDefinition(
                name:      'In',
                key:       'discards_in',
                unit:      $this->unit($device, $query),
                color:     'FF8800',
                lineColor: 'CC6600',
                area:      true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INDISCARDS'),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Out',
                key:       'discards_out',
                unit:      $this->unit($device, $query),
                color:     'FFBB44',
                lineColor: 'CC8811',
                area:      true,
                negate:    true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTDISCARDS'),
                ],
            ),
        ];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

}
