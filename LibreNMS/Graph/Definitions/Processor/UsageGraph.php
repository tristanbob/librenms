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

namespace LibreNMS\Graph\Definitions\Processor;

use LibreNMS\Graph\Definitions\Templates\EntityGraph;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;

class UsageGraph extends EntityGraph
{
    public const GRAPH_TYPE = 'processor_usage';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'Processor Usage', '%', 'processor', 'processor_id', 'processor_descr');
    }

    public function series(array $device, GraphQuery $query): array
    {
        $e = $query->entities;

        return [new GraphSeriesDefinition(
            name: short_hrDeviceDescr($e['processor_descr'] ?? 'processor'),
            key: 'processor',
            unit: '%',
            area: true,
            color: 'CC0000',
            areaOpacity: 0.25,
            bindings: [
                ...MetricSeries::gauge('processor.usage', new RrdMetricBinding(
                    rrdName: ['processor', $e['processor_type'] ?? '', $e['processor_index'] ?? ''],
                    ds: 'usage',
                )),
            ],
        )];
    }

}
