<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\Definitions\Templates\DerivedSeriesGraph;
use LibreNMS\Graph\GraphDefinition;

class DeviceIpSystemStatsGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
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

    public static function ipFragmentGraph(string $type, string $title, string $rrdName, array $defs, string $denominator): DerivedSeriesGraph
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
}
