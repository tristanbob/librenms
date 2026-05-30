<?php

/**
 * StackedAreaGraph.php
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

class StackedAreaGraph extends GraphTemplate
{
    /**
     * @param list<array{ds:string,label:string,invert?:bool,color?:string,metric?:string}> $series
     */
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly string|array $rrdName,
        private readonly array $series,
        private readonly string $palette = 'mixed',
        array $display = [],
    ) {
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line', 'area' => true, 'stacked' => true]);
    }

    public function series(GraphContext $context): array
    {
        $normal = [];
        $inverted = [];
        foreach ($this->series as $i => $def) {
            $invert = (bool) ($def['invert'] ?? false);
            $color = $def['color'] ?? $this->paletteColor($this->palette, $i, 'CC0000');
            $rrd = new RrdMetricBinding($this->rrdName, $def['ds']);
            $bindings = isset($def['metric'])
                ? MetricSeries::metric($def['metric'], $rrd)
                : [$rrd];
            $item = new GraphSeriesDefinition(
                name: $def['label'],
                key: str_replace('-', '_', $this->graphType) . '_' . $def['ds'],
                unit: $this->unit($context),
                color: $color,
                lineColor: $color,
                area: true,
                areaOpacity: \App\Facades\LibrenmsConfig::get('webui.graph_stacked') == true ? 0x88 / 0xff : 1.0,
                stack: $invert ? $this->graphType . '_out' : $this->graphType . '_in',
                negate: $invert && $this->stackedMultiplier() < 0,
                bindings: $bindings,
            );
            if ($invert) {
                $inverted[] = $item;
            } else {
                $normal[] = $item;
            }
        }

        return [...$normal, ...$inverted];
    }
}
