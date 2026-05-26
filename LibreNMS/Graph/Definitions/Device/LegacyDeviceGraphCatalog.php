<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\Definitions\Legacy\DerivedSeriesGraph;
use LibreNMS\Graph\Definitions\Legacy\DuplexGraph;
use LibreNMS\Graph\Definitions\Legacy\MultiLineGraph;
use LibreNMS\Graph\Definitions\Legacy\SimpleStatsGraph;
use LibreNMS\Graph\Definitions\Legacy\StackedAreaGraph;
use LibreNMS\Graph\GraphDefinition;

class LegacyDeviceGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        return [
            ...self::simpleStats(),
            ...self::netstat(),
            ...self::ipSystemStats(),
            ...self::ucd(),
        ];
    }

    private static function simpleStats(): array
    {
        $days = fn ($value) => $value / 86400;

        return [
            new SimpleStatsGraph('device_availability', 'Availability', 'Availability(%)', ['availability', 86400], 'availability', '', display: ['yAxisMin' => 0, 'yAxisMax' => 100]),
            new SimpleStatsGraph('device_hr_processes', 'Processes', 'Processes', 'hr_processes', 'procs'),
            new SimpleStatsGraph('device_hr_users', 'Users', 'Users', 'hr_users', 'users'),
            new SimpleStatsGraph('device_ucd_contexts', 'Context Switches', 'Switches/s', 'ucd_ssRawContexts', 'value'),
            new SimpleStatsGraph('device_ucd_cpu_steal', 'CPU Steal', 'CPU Steal', 'ucd_ssCpuRawSteal', 'value'),
            new SimpleStatsGraph('device_ucd_interrupts', 'Interrupts', 'Interrupts/s', 'ucd_ssRawInterrupts', 'value'),
            new SimpleStatsGraph('device_ucd_io_wait', 'IO Wait', 'IO Wait', 'ucd_ssCpuRawWait', 'value'),
            new SimpleStatsGraph('device_uptime', 'Uptime', 'Days', 'uptime', 'uptime', 'Uptime', 'greens', $days, ['legacyPercentiles' => true, 'yAxisMin' => 0]),
        ];
    }

    private static function netstat(): array
    {
        return [
            new MultiLineGraph('device_netstat_icmp', 'ICMP Statistics', 'Packets/s', 'netstats-icmp', [
                ['ds' => 'icmpInMsgs', 'label' => 'InMsgs', 'color' => '00cc00'],
                ['ds' => 'icmpOutMsgs', 'label' => 'OutMsgs', 'color' => '006600', 'invert' => true],
                ['ds' => 'icmpInErrors', 'label' => 'InErrors', 'color' => 'cc0000'],
                ['ds' => 'icmpOutErrors', 'label' => 'OutErrors', 'color' => '660000', 'invert' => true],
                ['ds' => 'icmpInEchos', 'label' => 'InEchos', 'color' => '0066cc'],
                ['ds' => 'icmpOutEchos', 'label' => 'OutEchos', 'color' => '003399', 'invert' => true],
                ['ds' => 'icmpInEchoReps', 'label' => 'InEchoReps', 'color' => 'cc00cc'],
                ['ds' => 'icmpOutEchoReps', 'label' => 'OutEchoReps', 'color' => '990099', 'invert' => true],
            ]),
            new MultiLineGraph('device_netstat_icmp_info', 'ICMP Informational Statistics', 'Packets/s', 'netstats-icmp', self::legacyStats([
                'icmpInSrcQuenchs', 'icmpOutSrcQuenchs', 'icmpInRedirects', 'icmpOutRedirects',
                'icmpInAddrMasks', 'icmpOutAddrMasks', 'icmpInAddrMaskReps', 'icmpOutAddrMaskReps',
            ], 'icmp')),
            new MultiLineGraph('device_netstat_ip', 'IP Statistics', 'Packets/s', 'netstats-ip', self::legacyStats([
                'ipForwDatagrams', 'ipInDelivers', 'ipInReceives', 'ipOutRequests',
                'ipInDiscards', 'ipOutDiscards', 'ipOutNoRoutes',
            ], 'ip')),
            new StackedAreaGraph('device_netstat_snmp', 'SNMP Statistics', 'Packets/s', 'netstats-snmp', self::legacyStats([
                'snmpInTraps', 'snmpOutTraps', 'snmpInTotalReqVars', 'snmpInTotalSetVars',
                'snmpOutGetResponses', 'snmpOutSetRequests',
            ], 'snmp')),
            new DuplexGraph('device_netstat_snmp_pkt', 'SNMP Packets', 'Packets', 'netstats-snmp', 'netstats-snmp', 'snmpInPkts', 'snmpOutPkts', null, 'AA66AA', '330033', 'FFDD88', 'FF6600'),
            new MultiLineGraph('device_netstat_tcp', 'TCP Statistics', 'Segments/s', 'netstats-tcp', self::legacyStats([
                'tcpInSegs', 'tcpOutSegs', 'tcpActiveOpens', 'tcpPassiveOpens',
                'tcpAttemptFails', 'tcpEstabResets', 'tcpRetransSegs',
            ], 'tcp', ['Out', 'Retrans', 'Attempt'])),
            new MultiLineGraph('device_netstat_udp', 'UDP Statistics', 'Packets/s', 'netstats-udp', self::legacyStats([
                'udpInDatagrams', 'udpOutDatagrams', 'udpInErrors', 'udpNoPorts',
            ], 'udp', ['Out', 'Retrans', 'Attempt'])),
            self::ipFragmentGraph('device_netstat_ip_frag', 'IP Fragmentation', 'netstats-ip', [
                ['name' => 'Frag OK', 'key' => 'frag_ok', 'metric' => 'ipFragOKs', 'color' => '00cc00'],
                ['name' => 'Frag Fail', 'key' => 'frag_fail', 'metric' => 'ipFragFails', 'color' => 'cc0000'],
                ['name' => 'Reasm OK', 'key' => 'reasm_ok', 'metric' => 'ipReasmOKs', 'color' => '006600'],
                ['name' => 'Reasm Fail', 'key' => 'reasm_fail', 'metric' => 'ipReasmFails', 'color' => '660000'],
                ['name' => 'Frag Create', 'key' => 'frag_create', 'metric' => 'ipFragCreates', 'color' => '0000cc'],
                ['name' => 'Reasm Reqd', 'key' => 'reasm_reqd', 'metric' => 'ipReasmReqds', 'color' => '000066'],
            ], 'ipInDelivers'),
        ];
    }

    private static function ipSystemStats(): array
    {
        return [
            self::ipSystemGraph('device_ipsystemstats_ipv4', 'IPv4 System Statistics', 'ipSystemStats-ipv4', 'v4'),
            self::ipSystemGraph('device_ipsystemstats_ipv6', 'IPv6 System Statistics', 'ipSystemStats-ipv6', 'v6'),
            self::ipFragmentGraph('device_ipsystemstats_ipv4_frag', 'IPv4 Fragmentation', 'ipSystemStats-ipv4', [
                ['name' => 'Frag Fail', 'key' => 'frag_fail', 'metric' => 'OutFragFails', 'color' => 'cc0000', 'negate' => true],
                ['name' => 'Frag Create', 'key' => 'frag_create', 'metric' => 'OutFragCreates', 'color' => '0000cc'],
                ['name' => 'Reasm OK', 'key' => 'reasm_ok', 'metric' => 'ReasmOKs', 'color' => '006600'],
                ['name' => 'Reasm Fail', 'key' => 'reasm_fail', 'metric' => 'ReasmFails', 'color' => '660000'],
                ['name' => 'Reasm Reqd', 'key' => 'reasm_reqd', 'metric' => 'ReasmReqds', 'color' => '000066'],
            ], 'InDelivers'),
            self::ipFragmentGraph('device_ipsystemstats_ipv6_frag', 'IPv6 Fragmentation', 'ipSystemStats-ipv6', [
                ['name' => 'Frag Fail', 'key' => 'frag_fail', 'metric' => 'OutFragFails', 'color' => 'cc0000', 'negate' => true],
                ['name' => 'Frag Create', 'key' => 'frag_create', 'metric' => 'OutFragCreates', 'color' => '0000cc'],
                ['name' => 'Reasm OK', 'key' => 'reasm_ok', 'metric' => 'ReasmOKs', 'color' => '006600'],
                ['name' => 'Reasm Fail', 'key' => 'reasm_fail', 'metric' => 'ReasmFails', 'color' => '660000'],
                ['name' => 'Reasm Reqd', 'key' => 'reasm_reqd', 'metric' => 'ReasmReqds', 'color' => '000066'],
            ], 'InDelivers'),
        ];
    }

    private static function ucd(): array
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

    private static function ipSystemGraph(string $type, string $title, string $rrdName, string $suffix): DerivedSeriesGraph
    {
        return new DerivedSeriesGraph($type, $title, 'Packets/s', $rrdName, [
            ['name' => "InReceives $suffix", 'key' => 'in_receives', 'ds' => 'InReceives', 'color' => '7D9B5B'],
            ['name' => "InForward $suffix", 'key' => 'in_forward', 'ds' => 'InForwDatagrams', 'color' => 'AF63AF', 'area' => true, 'stack' => 'ip_in'],
            ['name' => "InDelivers $suffix", 'key' => 'in_delivers', 'ds' => 'InDelivers', 'color' => 'CDEB8B', 'area' => true, 'stack' => 'ip_in'],
            ['name' => "OutRequests $suffix", 'key' => 'out_requests', 'ds' => 'OutRequests', 'color' => 'C3D9FF', 'area' => true, 'negate' => true],
            ['name' => "OutForward $suffix", 'key' => 'out_forward', 'ds' => 'OutForwDatagrams', 'color' => 'AF63AF', 'area' => true],
        ], ['area' => true]);
    }

    private static function ipFragmentGraph(string $type, string $title, string $rrdName, array $defs, string $denominator): DerivedSeriesGraph
    {
        $series = [];
        foreach ($defs as $def) {
            $metric = $def['metric'];
            $series[] = [
                'name' => $def['name'],
                'key' => $def['key'],
                'ds' => [$metric, $denominator],
                'transform' => static fn (array $values): ?float => ($values[$denominator] ?? 0) > 0 ? $values[$metric] / $values[$denominator] * 100 : null,
                'color' => $def['color'],
                'negate' => (bool) ($def['negate'] ?? false),
            ];
        }

        return new DerivedSeriesGraph($type, $title, '%', $rrdName, $series);
    }

    private static function legacyStats(array $stats, string $prefix, array $invertNeedles = ['Out']): array
    {
        return array_map(static function (string $stat) use ($prefix, $invertNeedles): array {
            $invert = false;
            foreach ($invertNeedles as $needle) {
                if (str_contains($stat, $needle)) {
                    $invert = true;
                    break;
                }
            }

            return [
                'ds' => $stat,
                'label' => str_replace($prefix, '', $stat),
                'invert' => $invert,
            ];
        }, $stats);
    }
}
