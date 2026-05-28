<?php

/**
 * VictoriaMetricsMetricCatalogTest.php
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

final class VictoriaMetricsMetricCatalogTest extends TestCase
{
    public function testLooksUpMetricByCatalogKey(): void
    {
        $entry = VictoriaMetricsMetricCatalog::get('port.if_in_errors');

        $this->assertSame('ports', $entry->measurement);
        $this->assertSame('INERRORS', $entry->field);
        $this->assertSame('librenms_port_if_in_errors_total', $entry->definition->name);
        $this->assertSame('counter', $entry->definition->type);
        $this->assertSame(['hostname', 'ifIndex'], $entry->identityLabels);
    }

    public function testLooksUpMetricByMeasurementAndField(): void
    {
        $entry = VictoriaMetricsMetricCatalog::getByMeasurementField('sensor', 'sensor');

        $this->assertSame('sensor.value', $entry->key);
        $this->assertSame('librenms_sensor_value', $entry->definition->name);
        $this->assertSame(['hostname', 'sensor_class', 'sensor_type', 'sensor_index'], $entry->identityLabels);
    }

    public function testUnknownMetricReturnsNull(): void
    {
        $this->assertNull(VictoriaMetricsMetricCatalog::get('missing.metric'));
        $this->assertNull(VictoriaMetricsMetricCatalog::getByMeasurementField('ports', 'missing'));
    }
}
