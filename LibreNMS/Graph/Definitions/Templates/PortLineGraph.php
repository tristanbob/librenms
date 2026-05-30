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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

/**
 * Template for entity-level port graphs. Bakes in port conventions:
 * entityType='port', id from port_id, subtitle from hostname+port_name,
 * rrdName from "port-id{port_id}". Series are driven by a declarative
 * $seriesDefs array; optional $markerBuilder handles dynamic markers.
 *
 * Series def keys: name, key, ds, color, lineColor?, metric? (kind derived from catalog),
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

    public function id(GraphContext $context): string
    {
        return $this->graphType . ':' . $context->query->entities['port_id'];
    }

    public function subtitle(GraphContext $context): string
    {
        $query    = $context->query;
        $portName = $query->entities['port_name'] ?? ('port ' . ($query->entities['port_id'] ?? '?'));

        return $context['hostname'] . ' ' . $portName;
    }

    public function display(): array
    {
        return $this->display + ['kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true];
    }

    public function series(GraphContext $context): array
    {
        $rrdName = 'port-id' . $context->query->entities['port_id'];

        return array_map(function (array $def) use ($context, $rrdName) {
            $rrd = new RrdMetricBinding(
                rrdName:   $rrdName,
                ds:        $def['ds'],
                transform: $def['transform'] ?? null,
            );
            $bindings = isset($def['metric'])
                ? MetricSeries::metric($def['metric'], $rrd)
                : [$rrd];

            return new GraphSeriesDefinition(
                name:      $def['name'],
                key:       $def['key'],
                unit:      $this->unit($context),
                color:     $def['color'],
                lineColor: $def['lineColor'] ?? null,
                area:      $def['area'] ?? true,
                negate:    $def['negate'] ?? false,
                stack:     $def['stack'] ?? null,
                bindings:  $bindings,
            );
        }, $this->seriesDefs);
    }

    public function markers(GraphContext $context): array
    {
        return $this->markerBuilder ? ($this->markerBuilder)($context) : [];
    }
}
