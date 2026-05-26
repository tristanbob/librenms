<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\Definitions\Templates\DerivedSeriesGraph;
use LibreNMS\Graph\Definitions\Templates\DuplexGraph;
use LibreNMS\Graph\GraphDefinition;

class DeviceUcdGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        $blocksToBits = fn ($value) => $value * 4096;
        $load = fn ($value) => $value / 100;
        $cpuPercent = fn (string $ds): \Closure => static function (array $values) use ($ds): ?float {
            $total = array_sum($values);
            return $total > 0 ? $values[$ds] / $total * 100 : null;
        };

        return [
            new DuplexGraph('device_ucd_io', 'I/O', 'bps', 'ucd_ssIORawReceived', 'ucd_ssIORawSent', 'value', 'value', $blocksToBits),
            new DuplexGraph('device_ucd_swap_io', 'Swap I/O', 'bps', 'ucd_ssRawSwapIn', 'ucd_ssRawSwapOut', 'value', 'value', $blocksToBits),
            new DerivedSeriesGraph('device_ucd_load', 'Load Average', 'Load', 'ucd_load', [
                ['name' => '1 Min', 'key' => 'load_1min', 'ds' => '1min', 'transform' => $load, 'color' => 'ffeeaa', 'lineColor' => 'c5aa00', 'area' => true],
                ['name' => '5 Min', 'key' => 'load_5min', 'ds' => '5min', 'transform' => $load, 'color' => 'ea8f00'],
                ['name' => '15 Min', 'key' => 'load_15min', 'ds' => '15min', 'transform' => $load, 'color' => 'cc0000'],
            ], ['area' => true, 'yAxisMin' => 0]),
            new DerivedSeriesGraph('device_ucd_cpu', 'UCD CPU', '%', 'ucd_cpu', [
                ['name' => 'user', 'key' => 'cpu_user', 'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('user'), 'color' => 'c02020', 'area' => true, 'stack' => 'ucd_cpu'],
                ['name' => 'nice', 'key' => 'cpu_nice', 'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('nice'), 'color' => '008f00', 'area' => true, 'stack' => 'ucd_cpu'],
                ['name' => 'system', 'key' => 'cpu_system', 'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('system'), 'color' => 'ea8f00', 'area' => true, 'stack' => 'ucd_cpu'],
                ['name' => 'idle', 'key' => 'cpu_idle', 'ds' => ['user', 'nice', 'system', 'idle'], 'transform' => $cpuPercent('idle'), 'color' => '000077', 'area' => true, 'stack' => 'ucd_cpu'],
            ], ['area' => true, 'stacked' => true, 'yAxisMin' => 0, 'yAxisMax' => 100]),
        ];
    }
}
