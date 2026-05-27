<?php

namespace LibreNMS\Graph\Definitions\Processor;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'processor_usage';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . ($query->entities['processor_id'] ?? '');
    }

    public function title(array $device): string { return 'Processor Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' - ' . ($query->entities['processor_descr'] ?? '');
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'processor'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;

        return [new GraphSeriesDefinition(
            name: short_hrDeviceDescr($e['processor_descr'] ?? 'processor'),
            key: 'processor',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: [
                ...MetricSeries::gauge('processor.usage', new RrdMetricBinding(
                    rrdName: ['processor', $e['processor_type'] ?? '', $e['processor_index'] ?? ''],
                    ds: 'usage',
                )),
            ],
        )];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

}
