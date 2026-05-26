<?php

namespace LibreNMS\Graph\Definitions\Device;

use LibreNMS\Graph\Definitions\Templates\DuplexGraph;
use LibreNMS\Graph\Definitions\Templates\MultiLineGraph;
use LibreNMS\Graph\Definitions\Templates\StackedAreaGraph;
use LibreNMS\Graph\GraphDefinition;

class DeviceNetstatGraphCatalog
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
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
            DeviceIpSystemStatsGraphCatalog::ipFragmentGraph('device_netstat_ip_frag', 'IP Fragmentation', 'netstats-ip', [
                ['name' => 'Frag OK', 'key' => 'frag_ok', 'metric' => 'ipFragOKs', 'color' => '00cc00'],
                ['name' => 'Frag Fail', 'key' => 'frag_fail', 'metric' => 'ipFragFails', 'color' => 'cc0000'],
                ['name' => 'Reasm OK', 'key' => 'reasm_ok', 'metric' => 'ipReasmOKs', 'color' => '006600'],
                ['name' => 'Reasm Fail', 'key' => 'reasm_fail', 'metric' => 'ipReasmFails', 'color' => '660000'],
                ['name' => 'Frag Create', 'key' => 'frag_create', 'metric' => 'ipFragCreates', 'color' => '0000cc'],
                ['name' => 'Reasm Reqd', 'key' => 'reasm_reqd', 'metric' => 'ipReasmReqds', 'color' => '000066'],
            ], 'ipInDelivers'),
        ];
    }

    private static function legacyStats(array $stats, string $prefix, array $invertNeedles = ['Out']): array
    {
        return array_map(static function (string $stat) use ($prefix, $invertNeedles): array {
            foreach ($invertNeedles as $needle) {
                if (str_contains($stat, $needle)) {
                    return ['ds' => $stat, 'label' => str_replace($prefix, '', $stat), 'invert' => true];
                }
            }

            return ['ds' => $stat, 'label' => str_replace($prefix, '', $stat), 'invert' => false];
        }, $stats);
    }
}
