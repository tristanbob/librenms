<?php

/**
 * PortLineGraph.php
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

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

/**
 * Template for entity-level port graphs. Bakes in port conventions:
 * entityType='port', id from port_id, subtitle from hostname+port_name,
 * rrdName from "port-id{port_id}". Series are driven by a declarative
 * $seriesDefs array; optional $markerBuilder handles dynamic markers.
 *
 * Series def keys: name, key, ds, color, lineColor?, metric?, vmKind? (default 'rate'),
 *                  transform? (RRD-only), area? (default true), negate? (default false), stack?
 */
class PortLineGraph extends GraphTemplate
{
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly array $seriesDefs,
        array $display = [],
        private readonly ?\Closure $markerBuilder = null,
    ) {
        parent::__construct($graphType, $title, $unit, $display);
    }

    public function entityType(): string
    {
        return 'port';
    }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType . ':' . $query->entities['port_id'];
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $device['hostname'] . ' ' . $portName;
    }

    public function display(): array
    {
        return $this->display + ['kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $rrdName = 'port-id' . $query->entities['port_id'];

        return array_map(function (array $def) use ($device, $query, $rrdName) {
            $rrd = new RrdMetricBinding(
                rrdName:   $rrdName,
                ds:        $def['ds'],
                transform: $def['transform'] ?? null,
            );
            $vmKind   = $def['vmKind'] ?? 'rate';
            $bindings = isset($def['metric'])
                ? ($vmKind === 'gauge'
                    ? [...MetricSeries::gauge($def['metric'], $rrd)]
                    : [...MetricSeries::rate($def['metric'], $rrd)])
                : [$rrd];

            return new GraphSeriesDefinition(
                name:      $def['name'],
                key:       $def['key'],
                unit:      $this->unit($device, $query),
                color:     $def['color'],
                lineColor: $def['lineColor'] ?? null,
                area:      $def['area'] ?? true,
                negate:    $def['negate'] ?? false,
                stack:     $def['stack'] ?? null,
                bindings:  $bindings,
            );
        }, $this->seriesDefs);
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return $this->markerBuilder ? ($this->markerBuilder)($device, $query) : [];
    }
}
