<?php

/**
 * DerivedSeriesGraph.php
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

class DerivedSeriesGraph extends GraphTemplate
{
    /**
     * @param list<array{name:string,key:string,ds:string|array,transform?:mixed,color:string,lineColor?:string,area?:bool,stack?:string,negate?:bool,lineWidth?:float,metric?:string,vm_expression?:\Closure,vm_label_keys?:string[]}> $series
     */
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly string|array $rrdName,
        private readonly array $series,
        array $display = [],
    ) {
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line']);
    }

    public function series(GraphContext $context): array
    {
        $series = [];
        foreach ($this->series as $def) {
            $rrd = new RrdMetricBinding($this->rrdName, $def['ds'], transform: $def['transform'] ?? null);
            if (isset($def['vm_expression'])) {
                $bindings = MetricSeries::expression($rrd, $def['vm_expression'], $def['vm_label_keys'] ?? ['hostname']);
            } elseif (isset($def['metric']) && is_string($def['ds'])) {
                $bindings = MetricSeries::metric($def['metric'], $rrd, transform: $def['transform'] ?? null);
            } else {
                $bindings = [$rrd];
            }

            $series[] = new GraphSeriesDefinition(
                name: $def['name'],
                key: $def['key'],
                unit: $this->unit($context),
                color: $def['color'],
                lineColor: $def['lineColor'] ?? null,
                area: (bool) ($def['area'] ?? false),
                stack: $def['stack'] ?? null,
                negate: (bool) ($def['negate'] ?? false),
                lineWidth: (float) ($def['lineWidth'] ?? 1.25),
                bindings: $bindings,
            );
        }

        return $series;
    }
}
