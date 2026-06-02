<?php

/**
 * SimpleStatsGraph.php
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
use LibreNMS\Graph\GraphMarkerDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\GraphVariableDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class SimpleStatsGraph extends GraphTemplate
{
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly mixed $rrdName,
        private readonly string $ds,
        private readonly string $label = '',
        private readonly string $palette = 'rainbow_stats_purple',
        private readonly mixed $transform = null,
        array $display = [],
        private readonly array $graphVariables = [],
        private readonly ?string $metric = null,
        private readonly mixed $vmTransform = null,
        private readonly mixed $vmExprBuilder = null,
    ) {
        parent::__construct($graphType, $title, $unit, $display + [
            'kind' => 'line',
            'area' => true,
        ]);
    }

    public function variables(): array
    {
        return $this->graphVariables;
    }

    public function series(GraphContext $context): array
    {
        $query = $context->query;
        $label = $this->label !== '' ? $this->label : $this->title;
        $scale = is_numeric($this->transform) ? (float) $this->transform : null;
        $rrdTransform = $scale !== null ? fn ($v) => $v * $scale : null;
        $binding = fn (?int $step) => new RrdMetricBinding(
            rrdName:   $this->resolvedRrdName($query),
            ds:        $this->ds,
            step:      $step,
            transform: $rrdTransform,
        );

        $primaryRrd = $binding(null);
        $primaryBindings = match (true) {
            $this->vmExprBuilder !== null => ($this->vmExprBuilder)($primaryRrd, $query),
            $this->metric !== null        => MetricSeries::metric($this->metric, $primaryRrd, transform: $this->vmTransform),
            default                       => [$primaryRrd],
        };

        $series = [
            new GraphSeriesDefinition(
                name:        $label,
                key:         $this->key('value'),
                unit:        $this->unit($context),
                color:       $this->paletteColor($this->palette, 0, '663399'),
                area:        true,
                areaOpacity: 0x33 / 0xff,
                bindings:    $primaryBindings,
            ),
        ];

        return array_merge($series, $this->trailingAverageSeries(
            $context,
            str_replace('-', '_', $this->graphType),
            $this->palette,
            fn (int $step) => [$binding($step)],
        ));
    }

    public function markers(GraphContext $context): array
    {
        if (\App\Facades\LibrenmsConfig::get('graph_stat_percentile_disable')) {
            return [];
        }

        $inner = new RrdMetricBinding($this->resolvedRrdName($context->query), $this->ds);

        return [
            GraphMarkerDefinition::percentile('25th Percentile', $inner, 25, $this->paletteColor($this->palette, 1, '22CCBB')),
            GraphMarkerDefinition::percentile('50th Percentile', $inner, 50, $this->paletteColor($this->palette, 2, '00BBCC')),
            GraphMarkerDefinition::percentile('75th Percentile', $inner, 75, $this->paletteColor($this->palette, 3, '0099CC')),
        ];
    }

    private function resolvedRrdName(GraphQuery $query): string|array
    {
        if (is_callable($this->rrdName)) {
            return ($this->rrdName)($query);
        }

        return $this->rrdName;
    }

    private function key(string $suffix): string
    {
        return str_replace('-', '_', $this->graphType) . '_' . $suffix;
    }
}
