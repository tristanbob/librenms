<?php

/**
 * PrometheusTextFormatterTest.php
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

use LibreNMS\Data\Store\VictoriaMetrics\MetricDefinition;
use LibreNMS\Data\Store\VictoriaMetrics\PrometheusTextFormatter;
use LibreNMS\Tests\TestCase;

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
