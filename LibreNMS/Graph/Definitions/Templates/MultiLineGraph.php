<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class MultiLineGraph extends GraphTemplate
{
    /**
     * @param list<array{ds:string,label:string,invert?:bool,color?:string,metric?:string,vm_kind?:string}> $series
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
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line']);
    }

    public function series(array $device, GraphQuery $query): array
    {
        $series = [];
        foreach ($this->series as $i => $def) {
            $invert = (bool) ($def['invert'] ?? false);
            $rrd = new RrdMetricBinding($this->rrdName, $def['ds']);
            $bindings = isset($def['metric'])
                ? (($def['vm_kind'] ?? 'gauge') === 'rate'
                    ? MetricSeries::rate($def['metric'], $rrd)
                    : MetricSeries::gauge($def['metric'], $rrd))
                : [$rrd];

            $series[] = new GraphSeriesDefinition(
                name: $def['label'],
                key: str_replace('-', '_', $this->graphType) . '_' . $def['ds'],
                unit: $this->unit($device, $query),
                color: $def['color'] ?? $this->paletteColor($this->palette, $i, 'CC0000'),
                lineWidth: 1.25,
                negate: $invert && $this->stackedMultiplier() < 0,
                bindings: $bindings,
            );
        }

        return $series;
    }
}
