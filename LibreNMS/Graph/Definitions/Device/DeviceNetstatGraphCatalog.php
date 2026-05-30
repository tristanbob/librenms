<?php

/**
 * DeviceNetstatGraphCatalog.php
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

use LibreNMS\Graph\Definitions\Templates\DuplexGraph;
use LibreNMS\Graph\Definitions\Templates\MultiLineGraph;
use LibreNMS\Graph\Definitions\Templates\StackedAreaGraph;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\ProvidesGraphDefinitions;

class DeviceNetstatGraphCatalog implements ProvidesGraphDefinitions
{
    /**
     * @return GraphDefinition[]
     */
    public static function definitions(): array
    {
        return [
            new MultiLineGraph('device_netstat_icmp', 'ICMP Statistics', 'Packets/s', 'netstats-icmp', [
                ['ds' => 'icmpInMsgs',      'label' => 'InMsgs',      'color' => '00cc00', 'metric' => 'netstats.icmpInMsgs'],
                ['ds' => 'icmpOutMsgs',     'label' => 'OutMsgs',     'color' => '006600', 'metric' => 'netstats.icmpOutMsgs',     'invert' => true],
                ['ds' => 'icmpInErrors',    'label' => 'InErrors',    'color' => 'cc0000', 'metric' => 'netstats.icmpInErrors'],
                ['ds' => 'icmpOutErrors',   'label' => 'OutErrors',   'color' => '660000', 'metric' => 'netstats.icmpOutErrors',   'invert' => true],
                ['ds' => 'icmpInEchos',     'label' => 'InEchos',     'color' => '0066cc', 'metric' => 'netstats.icmpInEchos'],
                ['ds' => 'icmpOutEchos',    'label' => 'OutEchos',    'color' => '003399', 'metric' => 'netstats.icmpOutEchos',    'invert' => true],
                ['ds' => 'icmpInEchoReps',  'label' => 'InEchoReps',  'color' => 'cc00cc', 'metric' => 'netstats.icmpInEchoReps'],
                ['ds' => 'icmpOutEchoReps', 'label' => 'OutEchoReps', 'color' => '990099', 'metric' => 'netstats.icmpOutEchoReps', 'invert' => true],
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
            new DuplexGraph(
                graphType: 'device_netstat_snmp_pkt',
                title: 'SNMP Packets',
                unit: 'Packets',
                rrdNameIn: 'netstats-snmp',
                rrdNameOut: 'netstats-snmp',
                dsIn: 'snmpInPkts',
                dsOut: 'snmpOutPkts',
                inArea: 'AA66AA',
                inLine: '330033',
                outArea: 'FFDD88',
                outLine: 'FF6600',
                metricIn: 'netstats.snmpInPkts',
                metricOut: 'netstats.snmpOutPkts',
            ),
            new MultiLineGraph('device_netstat_tcp', 'TCP Statistics', 'Segments/s', 'netstats-tcp', self::legacyStats([
                'tcpInSegs', 'tcpOutSegs', 'tcpActiveOpens', 'tcpPassiveOpens',
                'tcpAttemptFails', 'tcpEstabResets', 'tcpRetransSegs',
            ], 'tcp', ['Out', 'Retrans', 'Attempt'])),
            new MultiLineGraph('device_netstat_udp', 'UDP Statistics', 'Packets/s', 'netstats-udp', self::legacyStats([
                'udpInDatagrams', 'udpOutDatagrams', 'udpInErrors', 'udpNoPorts',
            ], 'udp', ['Out', 'Retrans', 'Attempt'])),
            DeviceIpSystemStatsGraphCatalog::ipFragmentGraph('device_netstat_ip_frag', 'IP Fragmentation', 'netstats-ip', [
                ['name' => 'Frag OK',    'key' => 'frag_ok',    'metric' => 'ipFragOKs',   'color' => '00cc00'],
                ['name' => 'Frag Fail',  'key' => 'frag_fail',  'metric' => 'ipFragFails', 'color' => 'cc0000'],
                ['name' => 'Reasm OK',   'key' => 'reasm_ok',   'metric' => 'ipReasmOKs',  'color' => '006600'],
                ['name' => 'Reasm Fail', 'key' => 'reasm_fail', 'metric' => 'ipReasmFails','color' => '660000'],
                ['name' => 'Frag Create','key' => 'frag_create','metric' => 'ipFragCreates','color' => '0000cc'],
                ['name' => 'Reasm Reqd', 'key' => 'reasm_reqd', 'metric' => 'ipReasmReqds','color' => '000066'],
            ], 'ipInDelivers'),
        ];
    }

    /**
     * Generates series defs from a flat list of RRD DS names.
     * Automatically adds the 'netstats.<ds>' catalog key for each entry.
     *
     * @param string[] $stats
     * @param string[] $invertNeedles
     * @return list<array{ds:string,label:string,invert:bool,metric:string}>
     */
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
                'ds'      => $stat,
                'label'   => str_replace($prefix, '', $stat),
                'invert'  => $invert,
                'metric'  => 'netstats.' . $stat,
            ];
        }, $stats);
    }
}
