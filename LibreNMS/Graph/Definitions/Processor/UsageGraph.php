<?php

namespace LibreNMS\Graph\Definitions\Processor;

use LibreNMS\Graph\Definitions\Templates\EntityGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph extends EntityGraph
{
    public const GRAPH_TYPE = 'processor_usage';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Processor Usage', '%', 'processor', 'processor_id', 'processor_descr');
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

}
