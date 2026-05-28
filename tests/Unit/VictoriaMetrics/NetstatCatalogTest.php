<?php

/**
 * NetstatCatalogTest.php
 *
 * Verifies that the VictoriaMetricsMetricCatalog contains correct entries
 * for the netstats and ipSystemStats metric families.
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

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use LibreNMS\Data\Store\VictoriaMetrics\VictoriaMetricsMetricCatalog;
use LibreNMS\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NetstatCatalogTest extends TestCase
{
    #[DataProvider('netstatsKeyProvider')]
    public function testNetstatsKeyLookupByKey(string $key, string $measurement, string $field, string $metricName): void
    {
        $entry = VictoriaMetricsMetricCatalog::get($key);

        $this->assertNotNull($entry, "Catalog key '$key' must exist");
        $this->assertSame($measurement, $entry->measurement, "Wrong measurement for $key");
        $this->assertSame($field, $entry->field, "Wrong field for $key");
        $this->assertSame($metricName, $entry->definition->name, "Wrong metric name for $key");
        $this->assertSame('counter', $entry->definition->type, "$key must be a counter");
        $this->assertSame(['hostname'], $entry->identityLabels, "$key must use only hostname as identity label");
    }

    #[DataProvider('netstatsKeyProvider')]
    public function testNetstatsKeyLookupByMeasurementField(string $key, string $measurement, string $field, string $metricName): void
    {
        $entry = VictoriaMetricsMetricCatalog::getByMeasurementField($measurement, $field);

        $this->assertNotNull($entry, "Catalog entry for ($measurement, $field) must exist");
        $this->assertSame($key, $entry->key, "Wrong key for ($measurement, $field)");
        $this->assertSame($metricName, $entry->definition->name);
    }

    public static function netstatsKeyProvider(): array
    {
        return [
            // netstats-icmp
            'icmpInMsgs'       => ['netstats.icmpInMsgs',       'netstats-icmp', 'icmpInMsgs',       'librenms_netstats_icmp_in_msgs_total'],
            'icmpOutMsgs'      => ['netstats.icmpOutMsgs',      'netstats-icmp', 'icmpOutMsgs',      'librenms_netstats_icmp_out_msgs_total'],
            'icmpInErrors'     => ['netstats.icmpInErrors',     'netstats-icmp', 'icmpInErrors',     'librenms_netstats_icmp_in_errors_total'],
            'icmpInEchoReps'   => ['netstats.icmpInEchoReps',   'netstats-icmp', 'icmpInEchoReps',   'librenms_netstats_icmp_in_echo_reps_total'],
            'icmpInSrcQuenchs' => ['netstats.icmpInSrcQuenchs', 'netstats-icmp', 'icmpInSrcQuenchs', 'librenms_netstats_icmp_in_src_quenchs_total'],
            'icmpInAddrMasks'  => ['netstats.icmpInAddrMasks',  'netstats-icmp', 'icmpInAddrMasks',  'librenms_netstats_icmp_in_addr_masks_total'],

            // netstats-ip
            'ipForwDatagrams'  => ['netstats.ipForwDatagrams',  'netstats-ip',   'ipForwDatagrams',  'librenms_netstats_ip_forw_datagrams_total'],
            'ipInDelivers'     => ['netstats.ipInDelivers',     'netstats-ip',   'ipInDelivers',     'librenms_netstats_ip_in_delivers_total'],
            'ipFragOKs'        => ['netstats.ipFragOKs',        'netstats-ip',   'ipFragOKs',        'librenms_netstats_ip_frag_oks_total'],
            'ipReasmFails'     => ['netstats.ipReasmFails',     'netstats-ip',   'ipReasmFails',     'librenms_netstats_ip_reasm_fails_total'],

            // netstats-snmp
            'snmpInPkts'       => ['netstats.snmpInPkts',       'netstats-snmp', 'snmpInPkts',       'librenms_netstats_snmp_in_pkts_total'],
            'snmpOutPkts'      => ['netstats.snmpOutPkts',      'netstats-snmp', 'snmpOutPkts',      'librenms_netstats_snmp_out_pkts_total'],
            'snmpInTraps'      => ['netstats.snmpInTraps',      'netstats-snmp', 'snmpInTraps',      'librenms_netstats_snmp_in_traps_total'],

            // netstats-tcp
            'tcpInSegs'        => ['netstats.tcpInSegs',        'netstats-tcp',  'tcpInSegs',        'librenms_netstats_tcp_in_segs_total'],
            'tcpRetransSegs'   => ['netstats.tcpRetransSegs',   'netstats-tcp',  'tcpRetransSegs',   'librenms_netstats_tcp_retrans_segs_total'],

            // netstats-udp
            'udpInDatagrams'   => ['netstats.udpInDatagrams',   'netstats-udp',  'udpInDatagrams',   'librenms_netstats_udp_in_datagrams_total'],
            'udpNoPorts'       => ['netstats.udpNoPorts',       'netstats-udp',  'udpNoPorts',       'librenms_netstats_udp_no_ports_total'],

            // ipSystemStats-ipv4
            'ipv4.InReceives'  => ['ipsystemstats.ipv4.InReceives',  'ipSystemStats-ipv4', 'InReceives',  'librenms_ipsystemstats_ipv4_in_receives_total'],
            'ipv4.OutRequests' => ['ipsystemstats.ipv4.OutRequests', 'ipSystemStats-ipv4', 'OutRequests', 'librenms_ipsystemstats_ipv4_out_requests_total'],
            'ipv4.ReasmOKs'    => ['ipsystemstats.ipv4.ReasmOKs',   'ipSystemStats-ipv4', 'ReasmOKs',    'librenms_ipsystemstats_ipv4_reasm_oks_total'],

            // ipSystemStats-ipv6
            'ipv6.InReceives'  => ['ipsystemstats.ipv6.InReceives',  'ipSystemStats-ipv6', 'InReceives',  'librenms_ipsystemstats_ipv6_in_receives_total'],
            'ipv6.OutRequests' => ['ipsystemstats.ipv6.OutRequests', 'ipSystemStats-ipv6', 'OutRequests', 'librenms_ipsystemstats_ipv6_out_requests_total'],
        ];
    }
}
