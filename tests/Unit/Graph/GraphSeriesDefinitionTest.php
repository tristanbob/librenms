<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\GraphSeriesDefinition;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Tests\TestCase;

final class GraphSeriesDefinitionTest extends TestCase
{
    public function testStoresBackendSpecificBindingsSeparatelyFromSeriesSemantics(): void
    {
        $binding = new RrdMetricBinding(
            rrdName: 'poller-perf',
            ds: 'poller',
            consolidation: 'MAX',
            step: 3600,
        );

        $series = new GraphSeriesDefinition(
            name: 'Poller time',
            key: 'poller_time',
            unit: 'seconds',
            area: true,
            bindings: [$binding],
        );

        $this->assertSame('Poller time', $series->name);
        $this->assertSame('seconds', $series->unit);
        $this->assertSame($binding, $series->binding(RrdMetricBinding::SOURCE));
        $this->assertNull($series->binding('victoriametrics'));
    }
}
