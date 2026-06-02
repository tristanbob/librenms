<?php

/**
 * MempoolGraph.php
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

use App\Facades\LibrenmsConfig;
use App\Models\Mempool;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class MempoolGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_mempool';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Memory Usage', '%', ['area' => true]);
    }

    public function series(GraphContext $context): array
    {
        $device = $context;
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
        $usedEntry = VictoriaMetricsMetricCatalog::get('mempool.used');
        $freeEntry = VictoriaMetricsMetricCatalog::get('mempool.free');

        return Mempool::query()
            ->where('device_id', $device['device_id'])
            ->get()
            ->sortBy(fn (Mempool $mempool) => $classes[$mempool->mempool_class] ?? 99)
            ->values()
            ->map(function (Mempool $mempool, int $i) use ($colors, $usedEntry, $freeEntry) {
                $color = $colors[$i % max(count($colors), 1)] ?? 'CC0000';

                return new GraphSeriesDefinition(
                    name:        $mempool->mempool_descr,
                    key:         'mempool_' . $mempool->mempool_id,
                    unit:        '%',
                    area:        true,
                    color:       $color,
                    areaOpacity: 0.25,
                    bindings:    MetricSeries::expression(
                        new RrdMetricBinding(
                            rrdName: ['mempool', $mempool->mempool_type, $mempool->mempool_class, $mempool->mempool_index],
                            ds: ['used', 'free'],
                            transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
                        ),
                        function (array $entities) use ($mempool, $usedEntry, $freeEntry): string {
                            $labels = [
                                'hostname'      => $entities['hostname'],
                                'mempool_type'  => $mempool->mempool_type,
                                'mempool_class' => $mempool->mempool_class,
                                'mempool_index' => (string) $mempool->mempool_index,
                            ];
                            $usedSel = VictoriaMetricsGraphDataProvider::buildSelector($usedEntry->definition->name, $usedEntry->identityLabels, $labels);
                            $freeSel = VictoriaMetricsGraphDataProvider::buildSelector($freeEntry->definition->name, $freeEntry->identityLabels, $labels);

                            return "100 * {$usedSel} / ({$usedSel} + {$freeSel})";
                        },
                        ['hostname'],
                    ),
                );
            })
            ->all();
    }
}
