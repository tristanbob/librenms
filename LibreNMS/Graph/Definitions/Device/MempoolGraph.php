<?php

namespace LibreNMS\Graph\Definitions\Device;

use App\Facades\LibrenmsConfig;
use App\Models\Mempool;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class MempoolGraph implements GraphDefinition
{
    public const GRAPH_TYPE = 'device_mempool';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string { return 'Memory Usage'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string { return '%'; }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true];
    }

    public function series(array $device, GraphQuery $query): array
    {
        $classes = [
            'system' => 0,
            'buffers' => 1,
            'cached' => 2,
            'available' => 3,
            'shared' => 4,
            'swap' => 5,
            'virtual' => 6,
        ];
        $colors = (array) LibrenmsConfig::get('graph_colours.varied', []);

        return Mempool::query()
            ->where('device_id', $device['device_id'])
            ->get()
            ->sortBy(fn (Mempool $mempool) => $classes[$mempool->mempool_class] ?? 99)
            ->values()
            ->map(function (Mempool $mempool, int $i) use ($colors) {
                $color = $colors[$i % max(count($colors), 1)] ?? 'CC0000';

                return new GraphSeriesDefinition(
                    name: $mempool->mempool_descr,
                    key: 'mempool_' . $mempool->mempool_id,
                    unit: '%',
                    area: true,
                    color: $color,
                    areaOpacity: 0.25,
                    bindings: [new RrdMetricBinding(
                        rrdName: ['mempool', $mempool->mempool_type, $mempool->mempool_class, $mempool->mempool_index],
                        ds: ['used', 'free'],
                        transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
                    )],
                );
            })
            ->all();
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    public function thresholds(array $device, GraphQuery $query): array { return []; }
}
