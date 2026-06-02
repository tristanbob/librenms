<?php

/**
 * MetricMapperTest.php
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

use LibreNMS\Data\Store\VictoriaMetrics\MetricMapper;
use LibreNMS\Tests\TestCase;

final class MetricMapperTest extends TestCase
{
    public function testKnownPollerMetricMapsToPrometheusDefinition(): void
    {
        $metric = MetricMapper::map('poller-perf', 'poller');

        $this->assertSame('librenms_device_poller_duration_seconds', $metric->name);
        $this->assertSame('gauge', $metric->type);
        $this->assertSame('seconds', $metric->unit);
    }

    public function testKnownPortCountersMapToPrometheusDefinitions(): void
    {
        $this->assertSame('librenms_port_if_in_octets_total', MetricMapper::map('ports', 'INOCTETS')->name);
        $this->assertSame('counter', MetricMapper::map('ports', 'INOCTETS')->type);
        $this->assertSame('librenms_port_if_out_octets_total', MetricMapper::map('ports', 'OUTOCTETS')->name);
        $this->assertSame('librenms_port_if_in_errors_total', MetricMapper::map('ports', 'INERRORS')->name);
        $this->assertSame('librenms_port_if_out_errors_total', MetricMapper::map('ports', 'OUTERRORS')->name);
        $this->assertSame('librenms_port_if_in_discards_total', MetricMapper::map('ports', 'INDISCARDS')->name);
        $this->assertSame('librenms_port_if_out_discards_total', MetricMapper::map('ports', 'OUTDISCARDS')->name);
    }

    public function testKnownPortRatesMapToPrometheusDefinitions(): void
    {
        $this->assertSame('librenms_port_if_in_bits_per_second', MetricMapper::map('ports', 'ifInBits_rate')->name);
        $this->assertSame('gauge', MetricMapper::map('ports', 'ifInBits_rate')->type);
        $this->assertSame('bits_per_second', MetricMapper::map('ports', 'ifInBits_rate')->unit);
        $this->assertSame('librenms_port_if_out_bits_per_second', MetricMapper::map('ports', 'ifOutBits_rate')->name);
    }

    public function testUnknownMetricsAreSkipped(): void
    {
        $this->assertNull(MetricMapper::map('ports', 'ifAlias'));
        $this->assertNull(MetricMapper::map('foo-bar', 'baz.val'));
        $this->assertNull(MetricMapper::map('ports', 'inoctets'));
    }

    public function testAdditionalPortCountersAreMapped(): void
    {
        $this->assertSame('librenms_port_if_in_ucast_pkts_total', MetricMapper::map('ports', 'INUCASTPKTS')->name);
        $this->assertSame('librenms_port_if_out_ucast_pkts_total', MetricMapper::map('ports', 'OUTUCASTPKTS')->name);
        $this->assertSame('librenms_port_if_in_broadcast_pkts_total', MetricMapper::map('ports', 'INBROADCASTPKTS')->name);
        $this->assertSame('librenms_port_if_out_broadcast_pkts_total', MetricMapper::map('ports', 'OUTBROADCASTPKTS')->name);
        $this->assertSame('librenms_port_if_in_multicast_pkts_total', MetricMapper::map('ports', 'INMULTICASTPKTS')->name);
        $this->assertSame('librenms_port_if_out_multicast_pkts_total', MetricMapper::map('ports', 'OUTMULTICASTPKTS')->name);
        $this->assertSame('librenms_port_if_in_unknown_protos_total', MetricMapper::map('ports', 'INUNKNOWNPROTOS')->name);
        $this->assertSame('counter', MetricMapper::map('ports', 'INUCASTPKTS')->type);
    }

    public function testAdditionalPortRatesAreMapped(): void
    {
        $this->assertSame('librenms_port_if_in_ucast_pkts_per_second', MetricMapper::map('ports', 'ifInUcastPkts_rate')->name);
        $this->assertSame('librenms_port_if_out_ucast_pkts_per_second', MetricMapper::map('ports', 'ifOutUcastPkts_rate')->name);
        $this->assertSame('librenms_port_if_in_errors_per_second', MetricMapper::map('ports', 'ifInErrors_rate')->name);
        $this->assertSame('librenms_port_if_out_errors_per_second', MetricMapper::map('ports', 'ifOutErrors_rate')->name);
        $this->assertSame('gauge', MetricMapper::map('ports', 'ifInUcastPkts_rate')->type);
    }

    public function testSensorMetricIsMapped(): void
    {
        $metric = MetricMapper::map('sensor', 'sensor');

        $this->assertSame('librenms_sensor_value', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testWirelessSensorMetricIsMapped(): void
    {
        $metric = MetricMapper::map('wireless-sensor', 'sensor');

        $this->assertSame('librenms_wireless_sensor_value', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testMempoolMetricsAreMapped(): void
    {
        $this->assertSame('librenms_mempool_used_bytes', MetricMapper::map('mempool', 'used')->name);
        $this->assertSame('librenms_mempool_free_bytes', MetricMapper::map('mempool', 'free')->name);
        $this->assertSame('gauge', MetricMapper::map('mempool', 'used')->type);
        $this->assertSame('bytes', MetricMapper::map('mempool', 'used')->unit);
    }

    public function testStorageMetricsAreMapped(): void
    {
        $this->assertSame('librenms_storage_used_bytes', MetricMapper::map('storage', 'used')->name);
        $this->assertSame('librenms_storage_free_bytes', MetricMapper::map('storage', 'free')->name);
        $this->assertSame('gauge', MetricMapper::map('storage', 'used')->type);
    }

    public function testProcessorMetricIsMapped(): void
    {
        $metric = MetricMapper::map('processors', 'usage');

        $this->assertSame('librenms_processor_usage_percent', $metric->name);
        $this->assertSame('gauge', $metric->type);
        $this->assertSame('percent', $metric->unit);
    }

    public function testIcmpPerfMetricsAreMapped(): void
    {
        $this->assertSame('librenms_icmp_avg_rtt_milliseconds', MetricMapper::map('icmp-perf', 'avg')->name);
        $this->assertSame('librenms_icmp_min_rtt_milliseconds', MetricMapper::map('icmp-perf', 'min')->name);
        $this->assertSame('librenms_icmp_max_rtt_milliseconds', MetricMapper::map('icmp-perf', 'max')->name);
        $this->assertSame('librenms_icmp_transmitted_total', MetricMapper::map('icmp-perf', 'xmt')->name);
        $this->assertSame('librenms_icmp_received_total', MetricMapper::map('icmp-perf', 'rcv')->name);
        $this->assertSame('gauge', MetricMapper::map('icmp-perf', 'avg')->type);
    }

    public function testUptimeMetricIsMapped(): void
    {
        $metric = MetricMapper::map('uptime', 'uptime');

        $this->assertSame('librenms_device_uptime_seconds', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testAvailabilityMetricIsMapped(): void
    {
        $metric = MetricMapper::map('availability', 'availability');

        $this->assertSame('librenms_device_availability_percent', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testOspfMetricsAreMapped(): void
    {
        $this->assertSame('librenms_ospf_instances', MetricMapper::map('ospf-statistics', 'instances')->name);
        $this->assertSame('librenms_ospf_areas', MetricMapper::map('ospf-statistics', 'areas')->name);
        $this->assertSame('librenms_ospf_ports', MetricMapper::map('ospf-statistics', 'ports')->name);
        $this->assertSame('librenms_ospf_neighbours', MetricMapper::map('ospf-statistics', 'neighbours')->name);
        $this->assertSame('gauge', MetricMapper::map('ospf-statistics', 'instances')->type);
    }

    public function testSlaMetricIsMapped(): void
    {
        $metric = MetricMapper::map('sla', 'rtt');

        $this->assertSame('librenms_sla_rtt_milliseconds', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testDiskIoMetricsAreMapped(): void
    {
        $this->assertSame('librenms_diskio_read_bytes_total', MetricMapper::map('ucd_diskio', 'read')->name);
        $this->assertSame('librenms_diskio_written_bytes_total', MetricMapper::map('ucd_diskio', 'written')->name);
        $this->assertSame('librenms_diskio_reads_total', MetricMapper::map('ucd_diskio', 'reads')->name);
        $this->assertSame('librenms_diskio_writes_total', MetricMapper::map('ucd_diskio', 'writes')->name);
        $this->assertSame('counter', MetricMapper::map('ucd_diskio', 'read')->type);
    }
}
