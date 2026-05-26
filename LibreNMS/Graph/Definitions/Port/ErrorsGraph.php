<?php

/**
 * ErrorsGraph.php
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

class ErrorsGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'port_errors';

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
        return 'Errors & Discards';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $device['hostname'] . ' ' . $portName;
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return 'pps';
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
                name:      'Errors In',
                key:       'errors_in',
                unit:      $this->unit($device, $query),
                color:     'FF3300',
                lineColor: 'CC2200',
                area:      true,
                stack:     'in',
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INERRORS'),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Errors Out',
                key:       'errors_out',
                unit:      $this->unit($device, $query),
                color:     'FF6633',
                lineColor: 'CC4411',
                area:      true,
                stack:     'out',
                negate:    true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTERRORS'),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Discards In',
                key:       'discards_in',
                unit:      $this->unit($device, $query),
                color:     '805080',
                lineColor: '603060',
                area:      true,
                stack:     'in',
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INDISCARDS'),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Discards Out',
                key:       'discards_out',
                unit:      $this->unit($device, $query),
                color:     'C0A060',
                lineColor: '907030',
                area:      true,
                stack:     'out',
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
