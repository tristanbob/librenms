<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class DerivedSeriesGraph extends GraphTemplate
{
    /**
     * @param list<array{name:string,key:string,ds:string|array,transform?:mixed,color:string,lineColor?:string,area?:bool,stack?:string,negate?:bool,lineWidth?:float,metric?:string,vm_kind?:string}> $series
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

    public function series(array $device, GraphQuery $query): array
    {
        $series = [];
        foreach ($this->series as $def) {
            $rrd = new RrdMetricBinding($this->rrdName, $def['ds'], transform: $def['transform'] ?? null);
            // VM binding is only possible for single-DS series; multi-DS (array ds) series are RRD-only.
            $bindings = (isset($def['metric']) && is_string($def['ds']))
                ? (($def['vm_kind'] ?? 'rate') === 'gauge'
                    ? MetricSeries::gauge($def['metric'], $rrd)
                    : MetricSeries::rate($def['metric'], $rrd))
                : [$rrd];

            $series[] = new GraphSeriesDefinition(
                name: $def['name'],
                key: $def['key'],
                unit: $this->unit($device, $query),
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
