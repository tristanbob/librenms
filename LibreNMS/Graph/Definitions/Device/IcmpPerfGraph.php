<?php

/**
 * IcmpPerfGraph.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
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

    public function series(GraphContext $context): array
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
