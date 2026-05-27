<?php

namespace LibreNMS\Graph\Definitions\Templates;

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
        private readonly string $vmKind = 'gauge',
        private readonly mixed $vmTransform = null,
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

    public function series(array $device, GraphQuery $query): array
    {
        $timeDiff    = $query->to - $query->from;
        $label       = $this->label !== '' ? $this->label : $this->title;
        $scale       = is_numeric($this->transform) ? (float) $this->transform : null;
        $rrdTransform = $scale !== null ? fn ($v) => $v * $scale : null;
        $binding     = fn (?int $step) => new RrdMetricBinding(
            rrdName:   $this->resolvedRrdName($query),
            ds:        $this->ds,
            step:      $step,
            transform: $rrdTransform,
        );

        $primaryRrd      = $binding(null);
        $primaryBindings = match (true) {
            $this->metric !== null && $this->vmKind === 'rate' => MetricSeries::rate($this->metric, $primaryRrd, null, $this->vmTransform),
            $this->metric !== null                             => MetricSeries::gauge($this->metric, $primaryRrd, $this->vmTransform),
            default                                            => [$primaryRrd],
        };

        $series = [
            new GraphSeriesDefinition(
                name:        $label,
                key:         $this->key('value'),
                unit:        $this->unit($device, $query),
                color:       $this->paletteColor($this->palette, 0, '663399'),
                area:        true,
                areaOpacity: 0x33 / 0xff,
                bindings:    $primaryBindings,
            ),
            new GraphSeriesDefinition(
                name:     '1 hour avg',
                key:      $this->key('1h'),
                unit:     $this->unit($device, $query),
                color:    $this->paletteColor($this->palette, 4, '3366BB'),
                bindings: [$binding(3600)],
            ),
        ];

        if ($timeDiff >= 129600) {
            $series[] = new GraphSeriesDefinition(
                name:     '1 day avg',
                key:      $this->key('1d'),
                unit:     $this->unit($device, $query),
                color:    $this->paletteColor($this->palette, 5, 'AA3355'),
                bindings: [$binding(86400)],
            );
        }

        if ($timeDiff >= 691200) {
            $series[] = new GraphSeriesDefinition(
                name:     '1 week avg',
                key:      $this->key('1w'),
                unit:     $this->unit($device, $query),
                color:    $this->paletteColor($this->palette, 6, '881177'),
                bindings: [$binding(604800)],
            );
        }

        return $series;
    }

    public function markers(array $device, GraphQuery $query): array
    {
        if (\App\Facades\LibrenmsConfig::get('graph_stat_percentile_disable')) {
            return [];
        }

        $inner = new RrdMetricBinding($this->resolvedRrdName($query), $this->ds);

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
