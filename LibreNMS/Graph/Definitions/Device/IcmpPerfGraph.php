<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;

class IcmpPerfGraph implements GraphDefinition
{
    use \LibreNMS\Graph\DefaultVariables;

    public const GRAPH_TYPE = 'device_icmp_perf';

    public function graphType(): string { return self::GRAPH_TYPE; }

    public function id(array $device, GraphQuery $query): string
    {
        return self::GRAPH_TYPE . ':' . $device['device_id'];
    }

    public function title(array $device): string { return 'Ping Response'; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string { return 'ms'; }

    public function entityType(): string { return 'device'; }

    public function display(): array
    {
        return [
            'kind'    => 'line',
            'stacked' => false,
            'area'    => true,
            'legend'  => true,
            'y_axes'  => [
                ['unit' => 'ms',  'scale' => 'linear', 'min' => null, 'max' => null],
                ['unit' => '%',   'scale' => 'linear', 'min' => 0,    'max' => 100],
            ],
        ];
    }

    public function series(array $device, GraphQuery $query): array
    {
        return [
            new GraphSeriesDefinition(
                name: 'RTT',
                key: 'icmp_avg',
                unit: 'ms',
                color: '36393d',
                lineWidth: 2.0,
                bindings: [new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'avg')],
            ),
            new GraphSeriesDefinition(
                name: 'Min',
                key: 'icmp_min',
                unit: 'ms',
                color: '8a96a8',
                lineOpacity: 0.6,
                bindings: [new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'min', consolidation: 'MIN')],
            ),
            new GraphSeriesDefinition(
                name: 'Max',
                key: 'icmp_max',
                unit: 'ms',
                color: '8a96a8',
                area: true,
                areaOpacity: 0.18,
                lineOpacity: 0.6,
                bindings: [new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'max', consolidation: 'MAX')],
            ),
            new GraphSeriesDefinition(
                name: 'Loss',
                key: 'icmp_loss',
                unit: '%',
                color: 'd42e08',
                area: true,
                areaOpacity: 0.25,
                yAxisIndex: 1,
                bindings: [new RrdMetricBinding(
                    rrdName: 'icmp-perf',
                    ds: ['xmt', 'rcv'],
                    transform: fn (array $v) => $v['xmt'] > 0 ? (($v['xmt'] - $v['rcv']) / $v['xmt'] * 100) : null,
                )],
            ),
        ];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }
}
