<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\Definitions\Templates\SimpleStatsGraph;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphVariableDefinition;

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
