<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\TestCase;

final class GraphQueryTest extends TestCase
{
    public function testRejectsZeroWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GraphQuery::fromRequest('device', 'device_poller_perf', ['device_id' => 1], 1000, 2000, 0);
    }

    public function testRejectsInvalidTimeRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GraphQuery::fromRequest('device', 'device_poller_perf', ['device_id' => 1], 2000, 1000);
    }

    public function testRejectsExcessiveTimeRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GraphQuery::fromRequest('device', 'device_poller_perf', ['device_id' => 1], 1000, 1000 + 63244801);
    }

    public function testAllowsTwoYearGraphRange(): void
    {
        $query = GraphQuery::fromRequest('device', 'device_poller_perf', ['device_id' => 1], 1000, 1000 + 63072000);

        $this->assertSame(52560, $query->step);
    }

    public function testPreservesScopeEntitiesAndOptions(): void
    {
        LibrenmsConfig::set('rrd.step', 300);

        $query = GraphQuery::fromRequest(
            'port',
            'port_bits',
            ['device_id' => 1, 'port_id' => 2],
            1000,
            4600,
            1200,
            400,
            ['previous' => true],
        );

        $this->assertSame('port', $query->scope);
        $this->assertSame('port_bits', $query->graphType);
        $this->assertSame(['device_id' => 1, 'port_id' => 2], $query->entities);
        $this->assertSame(['previous' => true], $query->options);
        $this->assertSame(300, $query->step);
    }

    public function testUsesConfiguredRrdStepAsMinimumStep(): void
    {
        LibrenmsConfig::set('rrd.step', 60);

        $query = GraphQuery::fromRequest('port', 'port_bits', ['device_id' => 1, 'port_id' => 2], 1000, 4600, 1200);

        $this->assertSame(60, $query->step);

        LibrenmsConfig::set('rrd.step', 300);
    }

    public function testUsesConfiguredPingStepForIcmpGraph(): void
    {
        LibrenmsConfig::set('ping_rrd_step', 60);

        $query = GraphQuery::fromRequest('device', 'device_icmp_perf', ['device_id' => 1], 1000, 4600, 1200);

        $this->assertSame(60, $query->step);

        LibrenmsConfig::set('ping_rrd_step', 300);
    }
}
