<?php

namespace LibreNMS\Graph\Definitions\Toner;

use LibreNMS\Graph\Definitions\Templates\EntityGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph extends EntityGraph
{
    public const GRAPH_TYPE = 'toner_usage';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Supply Level', '%', 'printer_supply', 'supply_id', 'supply_descr');
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;

        return [new GraphSeriesDefinition(
            name: $e['supply_descr'] ?? 'supply',
            key: 'toner',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: [new RrdMetricBinding(
                rrdName: ['toner', $e['supply_type'] ?? '', $e['supply_index'] ?? ''],
                ds: 'toner',
            )],
        )];
    }

}
