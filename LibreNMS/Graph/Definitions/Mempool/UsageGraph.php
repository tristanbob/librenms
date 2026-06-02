<?php

/**
 * UsageGraph.php
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

namespace LibreNMS\Graph\Definitions\Mempool;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\EntityGraph;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class UsageGraph extends EntityGraph
{
    public const GRAPH_TYPE = 'mempool_usage';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Memory Usage', '%', 'mempool', 'mempool_id', 'mempool_descr');
    }

    public function series(GraphContext $context): array
    {
        $e = $context->query->entities;

        return [new GraphSeriesDefinition(
            name: $e['mempool_descr'] ?? 'memory',
            key: 'mempool',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: MetricSeries::expression(
                new RrdMetricBinding(
                    rrdName: ['mempool', $e['mempool_type'] ?? '', $e['mempool_class'] ?? '', $e['mempool_index'] ?? ''],
                    ds: ['used', 'free'],
                    transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
                ),
                fn (array $entities): string => self::usageExpression($entities),
                ['hostname', 'mempool_type', 'mempool_class', 'mempool_index'],
            ),
        )];
    }

    private static function usageExpression(array $entities): string
    {
        $used = VictoriaMetricsMetricCatalog::get('mempool.used');
        $free = VictoriaMetricsMetricCatalog::get('mempool.free');

        $usedSelector = VictoriaMetricsGraphDataProvider::buildSelector($used->definition->name, $used->identityLabels, $entities);
        $freeSelector = VictoriaMetricsGraphDataProvider::buildSelector($free->definition->name, $free->identityLabels, $entities);

        return "100 * {$usedSelector} / ({$usedSelector} + {$freeSelector})";
    }
}
