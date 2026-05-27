<?php

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
        $this->assertSame(['device_id', 'ifIndex'], $entry->identityLabels);
    }

    public function testLooksUpMetricByMeasurementAndField(): void
    {
        $entry = VictoriaMetricsMetricCatalog::getByMeasurementField('sensor', 'sensor');

        $this->assertSame('sensor.value', $entry->key);
        $this->assertSame('librenms_sensor_value', $entry->definition->name);
        $this->assertSame(['device_id', 'sensor_class', 'sensor_type', 'sensor_index'], $entry->identityLabels);
    }

    public function testUnknownMetricReturnsNull(): void
    {
        $this->assertNull(VictoriaMetricsMetricCatalog::get('missing.metric'));
        $this->assertNull(VictoriaMetricsMetricCatalog::getByMeasurementField('ports', 'missing'));
    }
}
