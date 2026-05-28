<?php

/**
 * DeviceStatsGraphCatalog.php
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
use LibreNMS\Graph\Definitions\Templates\SimpleStatsGraph;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphVariableDefinition;
use LibreNMS\Graph\MetricSeries;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class DeviceStatsGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        $days = 1 / 86400;

        return [
            new SimpleStatsGraph(
                'device_availability',
                'Availability',
                'Availability(%)',
                fn (GraphQuery $query) => ['availability', $query->options['duration'] ?? 86400],
                'availability',
                '',
                display: ['yAxisMin' => 0, 'yAxisMax' => 100],
                graphVariables: [GraphVariableDefinition::integer('duration', 86400, 1)],
                vmExprBuilder: static function (RrdMetricBinding $primaryRrd, GraphQuery $query): array {
                    $duration = (string) ($query->options['duration'] ?? 86400);
                    $entry    = VictoriaMetricsMetricCatalog::get('device.availability');

                    return MetricSeries::expression(
                        $primaryRrd,
                        fn (array $entities) => VictoriaMetricsGraphDataProvider::buildSelector(
                            $entry->definition->name,
                            ['hostname', 'name'],
                            ['hostname' => $entities['hostname'], 'name' => $duration],
                        ),
                        ['hostname'],
                    );
                },
            ),
            new SimpleStatsGraph('device_hr_processes', 'Processes', 'Processes', 'hr_processes', 'procs'),
            new SimpleStatsGraph('device_hr_users', 'Users', 'Users', 'hr_users', 'users'),
            new SimpleStatsGraph('device_ucd_contexts', 'Context Switches', 'Switches/s', 'ucd_ssRawContexts', 'value'),
            new SimpleStatsGraph('device_ucd_cpu_steal', 'CPU Steal', 'CPU Steal', 'ucd_ssCpuRawSteal', 'value'),
            new SimpleStatsGraph('device_ucd_interrupts', 'Interrupts', 'Interrupts/s', 'ucd_ssRawInterrupts', 'value'),
            new SimpleStatsGraph('device_ucd_io_wait', 'IO Wait', 'IO Wait', 'ucd_ssCpuRawWait', 'value'),
            new SimpleStatsGraph('device_uptime', 'Uptime', 'Days', 'uptime', 'uptime', 'Uptime', 'greens', $days, ['yAxisMin' => 0], metric: 'device.uptime', vmTransform: fn ($v) => $v * $days),
        ];
    }
}
