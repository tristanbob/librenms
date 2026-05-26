<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphExpression;
use LibreNMS\Graph\GraphMarkerDefinition;
use LibreNMS\Graph\GraphPlan;
use LibreNMS\Graph\GraphPlanDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\GraphVariableDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class SimpleStatsGraph extends GraphTemplate implements GraphPlanDefinition
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
    ) {
        parent::__construct($graphType, $title, $unit, $display + [
            'kind' => 'line',
            'area' => true,
        ]);
    }

    public function variables(): array
    {
        if ($this->graphType !== 'device_availability') {
            return [];
        }

        return [GraphVariableDefinition::integer('duration', 86400, 1)];
    }

    public function expressions(array $device, GraphQuery $query): GraphPlan
    {
        $timeDiff = $query->to - $query->from;
        $label = $this->label !== '' ? $this->label : $this->title;
        $base = $this->expression($query);
        $series = [
            new GraphSeriesDefinition(
                name: $label,
                key: $this->key('value'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 0, '663399'),
                area: true,
                areaOpacity: 0x33 / 0xff,
                expression: $base,
            ),
            new GraphSeriesDefinition(
                name: '1 hour avg',
                key: $this->key('1h'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 4, '3366BB'),
                expression: $this->expression($query, 3600),
            ),
        ];

        if ($timeDiff >= 129600) {
            $series[] = new GraphSeriesDefinition(
                name: '1 day avg',
                key: $this->key('1d'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 5, 'AA3355'),
                expression: $this->expression($query, 86400),
            );
        }

        if ($timeDiff >= 691200) {
            $series[] = new GraphSeriesDefinition(
                name: '1 week avg',
                key: $this->key('1w'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 6, '881177'),
                expression: $this->expression($query, 604800),
            );
        }

        return new GraphPlan($series, $this->percentileMarkers($base));
    }

    public function series(array $device, GraphQuery $query): array
    {
        $timeDiff = $query->to - $query->from;
        $label = $this->label !== '' ? $this->label : $this->title;
        $series = [
            new GraphSeriesDefinition(
                name: $label,
                key: $this->key('value'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 0, '663399'),
                area: true,
                areaOpacity: 0x33 / 0xff,
                bindings: [new RrdMetricBinding($this->resolvedRrdName($query), $this->ds, transform: $this->bindingTransform())],
            ),
            new GraphSeriesDefinition(
                name: '1 hour avg',
                key: $this->key('1h'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 4, '3366BB'),
                bindings: [new RrdMetricBinding($this->resolvedRrdName($query), $this->ds, step: 3600, transform: $this->bindingTransform())],
            ),
        ];

        if ($timeDiff >= 129600) {
            $series[] = new GraphSeriesDefinition(
                name: '1 day avg',
                key: $this->key('1d'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 5, 'AA3355'),
                bindings: [new RrdMetricBinding($this->resolvedRrdName($query), $this->ds, step: 86400, transform: $this->bindingTransform())],
            );
        }

        if ($timeDiff >= 691200) {
            $series[] = new GraphSeriesDefinition(
                name: '1 week avg',
                key: $this->key('1w'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 6, '881177'),
                bindings: [new RrdMetricBinding($this->resolvedRrdName($query), $this->ds, step: 604800, transform: $this->bindingTransform())],
            );
        }

        return $series;
    }

    public function display(): array
    {
        return parent::display();
    }

    private function expression(GraphQuery $query, ?int $step = null): GraphExpression
    {
        $expression = GraphExpression::def($this->resolvedRrdName($query), $this->ds, step: $step);
        if (is_numeric($this->transform)) {
            $expression = GraphExpression::scale($expression, (float) $this->transform);
        }

        return $expression;
    }

    private function resolvedRrdName(GraphQuery $query): string|array
    {
        if (is_callable($this->rrdName)) {
            return ($this->rrdName)($query);
        }

        return $this->rrdName;
    }

    private function bindingTransform(): mixed
    {
        if (is_callable($this->transform)) {
            return $this->transform;
        }
        if (is_numeric($this->transform)) {
            $factor = (float) $this->transform;

            return static fn ($value) => $value * $factor;
        }

        return null;
    }

    private function percentileMarkers(GraphExpression $expression): array
    {
        if (\App\Facades\LibrenmsConfig::get('graph_stat_percentile_disable')) {
            return [];
        }

        return [
            GraphMarkerDefinition::percentile('25th Percentile', $expression, 25, $this->paletteColor($this->palette, 1, '22CCBB')),
            GraphMarkerDefinition::percentile('50th Percentile', $expression, 50, $this->paletteColor($this->palette, 2, '00BBCC')),
            GraphMarkerDefinition::percentile('75th Percentile', $expression, 75, $this->paletteColor($this->palette, 3, '0099CC')),
        ];
    }

    private function key(string $suffix): string
    {
        return str_replace('-', '_', $this->graphType) . '_' . $suffix;
    }
}
