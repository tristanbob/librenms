<?php

/**
 * DeviceUcdGraphCatalog.php
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
use LibreNMS\Graph\Definitions\Templates\DerivedSeriesGraph;
use LibreNMS\Graph\Definitions\Templates\DuplexGraph;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;

class DeviceUcdGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        $blocksToBits = fn ($value) => $value * 4096;
        $load         = fn ($value) => $value / 100;

        $cpuPercent = fn (string $ds): \Closure => static function (array $values) use ($ds): ?float {
            $total = array_sum($values);
            return $total > 0 ? $values[$ds] / $total * 100 : null;
        };

        // Pre-load CPU entries once; closures capture by reference to avoid repeated catalog lookups.
        $userEntry   = VictoriaMetricsMetricCatalog::get('ucd.cpu.user');
        $niceEntry   = VictoriaMetricsMetricCatalog::get('ucd.cpu.nice');
        $systemEntry = VictoriaMetricsMetricCatalog::get('ucd.cpu.system');
        $idleEntry   = VictoriaMetricsMetricCatalog::get('ucd.cpu.idle');

        // Builds the MetricsQL percentage expression for one CPU component.
        $cpuExpr = function (string $ds) use ($userEntry, $niceEntry, $systemEntry, $idleEntry): \Closure {
            $entry = VictoriaMetricsMetricCatalog::get("ucd.cpu.$ds");
            return function (array $entities) use ($entry, $userEntry, $niceEntry, $systemEntry, $idleEntry): string {
                $rate = fn ($e) => 'rate(' . VictoriaMetricsGraphDataProvider::buildSelector(
                    $e->definition->name, $e->identityLabels, $entities,
                ) . '[5m])';
                $total = "{$rate($userEntry)} + {$rate($niceEntry)} + {$rate($systemEntry)} + {$rate($idleEntry)}";
                return "100 * {$rate($entry)} / ({$total})";
            };
        };

        return [
            new DuplexGraph(
                'device_ucd_io', 'I/O', 'bps',
                'ucd_ssIORawReceived', 'ucd_ssIORawSent', 'value', 'value', $blocksToBits,
                metricIn: 'ucd.io.received', metricOut: 'ucd.io.sent', vmKind: 'rate',
            ),
            new DuplexGraph(
                'device_ucd_swap_io', 'Swap I/O', 'bps',
                'ucd_ssRawSwapIn', 'ucd_ssRawSwapOut', 'value', 'value', $blocksToBits,
                metricIn: 'ucd.swap.in', metricOut: 'ucd.swap.out', vmKind: 'rate',
            ),
            new DerivedSeriesGraph('device_ucd_load', 'Load Average', 'Load', 'ucd_load', [
                ['name' => '1 Min',  'key' => 'load_1min',  'ds' => '1min',  'transform' => $load, 'color' => 'ffeeaa', 'lineColor' => 'c5aa00', 'area' => true, 'metric' => 'ucd.load.1min',  'vm_kind' => 'gauge'],
                ['name' => '5 Min',  'key' => 'load_5min',  'ds' => '5min',  'transform' => $load, 'color' => 'ea8f00',                                          'metric' => 'ucd.load.5min',  'vm_kind' => 'gauge'],
                ['name' => '15 Min', 'key' => 'load_15min', 'ds' => '15min', 'transform' => $load, 'color' => 'cc0000',                                          'metric' => 'ucd.load.15min', 'vm_kind' => 'gauge'],
            ], ['area' => true, 'yAxisMin' => 0]),
            new DerivedSeriesGraph('device_ucd_cpu', 'UCD CPU', '%', 'ucd_cpu', [
                ['name' => 'user',   'key' => 'cpu_user',   'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('user'),   'color' => 'c02020', 'area' => true, 'stack' => 'ucd_cpu', 'vm_expression' => $cpuExpr('user')],
                ['name' => 'nice',   'key' => 'cpu_nice',   'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('nice'),   'color' => '008f00', 'area' => true, 'stack' => 'ucd_cpu', 'vm_expression' => $cpuExpr('nice')],
                ['name' => 'system', 'key' => 'cpu_system', 'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('system'), 'color' => 'ea8f00', 'area' => true, 'stack' => 'ucd_cpu', 'vm_expression' => $cpuExpr('system')],
                ['name' => 'idle',   'key' => 'cpu_idle',   'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('idle'),   'color' => '000077', 'area' => true, 'stack' => 'ucd_cpu', 'vm_expression' => $cpuExpr('idle')],
            ], ['area' => true, 'stacked' => true, 'yAxisMin' => 0, 'yAxisMax' => 100]),
        ];
    }
}
