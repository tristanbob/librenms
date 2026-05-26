<?php

namespace LibreNMS\Graph\Definitions\Toner;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'toner_usage';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . ($query->entities['supply_id'] ?? '');
    }

    public function title(array $device): string { return 'Supply Level'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' - ' . ($query->entities['supply_descr'] ?? '');
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'printer_supply'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
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

    public function markers(array $device, GraphQuery $query): array { return []; }

}
