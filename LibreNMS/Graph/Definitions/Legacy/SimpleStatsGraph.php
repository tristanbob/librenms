<?php

namespace LibreNMS\Graph\Definitions\Legacy;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class SimpleStatsGraph extends LegacyGraph
{
    public function __construct(
        string $graphType,
        string $title,
        string $unit,
        private readonly string|array $rrdName,
        private readonly string $ds,
        private readonly string $label = '',
        private readonly string $palette = 'rainbow_stats_purple',
        private readonly mixed $transform = null,
        array $display = [],
    ) {
        parent::__construct($graphType, $title, $unit, $display + [
            'kind' => 'line',
            'area' => true,
            'legacyPercentiles' => true,
        ]);
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
                bindings: [new RrdMetricBinding($this->rrdName, $this->ds, transform: $this->transform)],
            ),
            new GraphSeriesDefinition(
                name: '1 hour avg',
                key: $this->key('1h'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 4, '3366BB'),
                bindings: [new RrdMetricBinding($this->rrdName, $this->ds, step: 3600, transform: $this->transform)],
            ),
        ];

        if ($timeDiff >= 129600) {
            $series[] = new GraphSeriesDefinition(
                name: '1 day avg',
                key: $this->key('1d'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 5, 'AA3355'),
                bindings: [new RrdMetricBinding($this->rrdName, $this->ds, step: 86400, transform: $this->transform)],
            );
        }

        if ($timeDiff >= 691200) {
            $series[] = new GraphSeriesDefinition(
                name: '1 week avg',
                key: $this->key('1w'),
                unit: $this->unit($device, $query),
                color: $this->paletteColor($this->palette, 6, '881177'),
                bindings: [new RrdMetricBinding($this->rrdName, $this->ds, step: 604800, transform: $this->transform)],
            );
        }

        return $series;
    }

    public function display(): array
    {
        $display = parent::display();
        if (\App\Facades\LibrenmsConfig::get('graph_stat_percentile_disable')) {
            unset($display['legacyPercentiles'], $display['legacyPercentileColors']);

            return $display;
        }

        $display['legacyPercentiles'] = true;
        $display['legacyPercentileColors'] = [
            $this->paletteColor($this->palette, 1, '22CCBB'),
            $this->paletteColor($this->palette, 2, '00BBCC'),
            $this->paletteColor($this->palette, 3, '0099CC'),
        ];

        return $display;
    }

    private function key(string $suffix): string
    {
        return str_replace('-', '_', $this->graphType) . '_' . $suffix;
    }
}
