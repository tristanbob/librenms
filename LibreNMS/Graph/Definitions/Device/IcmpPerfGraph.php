<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class IcmpPerfGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_icmp_perf';

    public function __construct()
    {
        parent::__construct(
            graphType: self::GRAPH_TYPE,
            title:     'Ping Response',
            unit:      'ms',
            display:   [
                'area'   => true,
                'y_axes' => [
                    ['unit' => 'ms', 'scale' => 'linear', 'min' => null, 'max' => null],
                    ['unit' => '%',  'scale' => 'linear', 'min' => 0,    'max' => 100],
                ],
            ],
        );
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
                bindings: MetricSeries::gauge('icmp.avg_rtt', new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'avg')),
            ),
            new GraphSeriesDefinition(
                name: 'Min',
                key: 'icmp_min',
                unit: 'ms',
                color: '8a96a8',
                lineOpacity: 0.6,
                bindings: MetricSeries::gauge('icmp.min_rtt', new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'min', consolidation: 'MIN')),
            ),
            new GraphSeriesDefinition(
                name: 'Max',
                key: 'icmp_max',
                unit: 'ms',
                color: '8a96a8',
                area: true,
                areaOpacity: 0.18,
                lineOpacity: 0.6,
                bindings: MetricSeries::gauge('icmp.max_rtt', new RrdMetricBinding(rrdName: 'icmp-perf', ds: 'max', consolidation: 'MAX')),
            ),
            new GraphSeriesDefinition(
                name: 'Loss',
                key: 'icmp_loss',
                unit: '%',
                color: 'd42e08',
                area: true,
                areaOpacity: 0.25,
                yAxisIndex: 1,
                bindings: MetricSeries::expression(
                    new RrdMetricBinding(
                        rrdName: 'icmp-perf',
                        ds: ['xmt', 'rcv'],
                        transform: fn (array $v) => $v['xmt'] > 0 ? (($v['xmt'] - $v['rcv']) / $v['xmt'] * 100) : null,
                    ),
                    fn (array $entities): string => self::lossExpression($entities),
                ),
            ),
        ];
    }

    private static function lossExpression(array $entities): string
    {
        $xmt = VictoriaMetricsMetricCatalog::get('icmp.transmitted');
        $rcv = VictoriaMetricsMetricCatalog::get('icmp.received');

        $xmtSelector = VictoriaMetricsGraphDataProvider::buildSelector($xmt->definition->name, $xmt->identityLabels, $entities);
        $rcvSelector = VictoriaMetricsGraphDataProvider::buildSelector($rcv->definition->name, $rcv->identityLabels, $entities);

        return "100 * ({$xmtSelector} - {$rcvSelector}) / {$xmtSelector}";
    }
}
