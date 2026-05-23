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
        return 'Errors';
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $device['hostname'] . ' ' . $portName;
    }

    public function unit(): string
    {
        return 'eps';
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
                key:       'errors_in',
                unit:      $this->unit(),
                color:     'CC2222',
                lineColor: '991111',
                area:      true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'INERRORS'),
                ],
            ),
            new GraphSeriesDefinition(
                name:      'Out',
                key:       'errors_out',
                unit:      $this->unit(),
                color:     'FF7744',
                lineColor: 'CC4411',
                area:      true,
                negate:    true,
                bindings:  [
                    new RrdMetricBinding(rrdName: $rrdName, ds: 'OUTERRORS'),
                ],
            ),
        ];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

    public function thresholds(array $device, GraphQuery $query): array
    {
        return [];
    }
}
