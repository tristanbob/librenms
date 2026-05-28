<?php

/**
 * ProcessorGraph.php
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
use App\Models\Processor;
use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Graph\Definitions\Templates\GraphTemplate;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class ProcessorGraph extends GraphTemplate
{
    public const GRAPH_TYPE = 'device_processor';

    public function __construct()
    {
        parent::__construct(self::GRAPH_TYPE, 'CPU Usage', '%', ['area' => true]);
    }

    public function series(array $device, GraphQuery $query): array
    {
        $processors = Processor::query()
            ->where('device_id', $device['device_id'])
            ->orderBy('processor_type')
            ->orderBy('processor_index')
            ->get();

        $colors     = (array) LibrenmsConfig::get('graph_colours.mixed', LibrenmsConfig::get('graph_colours.oranges', []));
        $stacked    = (bool) LibrenmsConfig::getOsSetting($device['os'] ?? '', 'processor_stacked');
        $usageEntry = VictoriaMetricsMetricCatalog::get('processor.usage');

        return $processors->values()->map(function (Processor $processor, int $i) use ($colors, $stacked, $usageEntry) {
            $color = $colors[$i % max(count($colors), 1)] ?? 'CC0000';
            $name  = short_hrDeviceDescr($processor->processor_descr);

            return new GraphSeriesDefinition(
                name:        $name,
                key:         'processor_' . $processor->processor_id,
                unit:        '%',
                area:        true,
                stack:       $stacked ? 'processor_usage' : null,
                color:       $color,
                areaOpacity: 0.25,
                bindings:    MetricSeries::expression(
                    new RrdMetricBinding(
                        rrdName: ['processor', $processor->processor_type, $processor->processor_index],
                        ds: 'usage',
                    ),
                    fn(array $entities): string => VictoriaMetricsGraphDataProvider::buildSelector(
                        $usageEntry->definition->name,
                        $usageEntry->identityLabels,
                        [
                            'hostname'        => $entities['hostname'],
                            'processor_type'  => $processor->processor_type,
                            'processor_index' => (string) $processor->processor_index,
                        ],
                    ),
                    ['hostname'],
                ),
            );
        })->all();
    }

}
