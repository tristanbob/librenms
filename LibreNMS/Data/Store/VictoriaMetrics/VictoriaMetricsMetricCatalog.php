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
 * @copyright  2026 LibreNMS Contributors
 * @copyright  2026 Tristan
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

            self::entry('sensor.value', 'sensor', 'sensor', 'librenms_sensor_value', 'gauge', 'value', ['device_id', 'sensor_class', 'sensor_type', 'sensor_index']),
            self::entry('wireless_sensor.value', 'wireless-sensor', 'sensor', 'librenms_wireless_sensor_value', 'gauge', 'value', ['device_id', 'sensor_class', 'sensor_type', 'sensor_index']),
            self::entry('mempool.used', 'mempool', 'used', 'librenms_mempool_used_bytes', 'gauge', 'bytes', ['device_id', 'mempool_type', 'mempool_class', 'mempool_index']),
            self::entry('mempool.free', 'mempool', 'free', 'librenms_mempool_free_bytes', 'gauge', 'bytes', ['device_id', 'mempool_type', 'mempool_class', 'mempool_index']),
            self::entry('storage.used', 'storage', 'used', 'librenms_storage_used_bytes', 'gauge', 'bytes', ['device_id', 'type', 'descr']),
            self::entry('storage.free', 'storage', 'free', 'librenms_storage_free_bytes', 'gauge', 'bytes', ['device_id', 'type', 'descr']),
            self::entry('processor.usage', 'processors', 'usage', 'librenms_processor_usage_percent', 'gauge', 'percent', ['device_id', 'processor_type', 'processor_index']),
            self::entry('icmp.avg_rtt', 'icmp-perf', 'avg', 'librenms_icmp_avg_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.min_rtt', 'icmp-perf', 'min', 'librenms_icmp_min_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.max_rtt', 'icmp-perf', 'max', 'librenms_icmp_max_rtt_milliseconds', 'gauge', 'milliseconds'),
            self::entry('icmp.transmitted', 'icmp-perf', 'xmt', 'librenms_icmp_transmitted_total', 'gauge', 'packets'),
            self::entry('icmp.received', 'icmp-perf', 'rcv', 'librenms_icmp_received_total', 'gauge', 'packets'),
            self::entry('device.uptime', 'uptime', 'uptime', 'librenms_device_uptime_seconds', 'gauge', 'seconds'),
            self::entry('device.availability', 'availability', 'availability', 'librenms_device_availability_percent', 'gauge', 'percent', ['device_id', 'name']),
            self::entry('ospf.instances', 'ospf-statistics', 'instances', 'librenms_ospf_instances', 'gauge', 'count'),
            self::entry('ospf.areas', 'ospf-statistics', 'areas', 'librenms_ospf_areas', 'gauge', 'count'),
            self::entry('ospf.ports', 'ospf-statistics', 'ports', 'librenms_ospf_ports', 'gauge', 'count'),
            self::entry('ospf.neighbours', 'ospf-statistics', 'neighbours', 'librenms_ospf_neighbours', 'gauge', 'count'),
            self::entry('sla.rtt', 'sla', 'rtt', 'librenms_sla_rtt_milliseconds', 'gauge', 'milliseconds', ['device_id', 'sla_nr']),
            self::entry('diskio.read_bytes', 'ucd_diskio', 'read', 'librenms_diskio_read_bytes_total', 'counter', 'bytes', ['device_id', 'descr']),
            self::entry('diskio.written_bytes', 'ucd_diskio', 'written', 'librenms_diskio_written_bytes_total', 'counter', 'bytes', ['device_id', 'descr']),
            self::entry('diskio.reads', 'ucd_diskio', 'reads', 'librenms_diskio_reads_total', 'counter', 'operations', ['device_id', 'descr']),
            self::entry('diskio.writes', 'ucd_diskio', 'writes', 'librenms_diskio_writes_total', 'counter', 'operations', ['device_id', 'descr']),
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
        array $identityLabels = ['device_id'],
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
        return ['device_id', 'ifIndex'];
    }
}
