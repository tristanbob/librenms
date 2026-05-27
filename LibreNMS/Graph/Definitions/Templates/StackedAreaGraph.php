<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class StackedAreaGraph extends GraphTemplate
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
        parent::__construct($graphType, $title, $unit, $display + ['kind' => 'line', 'area' => true, 'stacked' => true]);
    }

    public function series(array $device, GraphQuery $query): array
    {
        $normal = [];
        $inverted = [];
        foreach ($this->series as $i => $def) {
            $invert = (bool) ($def['invert'] ?? false);
            $color = $def['color'] ?? $this->paletteColor($this->palette, $i, 'CC0000');
            $rrd = new RrdMetricBinding($this->rrdName, $def['ds']);
            $bindings = isset($def['metric'])
                ? (($def['vm_kind'] ?? 'rate') === 'gauge'
                    ? MetricSeries::gauge($def['metric'], $rrd)
                    : MetricSeries::rate($def['metric'], $rrd))
                : [$rrd];
            $item = new GraphSeriesDefinition(
                name: $def['label'],
                key: str_replace('-', '_', $this->graphType) . '_' . $def['ds'],
                unit: $this->unit($device, $query),
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
