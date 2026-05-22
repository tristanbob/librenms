<?php

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use LibreNMS\Tests\TestCase;
use LibreNMS\Util\VictoriaMetrics\MetricMapper;

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
}
