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

namespace LibreNMS\Graph\Definitions\Storage;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\EntityGraph;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class UsageGraph extends EntityGraph
{
    public const GRAPH_TYPE = 'storage_usage';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Storage Usage', '%', 'storage', 'storage_id', 'storage_descr');
    }

    public function series(GraphContext $context): array
    {
        $e = $context->query->entities;

        return [new GraphSeriesDefinition(
            name: $e['storage_descr'] ?? 'storage',
            key: 'storage',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: [
                ...MetricSeries::expression(
                    rrd: new RrdMetricBinding(
                        rrdName: ['storage', $e['type'] ?? '', $e['storage_descr'] ?? ''],
                        ds: ['used', 'free'],
                        transform: fn (array $v) => ($v['used'] + $v['free']) > 0 ? ($v['used'] / ($v['used'] + $v['free']) * 100) : null,
                    ),
                    expressionBuilder: function (array $entities): string {
                        $used = VictoriaMetricsMetricCatalog::get('storage.used');
                        $free = VictoriaMetricsMetricCatalog::get('storage.free');
                        $usedSel = VictoriaMetricsGraphDataProvider::buildSelector($used->definition->name, $used->identityLabels, $entities);
                        $freeSel = VictoriaMetricsGraphDataProvider::buildSelector($free->definition->name, $free->identityLabels, $entities);

                        return "100 * $usedSel / ($usedSel + $freeSel)";
                    },
                    labelKeys: ['hostname', 'type', 'descr'],
                ),
            ],
        )];
    }

}
