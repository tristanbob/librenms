<?php

/**
 * MetricMapper.php
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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Data\Store\VictoriaMetrics;

class MetricMapper
{
    public static function map(string $measurement, string $field): ?MetricDefinition
    {
        $def = self::resolvedMap()[$measurement][$field] ?? null;

        return $def === null
            ? null
            : new MetricDefinition($def['name'], $def['type'], $def['unit']);
    }

    // ---------------------------------------------------------------------------
    // Internal — lazy-initialized flat map indexed by [measurement][field]
    // ---------------------------------------------------------------------------

    /** @var array<string, array<string, array{name: string, type: string, unit: string}>>|null */
    private static ?array $map = null;

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function resolvedMap(): array
    {
        return self::$map ??= array_merge(
            self::pollerPerfMap(),
            self::portsMap(),
            self::sensorMap(),
            self::wirelessSensorMap(),
            self::mempoolMap(),
            self::storageMap(),
            self::processorsMap(),
            self::icmpPerfMap(),
            self::uptimeMap(),
            self::availabilityMap(),
            self::ospfMap(),
            self::slaMap(),
            self::diskIoMap(),
        );
    }

    // ---------------------------------------------------------------------------
    // Per-measurement maps
    // ---------------------------------------------------------------------------

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function pollerPerfMap(): array
    {
        return [
            'poller-perf' => [
                'poller' => ['name' => 'librenms_device_poller_duration_seconds', 'type' => 'gauge', 'unit' => 'seconds'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function portsMap(): array
    {
        return [
            'ports' => [
                // Counters (raw cumulative values from SNMP)
                'INOCTETS'         => ['name' => 'librenms_port_if_in_octets_total',            'type' => 'counter', 'unit' => 'octets'],
                'OUTOCTETS'        => ['name' => 'librenms_port_if_out_octets_total',           'type' => 'counter', 'unit' => 'octets'],
                'INERRORS'         => ['name' => 'librenms_port_if_in_errors_total',            'type' => 'counter', 'unit' => 'errors'],
                'OUTERRORS'        => ['name' => 'librenms_port_if_out_errors_total',           'type' => 'counter', 'unit' => 'errors'],
                'INDISCARDS'       => ['name' => 'librenms_port_if_in_discards_total',          'type' => 'counter', 'unit' => 'discards'],
                'OUTDISCARDS'      => ['name' => 'librenms_port_if_out_discards_total',         'type' => 'counter', 'unit' => 'discards'],
                'INUCASTPKTS'      => ['name' => 'librenms_port_if_in_ucast_pkts_total',        'type' => 'counter', 'unit' => 'packets'],
                'OUTUCASTPKTS'     => ['name' => 'librenms_port_if_out_ucast_pkts_total',       'type' => 'counter', 'unit' => 'packets'],
                'INNUCASTPKTS'     => ['name' => 'librenms_port_if_in_nucast_pkts_total',       'type' => 'counter', 'unit' => 'packets'],
                'OUTNUCASTPKTS'    => ['name' => 'librenms_port_if_out_nucast_pkts_total',      'type' => 'counter', 'unit' => 'packets'],
                'INBROADCASTPKTS'  => ['name' => 'librenms_port_if_in_broadcast_pkts_total',    'type' => 'counter', 'unit' => 'packets'],
                'OUTBROADCASTPKTS' => ['name' => 'librenms_port_if_out_broadcast_pkts_total',   'type' => 'counter', 'unit' => 'packets'],
                'INMULTICASTPKTS'  => ['name' => 'librenms_port_if_in_multicast_pkts_total',    'type' => 'counter', 'unit' => 'packets'],
                'OUTMULTICASTPKTS' => ['name' => 'librenms_port_if_out_multicast_pkts_total',   'type' => 'counter', 'unit' => 'packets'],
                'INUNKNOWNPROTOS'  => ['name' => 'librenms_port_if_in_unknown_protos_total',    'type' => 'counter', 'unit' => 'packets'],
                // Pre-computed rate gauges
                'ifInBits_rate'       => ['name' => 'librenms_port_if_in_bits_per_second',         'type' => 'gauge', 'unit' => 'bits_per_second'],
                'ifOutBits_rate'      => ['name' => 'librenms_port_if_out_bits_per_second',        'type' => 'gauge', 'unit' => 'bits_per_second'],
                'ifInUcastPkts_rate'  => ['name' => 'librenms_port_if_in_ucast_pkts_per_second',   'type' => 'gauge', 'unit' => 'packets_per_second'],
                'ifOutUcastPkts_rate' => ['name' => 'librenms_port_if_out_ucast_pkts_per_second',  'type' => 'gauge', 'unit' => 'packets_per_second'],
                'ifInErrors_rate'     => ['name' => 'librenms_port_if_in_errors_per_second',       'type' => 'gauge', 'unit' => 'errors_per_second'],
                'ifOutErrors_rate'    => ['name' => 'librenms_port_if_out_errors_per_second',      'type' => 'gauge', 'unit' => 'errors_per_second'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function sensorMap(): array
    {
        // All health sensor classes share one metric name; sensor_class is a label.
        return [
            'sensor' => [
                'sensor' => ['name' => 'librenms_sensor_value', 'type' => 'gauge', 'unit' => 'value'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function wirelessSensorMap(): array
    {
        return [
            'wireless-sensor' => [
                'sensor' => ['name' => 'librenms_wireless_sensor_value', 'type' => 'gauge', 'unit' => 'value'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function mempoolMap(): array
    {
        return [
            'mempool' => [
                'used' => ['name' => 'librenms_mempool_used_bytes', 'type' => 'gauge', 'unit' => 'bytes'],
                'free' => ['name' => 'librenms_mempool_free_bytes', 'type' => 'gauge', 'unit' => 'bytes'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function storageMap(): array
    {
        return [
            'storage' => [
                'used' => ['name' => 'librenms_storage_used_bytes', 'type' => 'gauge', 'unit' => 'bytes'],
                'free' => ['name' => 'librenms_storage_free_bytes', 'type' => 'gauge', 'unit' => 'bytes'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function processorsMap(): array
    {
        return [
            'processors' => [
                'usage' => ['name' => 'librenms_processor_usage_percent', 'type' => 'gauge', 'unit' => 'percent'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function icmpPerfMap(): array
    {
        return [
            'icmp-perf' => [
                'avg' => ['name' => 'librenms_icmp_avg_rtt_milliseconds', 'type' => 'gauge', 'unit' => 'milliseconds'],
                'min' => ['name' => 'librenms_icmp_min_rtt_milliseconds', 'type' => 'gauge', 'unit' => 'milliseconds'],
                'max' => ['name' => 'librenms_icmp_max_rtt_milliseconds', 'type' => 'gauge', 'unit' => 'milliseconds'],
                'xmt' => ['name' => 'librenms_icmp_transmitted_total',    'type' => 'gauge', 'unit' => 'packets'],
                'rcv' => ['name' => 'librenms_icmp_received_total',       'type' => 'gauge', 'unit' => 'packets'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function uptimeMap(): array
    {
        return [
            'uptime' => [
                'uptime' => ['name' => 'librenms_device_uptime_seconds', 'type' => 'gauge', 'unit' => 'seconds'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function availabilityMap(): array
    {
        return [
            'availability' => [
                'availability' => ['name' => 'librenms_device_availability_percent', 'type' => 'gauge', 'unit' => 'percent'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function ospfMap(): array
    {
        return [
            'ospf-statistics' => [
                'instances'  => ['name' => 'librenms_ospf_instances',  'type' => 'gauge', 'unit' => 'count'],
                'areas'      => ['name' => 'librenms_ospf_areas',      'type' => 'gauge', 'unit' => 'count'],
                'ports'      => ['name' => 'librenms_ospf_ports',      'type' => 'gauge', 'unit' => 'count'],
                'neighbours' => ['name' => 'librenms_ospf_neighbours', 'type' => 'gauge', 'unit' => 'count'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function slaMap(): array
    {
        return [
            'sla' => [
                'rtt' => ['name' => 'librenms_sla_rtt_milliseconds', 'type' => 'gauge', 'unit' => 'milliseconds'],
            ],
        ];
    }

    /** @return array<string, array<string, array{name: string, type: string, unit: string}>> */
    private static function diskIoMap(): array
    {
        return [
            'ucd_diskio' => [
                'read'    => ['name' => 'librenms_diskio_read_bytes_total',    'type' => 'counter', 'unit' => 'bytes'],
                'written' => ['name' => 'librenms_diskio_written_bytes_total', 'type' => 'counter', 'unit' => 'bytes'],
                'reads'   => ['name' => 'librenms_diskio_reads_total',         'type' => 'counter', 'unit' => 'operations'],
                'writes'  => ['name' => 'librenms_diskio_writes_total',        'type' => 'counter', 'unit' => 'operations'],
            ],
        ];
    }
}
