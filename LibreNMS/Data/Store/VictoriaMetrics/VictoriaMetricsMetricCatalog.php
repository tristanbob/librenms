<?php

/**
 * VictoriaMetricsMetricCatalog.php
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

namespace LibreNMS\Data\Store\VictoriaMetrics;

final class VictoriaMetricsMetricCatalog
{
    /** @var array<string, MetricCatalogEntry>|null */
    private static ?array $byKey = null;

    /** @var array<string, array<string, MetricCatalogEntry>>|null */
    private static ?array $byMeasurementField = null;

    public static function get(string $key): ?MetricCatalogEntry
    {
        return self::byKey()[$key] ?? null;
    }

    public static function getDefinition(string $key): ?MetricDefinition
    {
        return self::get($key)?->definition;
    }

    public static function getByMeasurementField(string $measurement, string $field): ?MetricCatalogEntry
    {
        return self::byMeasurementField()[$measurement][$field] ?? null;
    }

    /**
     * @return array<string, MetricCatalogEntry>
     */
    public static function byKey(): array
    {
        return self::$byKey ??= self::buildByKey();
    }

    /**
     * @return array<string, array<string, MetricCatalogEntry>>
     */
    private static function byMeasurementField(): array
    {
        if (self::$byMeasurementField !== null) {
            return self::$byMeasurementField;
        }

        $map = [];
        foreach (self::byKey() as $entry) {
            $map[$entry->measurement][$entry->field] = $entry;
        }

        return self::$byMeasurementField = $map;
    }

    /**
     * @return array<string, MetricCatalogEntry>
     */
    private static function buildByKey(): array
    {
        $entries = [
            self::entry('device.poller.duration', 'poller-perf', 'poller', 'librenms_device_poller_duration_seconds', 'gauge', 'seconds'),

            self::entry('port.if_in_octets', 'ports', 'INOCTETS', 'librenms_port_if_in_octets_total', 'counter', 'octets', self::portLabels()),
            self::entry('port.if_out_octets', 'ports', 'OUTOCTETS', 'librenms_port_if_out_octets_total', 'counter', 'octets', self::portLabels()),
            self::entry('port.if_in_errors', 'ports', 'INERRORS', 'librenms_port_if_in_errors_total', 'counter', 'errors', self::portLabels()),
            self::entry('port.if_out_errors', 'ports', 'OUTERRORS', 'librenms_port_if_out_errors_total', 'counter', 'errors', self::portLabels()),
            self::entry('port.if_in_discards', 'ports', 'INDISCARDS', 'librenms_port_if_in_discards_total', 'counter', 'discards', self::portLabels()),
            self::entry('port.if_out_discards', 'ports', 'OUTDISCARDS', 'librenms_port_if_out_discards_total', 'counter', 'discards', self::portLabels()),
            self::entry('port.if_in_ucast_pkts', 'ports', 'INUCASTPKTS', 'librenms_port_if_in_ucast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_out_ucast_pkts', 'ports', 'OUTUCASTPKTS', 'librenms_port_if_out_ucast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_in_nucast_pkts', 'ports', 'INNUCASTPKTS', 'librenms_port_if_in_nucast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_out_nucast_pkts', 'ports', 'OUTNUCASTPKTS', 'librenms_port_if_out_nucast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_in_broadcast_pkts', 'ports', 'INBROADCASTPKTS', 'librenms_port_if_in_broadcast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_out_broadcast_pkts', 'ports', 'OUTBROADCASTPKTS', 'librenms_port_if_out_broadcast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_in_multicast_pkts', 'ports', 'INMULTICASTPKTS', 'librenms_port_if_in_multicast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_out_multicast_pkts', 'ports', 'OUTMULTICASTPKTS', 'librenms_port_if_out_multicast_pkts_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_in_unknown_protos', 'ports', 'INUNKNOWNPROTOS', 'librenms_port_if_in_unknown_protos_total', 'counter', 'packets', self::portLabels()),
            self::entry('port.if_in_bits_rate', 'ports', 'ifInBits_rate', 'librenms_port_if_in_bits_per_second', 'gauge', 'bits_per_second', self::portLabels()),
            self::entry('port.if_out_bits_rate', 'ports', 'ifOutBits_rate', 'librenms_port_if_out_bits_per_second', 'gauge', 'bits_per_second', self::portLabels()),
            self::entry('port.if_in_ucast_pkts_rate', 'ports', 'ifInUcastPkts_rate', 'librenms_port_if_in_ucast_pkts_per_second', 'gauge', 'packets_per_second', self::portLabels()),
            self::entry('port.if_out_ucast_pkts_rate', 'ports', 'ifOutUcastPkts_rate', 'librenms_port_if_out_ucast_pkts_per_second', 'gauge', 'packets_per_second', self::portLabels()),
            self::entry('port.if_in_errors_rate', 'ports', 'ifInErrors_rate', 'librenms_port_if_in_errors_per_second', 'gauge', 'errors_per_second', self::portLabels()),
            self::entry('port.if_out_errors_rate', 'ports', 'ifOutErrors_rate', 'librenms_port_if_out_errors_per_second', 'gauge', 'errors_per_second', self::portLabels()),

            self::entry('sensor.value', 'sensor', 'sensor', 'librenms_sensor_value', 'gauge', 'value', ['hostname', 'sensor_class', 'sensor_type', 'sensor_index']),
            self::entry('wireless_sensor.value', 'wireless-sensor', 'sensor', 'librenms_wireless_sensor_value', 'gauge', 'value', ['hostname', 'sensor_class', 'sensor_type', 'sensor_index']),
            self::entry('mempool.used', 'mempool', 'used', 'librenms_mempool_used_bytes', 'gauge', 'bytes', ['hostname', 'mempool_type', 'mempool_class', 'mempool_index']),
            self::entry('mempool.free', 'mempool', 'free', 'librenms_mempool_free_bytes', 'gauge', 'bytes', ['hostname', 'mempool_type', 'mempool_class', 'mempool_index']),
            self::entry('storage.used', 'storage', 'used', 'librenms_storage_used_bytes', 'gauge', 'bytes', ['hostname', 'type', 'descr']),
            self::entry('storage.free', 'storage', 'free', 'librenms_storage_free_bytes', 'gauge', 'bytes', ['hostname', 'type', 'descr']),
            self::entry('processor.usage', 'processors', 'usage', 'librenms_processor_usage_percent', 'gauge', 'percent', ['hostname', 'processor_type', 'processor_index']),
            self::entry('icmp.avg_rtt', 'icmp-perf', 'avg', 'librenms_icmp_avg_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.min_rtt', 'icmp-perf', 'min', 'librenms_icmp_min_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.max_rtt', 'icmp-perf', 'max', 'librenms_icmp_max_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.transmitted', 'icmp-perf', 'xmt', 'librenms_icmp_transmitted_total', 'gauge', 'packets'),
            self::entry('icmp.received', 'icmp-perf', 'rcv', 'librenms_icmp_received_total', 'gauge', 'packets'),
            self::entry('device.uptime', 'uptime', 'uptime', 'librenms_device_uptime_seconds', 'gauge', 'seconds'),
            self::entry('device.availability', 'availability', 'availability', 'librenms_device_availability_percent', 'gauge', 'percent', ['hostname', 'name']),
            self::entry('ospf.instances', 'ospf-statistics', 'instances', 'librenms_ospf_instances', 'gauge', 'count'),
            self::entry('ospf.areas', 'ospf-statistics', 'areas', 'librenms_ospf_areas', 'gauge', 'count'),
            self::entry('ospf.ports', 'ospf-statistics', 'ports', 'librenms_ospf_ports', 'gauge', 'count'),
            self::entry('ospf.neighbours', 'ospf-statistics', 'neighbours', 'librenms_ospf_neighbours', 'gauge', 'count'),
            self::entry('sla.rtt', 'sla', 'rtt', 'librenms_sla_rtt_milliseconds', 'gauge', 'milliseconds', ['hostname', 'sla_nr']),
            self::entry('diskio.read_bytes', 'ucd_diskio', 'read', 'librenms_diskio_read_bytes_total', 'counter', 'bytes', ['hostname', 'descr']),
            self::entry('diskio.written_bytes', 'ucd_diskio', 'written', 'librenms_diskio_written_bytes_total', 'counter', 'bytes', ['hostname', 'descr']),
            self::entry('diskio.reads', 'ucd_diskio', 'reads', 'librenms_diskio_reads_total', 'counter', 'operations', ['hostname', 'descr']),
            self::entry('diskio.writes', 'ucd_diskio', 'writes', 'librenms_diskio_writes_total', 'counter', 'operations', ['hostname', 'descr']),

            // ucd-mib system stats (each OID written with its own measurement name: 'ucd_<oid>')
            self::entry('ucd.io.received', 'ucd_ssIORawReceived', 'value', 'librenms_ucd_io_received_blocks_total', 'counter', 'blocks'),
            self::entry('ucd.io.sent',     'ucd_ssIORawSent',     'value', 'librenms_ucd_io_sent_blocks_total',     'counter', 'blocks'),
            self::entry('ucd.swap.in',     'ucd_ssRawSwapIn',     'value', 'librenms_ucd_swap_in_blocks_total',     'counter', 'blocks'),
            self::entry('ucd.swap.out',    'ucd_ssRawSwapOut',    'value', 'librenms_ucd_swap_out_blocks_total',    'counter', 'blocks'),

            // ucd-mib load averages (all in measurement 'ucd_load', stored as raw laLoadInt × 100)
            self::entry('ucd.load.1min',  'ucd_load', '1min',  'librenms_ucd_load_1min',  'gauge', 'load'),
            self::entry('ucd.load.5min',  'ucd_load', '5min',  'librenms_ucd_load_5min',  'gauge', 'load'),
            self::entry('ucd.load.15min', 'ucd_load', '15min', 'librenms_ucd_load_15min', 'gauge', 'load'),

            // ucd-mib CPU raw ticks (measurement 'ucd_cpu', COUNTER type)
            self::entry('ucd.cpu.user',   'ucd_cpu', 'user',   'librenms_ucd_cpu_user_ticks_total',   'counter', 'ticks'),
            self::entry('ucd.cpu.nice',   'ucd_cpu', 'nice',   'librenms_ucd_cpu_nice_ticks_total',   'counter', 'ticks'),
            self::entry('ucd.cpu.system', 'ucd_cpu', 'system', 'librenms_ucd_cpu_system_ticks_total', 'counter', 'ticks'),
            self::entry('ucd.cpu.idle',   'ucd_cpu', 'idle',   'librenms_ucd_cpu_idle_ticks_total',   'counter', 'ticks'),

            // printer supplies (measurement 'toner', gauge percent)
            self::entry('printer_supply.level', 'toner', 'toner', 'librenms_printer_supply_level_percent', 'gauge', 'percent', ['hostname', 'supply_type', 'supply_index']),

            // netstats-icmp
            self::entry('netstats.icmpInMsgs',        'netstats-icmp', 'icmpInMsgs',        'librenms_netstats_icmp_in_msgs_total',          'counter', 'packets'),
            self::entry('netstats.icmpOutMsgs',       'netstats-icmp', 'icmpOutMsgs',       'librenms_netstats_icmp_out_msgs_total',         'counter', 'packets'),
            self::entry('netstats.icmpInErrors',      'netstats-icmp', 'icmpInErrors',      'librenms_netstats_icmp_in_errors_total',        'counter', 'packets'),
            self::entry('netstats.icmpOutErrors',     'netstats-icmp', 'icmpOutErrors',     'librenms_netstats_icmp_out_errors_total',       'counter', 'packets'),
            self::entry('netstats.icmpInEchos',       'netstats-icmp', 'icmpInEchos',       'librenms_netstats_icmp_in_echos_total',         'counter', 'packets'),
            self::entry('netstats.icmpOutEchos',      'netstats-icmp', 'icmpOutEchos',      'librenms_netstats_icmp_out_echos_total',        'counter', 'packets'),
            self::entry('netstats.icmpInEchoReps',    'netstats-icmp', 'icmpInEchoReps',    'librenms_netstats_icmp_in_echo_reps_total',     'counter', 'packets'),
            self::entry('netstats.icmpOutEchoReps',   'netstats-icmp', 'icmpOutEchoReps',   'librenms_netstats_icmp_out_echo_reps_total',    'counter', 'packets'),
            self::entry('netstats.icmpInSrcQuenchs',  'netstats-icmp', 'icmpInSrcQuenchs',  'librenms_netstats_icmp_in_src_quenchs_total',   'counter', 'packets'),
            self::entry('netstats.icmpOutSrcQuenchs', 'netstats-icmp', 'icmpOutSrcQuenchs', 'librenms_netstats_icmp_out_src_quenchs_total',  'counter', 'packets'),
            self::entry('netstats.icmpInRedirects',   'netstats-icmp', 'icmpInRedirects',   'librenms_netstats_icmp_in_redirects_total',     'counter', 'packets'),
            self::entry('netstats.icmpOutRedirects',  'netstats-icmp', 'icmpOutRedirects',  'librenms_netstats_icmp_out_redirects_total',    'counter', 'packets'),
            self::entry('netstats.icmpInAddrMasks',   'netstats-icmp', 'icmpInAddrMasks',   'librenms_netstats_icmp_in_addr_masks_total',    'counter', 'packets'),
            self::entry('netstats.icmpOutAddrMasks',  'netstats-icmp', 'icmpOutAddrMasks',  'librenms_netstats_icmp_out_addr_masks_total',   'counter', 'packets'),
            self::entry('netstats.icmpInAddrMaskReps',  'netstats-icmp', 'icmpInAddrMaskReps',  'librenms_netstats_icmp_in_addr_mask_reps_total',  'counter', 'packets'),
            self::entry('netstats.icmpOutAddrMaskReps', 'netstats-icmp', 'icmpOutAddrMaskReps', 'librenms_netstats_icmp_out_addr_mask_reps_total', 'counter', 'packets'),

            // netstats-ip
            self::entry('netstats.ipForwDatagrams', 'netstats-ip', 'ipForwDatagrams', 'librenms_netstats_ip_forw_datagrams_total', 'counter', 'packets'),
            self::entry('netstats.ipInDelivers',    'netstats-ip', 'ipInDelivers',    'librenms_netstats_ip_in_delivers_total',    'counter', 'packets'),
            self::entry('netstats.ipInReceives',    'netstats-ip', 'ipInReceives',    'librenms_netstats_ip_in_receives_total',    'counter', 'packets'),
            self::entry('netstats.ipOutRequests',   'netstats-ip', 'ipOutRequests',   'librenms_netstats_ip_out_requests_total',   'counter', 'packets'),
            self::entry('netstats.ipInDiscards',    'netstats-ip', 'ipInDiscards',    'librenms_netstats_ip_in_discards_total',    'counter', 'packets'),
            self::entry('netstats.ipOutDiscards',   'netstats-ip', 'ipOutDiscards',   'librenms_netstats_ip_out_discards_total',   'counter', 'packets'),
            self::entry('netstats.ipOutNoRoutes',   'netstats-ip', 'ipOutNoRoutes',   'librenms_netstats_ip_out_no_routes_total',  'counter', 'packets'),
            self::entry('netstats.ipFragOKs',       'netstats-ip', 'ipFragOKs',       'librenms_netstats_ip_frag_oks_total',       'counter', 'packets'),
            self::entry('netstats.ipFragFails',     'netstats-ip', 'ipFragFails',     'librenms_netstats_ip_frag_fails_total',     'counter', 'packets'),
            self::entry('netstats.ipReasmOKs',      'netstats-ip', 'ipReasmOKs',      'librenms_netstats_ip_reasm_oks_total',      'counter', 'packets'),
            self::entry('netstats.ipReasmFails',    'netstats-ip', 'ipReasmFails',    'librenms_netstats_ip_reasm_fails_total',    'counter', 'packets'),
            self::entry('netstats.ipFragCreates',   'netstats-ip', 'ipFragCreates',   'librenms_netstats_ip_frag_creates_total',   'counter', 'packets'),
            self::entry('netstats.ipReasmReqds',    'netstats-ip', 'ipReasmReqds',    'librenms_netstats_ip_reasm_reqds_total',    'counter', 'packets'),

            // netstats-snmp
            self::entry('netstats.snmpInTraps',         'netstats-snmp', 'snmpInTraps',         'librenms_netstats_snmp_in_traps_total',           'counter', 'packets'),
            self::entry('netstats.snmpOutTraps',        'netstats-snmp', 'snmpOutTraps',        'librenms_netstats_snmp_out_traps_total',          'counter', 'packets'),
            self::entry('netstats.snmpInTotalReqVars',  'netstats-snmp', 'snmpInTotalReqVars',  'librenms_netstats_snmp_in_total_req_vars_total',  'counter', 'packets'),
            self::entry('netstats.snmpInTotalSetVars',  'netstats-snmp', 'snmpInTotalSetVars',  'librenms_netstats_snmp_in_total_set_vars_total',  'counter', 'packets'),
            self::entry('netstats.snmpOutGetResponses', 'netstats-snmp', 'snmpOutGetResponses', 'librenms_netstats_snmp_out_get_responses_total',  'counter', 'packets'),
            self::entry('netstats.snmpOutSetRequests',  'netstats-snmp', 'snmpOutSetRequests',  'librenms_netstats_snmp_out_set_requests_total',   'counter', 'packets'),
            self::entry('netstats.snmpInPkts',          'netstats-snmp', 'snmpInPkts',          'librenms_netstats_snmp_in_pkts_total',            'counter', 'packets'),
            self::entry('netstats.snmpOutPkts',         'netstats-snmp', 'snmpOutPkts',         'librenms_netstats_snmp_out_pkts_total',           'counter', 'packets'),

            // netstats-tcp
            self::entry('netstats.tcpInSegs',       'netstats-tcp', 'tcpInSegs',       'librenms_netstats_tcp_in_segs_total',       'counter', 'segments'),
            self::entry('netstats.tcpOutSegs',      'netstats-tcp', 'tcpOutSegs',      'librenms_netstats_tcp_out_segs_total',      'counter', 'segments'),
            self::entry('netstats.tcpActiveOpens',  'netstats-tcp', 'tcpActiveOpens',  'librenms_netstats_tcp_active_opens_total',  'counter', 'connections'),
            self::entry('netstats.tcpPassiveOpens', 'netstats-tcp', 'tcpPassiveOpens', 'librenms_netstats_tcp_passive_opens_total', 'counter', 'connections'),
            self::entry('netstats.tcpAttemptFails', 'netstats-tcp', 'tcpAttemptFails', 'librenms_netstats_tcp_attempt_fails_total', 'counter', 'connections'),
            self::entry('netstats.tcpEstabResets',  'netstats-tcp', 'tcpEstabResets',  'librenms_netstats_tcp_estab_resets_total',  'counter', 'connections'),
            self::entry('netstats.tcpRetransSegs',  'netstats-tcp', 'tcpRetransSegs',  'librenms_netstats_tcp_retrans_segs_total',  'counter', 'segments'),

            // netstats-udp
            self::entry('netstats.udpInDatagrams',  'netstats-udp', 'udpInDatagrams',  'librenms_netstats_udp_in_datagrams_total',  'counter', 'packets'),
            self::entry('netstats.udpOutDatagrams', 'netstats-udp', 'udpOutDatagrams', 'librenms_netstats_udp_out_datagrams_total', 'counter', 'packets'),
            self::entry('netstats.udpInErrors',     'netstats-udp', 'udpInErrors',     'librenms_netstats_udp_in_errors_total',     'counter', 'packets'),
            self::entry('netstats.udpNoPorts',      'netstats-udp', 'udpNoPorts',      'librenms_netstats_udp_no_ports_total',      'counter', 'packets'),

            // ipSystemStats-ipv4
            self::entry('ipsystemstats.ipv4.InReceives',     'ipSystemStats-ipv4', 'InReceives',     'librenms_ipsystemstats_ipv4_in_receives_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.InForwDatagrams','ipSystemStats-ipv4', 'InForwDatagrams','librenms_ipsystemstats_ipv4_in_forw_datagrams_total', 'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.InDelivers',     'ipSystemStats-ipv4', 'InDelivers',     'librenms_ipsystemstats_ipv4_in_delivers_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.OutRequests',    'ipSystemStats-ipv4', 'OutRequests',    'librenms_ipsystemstats_ipv4_out_requests_total',    'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.OutForwDatagrams','ipSystemStats-ipv4','OutForwDatagrams','librenms_ipsystemstats_ipv4_out_forw_datagrams_total','counter','packets'),
            self::entry('ipsystemstats.ipv4.OutFragFails',   'ipSystemStats-ipv4', 'OutFragFails',   'librenms_ipsystemstats_ipv4_out_frag_fails_total',  'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.OutFragCreates', 'ipSystemStats-ipv4', 'OutFragCreates', 'librenms_ipsystemstats_ipv4_out_frag_creates_total', 'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.ReasmOKs',       'ipSystemStats-ipv4', 'ReasmOKs',       'librenms_ipsystemstats_ipv4_reasm_oks_total',       'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.ReasmFails',     'ipSystemStats-ipv4', 'ReasmFails',     'librenms_ipsystemstats_ipv4_reasm_fails_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv4.ReasmReqds',     'ipSystemStats-ipv4', 'ReasmReqds',     'librenms_ipsystemstats_ipv4_reasm_reqds_total',     'counter', 'packets'),

            // ipSystemStats-ipv6
            self::entry('ipsystemstats.ipv6.InReceives',     'ipSystemStats-ipv6', 'InReceives',     'librenms_ipsystemstats_ipv6_in_receives_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.InForwDatagrams','ipSystemStats-ipv6', 'InForwDatagrams','librenms_ipsystemstats_ipv6_in_forw_datagrams_total', 'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.InDelivers',     'ipSystemStats-ipv6', 'InDelivers',     'librenms_ipsystemstats_ipv6_in_delivers_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.OutRequests',    'ipSystemStats-ipv6', 'OutRequests',    'librenms_ipsystemstats_ipv6_out_requests_total',    'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.OutForwDatagrams','ipSystemStats-ipv6','OutForwDatagrams','librenms_ipsystemstats_ipv6_out_forw_datagrams_total','counter','packets'),
            self::entry('ipsystemstats.ipv6.OutFragFails',   'ipSystemStats-ipv6', 'OutFragFails',   'librenms_ipsystemstats_ipv6_out_frag_fails_total',  'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.OutFragCreates', 'ipSystemStats-ipv6', 'OutFragCreates', 'librenms_ipsystemstats_ipv6_out_frag_creates_total', 'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.ReasmOKs',       'ipSystemStats-ipv6', 'ReasmOKs',       'librenms_ipsystemstats_ipv6_reasm_oks_total',       'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.ReasmFails',     'ipSystemStats-ipv6', 'ReasmFails',     'librenms_ipsystemstats_ipv6_reasm_fails_total',     'counter', 'packets'),
            self::entry('ipsystemstats.ipv6.ReasmReqds',     'ipSystemStats-ipv6', 'ReasmReqds',     'librenms_ipsystemstats_ipv6_reasm_reqds_total',     'counter', 'packets'),
        ];

        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry->key] = $entry;
        }

        return $byKey;
    }

    /**
     * @param string[] $identityLabels
     */
    private static function entry(
        string $key,
        string $measurement,
        string $field,
        string $name,
        string $type,
        string $unit,
        array $identityLabels = ['hostname'],
    ): MetricCatalogEntry {
        return new MetricCatalogEntry(
            key: $key,
            measurement: $measurement,
            field: $field,
            definition: new MetricDefinition($name, $type, $unit),
            identityLabels: $identityLabels,
        );
    }

    /**
     * @return string[]
     */
    private static function portLabels(): array
    {
        return ['hostname', 'ifIndex'];
    }
}
