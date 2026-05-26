<?php

namespace LibreNMS\Graph\Definitions\Storage;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'storage_usage';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . ($query->entities['storage_id'] ?? '');
    }

    public function title(array $device): string { return 'Storage Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' - ' . ($query->entities['storage_descr'] ?? '');
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'storage'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;

        return [new GraphSeriesDefinition(
            name: $e['storage_descr'] ?? 'storage',
            key: 'storage',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: [new RrdMetricBinding(
                rrdName: ['storage', $e['type'] ?? '', $e['storage_descr'] ?? ''],
                ds: ['used', 'free'],
                transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
            )],
        )];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

}
