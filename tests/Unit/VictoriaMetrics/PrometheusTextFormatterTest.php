<?php

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use LibreNMS\Tests\TestCase;
use LibreNMS\Util\VictoriaMetrics\MetricDefinition;
use LibreNMS\Util\VictoriaMetrics\PrometheusTextFormatter;

final class PrometheusTextFormatterTest extends TestCase
{
    public function testFormatsPrometheusTextWithTypeLabelsValueAndTimestamp(): void
    {
        $metric = new MetricDefinition('librenms_port_if_in_octets_total', 'counter', 'octets');
        $line = PrometheusTextFormatter::format($metric, [
            'source' => 'librenms',
            'device_id' => '1',
            'hostname' => 'router1',
            'entity_type' => 'port',
            'port_id' => '7',
            'ifName' => 'Gi0/0',
        ], 123456789.0, 1779475200000);

        $this->assertSame(
            "# TYPE librenms_port_if_in_octets_total counter\n"
            . 'librenms_port_if_in_octets_total{device_id="1",entity_type="port",hostname="router1",ifName="Gi0/0",port_id="7",source="librenms"} 123456789 1779475200000',
            $line
        );
    }

    public function testEscapesPrometheusLabelValues(): void
    {
        $metric = new MetricDefinition('librenms_device_poller_duration_seconds', 'gauge', 'seconds');
        $line = PrometheusTextFormatter::format($metric, [
            'hostname' => "host\\name\nwith \"quotes\"",
            'bad-label' => 'skipped',
        ], 1.5, 1779475200000);

        $this->assertStringContainsString('hostname="host\\\\name\\nwith \\"quotes\\""', $line);
        $this->assertStringNotContainsString('bad-label', $line);
    }
}
