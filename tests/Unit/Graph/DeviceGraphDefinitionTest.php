<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Facades\LibrenmsConfig;
use LibreNMS\Graph\Definitions\Device\DeviceGraphCatalog;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Tests\TestCase;

final class DeviceGraphDefinitionTest extends TestCase
{
    public function testCatalogSupportsAllRemainingDevDeviceGraphs(): void
    {
        $types = array_map(fn ($definition) => $definition->graphType(), DeviceGraphCatalog::definitions());

        $this->assertContains('device_availability', $types);
        $this->assertContains('device_netstat_tcp', $types);
        $this->assertContains('device_ipsystemstats_ipv4_frag', $types);
        $this->assertContains('device_ucd_cpu', $types);
        $this->assertContains('device_uptime', $types);
    }

    public function testAvailabilityUsesRequestedDurationForRrdBinding(): void
    {
        $graph = $this->definition('device_availability');
        $query = new GraphQuery('device', 'device_availability', 1000000, 1003600, 1200, 300, ['device_id' => 1], ['duration' => 172800]);
        $series = $graph->series($this->device(), $query);
        $binding = $series[0]->binding(RrdMetricBinding::SOURCE);

        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
        $this->assertSame(['availability', 172800], $binding->rrdName);
    }

    public function testSimpleStatsAddsRollupsByTimeRange(): void
    {
        $graph = $this->definition('device_hr_processes');

        $short = $graph->series($this->device(), $this->query('device_hr_processes', 86400));
        $this->assertCount(2, $short);
        $this->assertSame('1 hour avg', $short[1]->name);

        $long = $graph->series($this->device(), $this->query('device_hr_processes', 700000));
        $this->assertSame(['Processes', '1 hour avg', '1 day avg', '1 week avg'], array_map(fn ($s) => $s->name, $long));
    }

    public function testUptimeMungesSecondsToDays(): void
    {
        $series = $this->definition('device_uptime')->series($this->device(), $this->query('device_uptime'))[0];
        $binding = $series->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);

        $this->assertSame('uptime', $binding->rrdName);
        $this->assertSame('uptime', $binding->ds);
        $this->assertEquals(2.0, ($binding->transform)(172800));
    }

    public function testStackedConfigControlsInvertedSeries(): void
    {
        LibrenmsConfig::set('webui.graph_stacked', false);
        $tcp = $this->definition('device_netstat_tcp')->series($this->device(), $this->query('device_netstat_tcp'));
        $this->assertTrue($tcp[1]->negate);

        LibrenmsConfig::set('webui.graph_stacked', true);
        $tcp = $this->definition('device_netstat_tcp')->series($this->device(), $this->query('device_netstat_tcp'));
        $this->assertFalse($tcp[1]->negate);
    }

    public function testUcdCpuDefinesStackedPercentSeries(): void
    {
        $series = $this->definition('device_ucd_cpu')->series($this->device(), $this->query('device_ucd_cpu'));

        $this->assertSame(['user', 'nice', 'system', 'idle'], array_map(fn ($s) => $s->name, $series));
        $this->assertSame(['c02020', '008f00', 'ea8f00', '000077'], array_map(fn ($s) => $s->color, $series));
        $this->assertSame('ucd_cpu', $series[0]->stack);

        $binding = $series[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
        $this->assertSame(25.0, ($binding->transform)(['user' => 25, 'nice' => 25, 'system' => 25, 'idle' => 25]));
    }

    public function testIpFragmentGraphUsesPercentTransformAndNegation(): void
    {
        $series = $this->definition('device_ipsystemstats_ipv4_frag')->series($this->device(), $this->query('device_ipsystemstats_ipv4_frag'));
        $binding = $series[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);

        $this->assertTrue($series[0]->negate);
        $this->assertSame(5.0, ($binding->transform)(['OutFragFails' => 5, 'InDelivers' => 100]));
        $this->assertNull(($binding->transform)(['OutFragFails' => 5, 'InDelivers' => 0]));
    }

    private function definition(string $type): \LibreNMS\Graph\GraphDefinition
    {
        foreach (DeviceGraphCatalog::definitions() as $definition) {
            if ($definition->graphType() === $type) {
                return $definition;
            }
        }

        throw new \RuntimeException("Missing definition $type");
    }

    private function query(string $type, int $range = 86400): GraphQuery
    {
        return new GraphQuery('device', $type, 1000000, 1000000 + $range, 1200, 300, ['device_id' => 1, 'hostname' => 'router1']);
    }

    private function device(): array
    {
        return ['device_id' => 1, 'hostname' => 'router1'];
    }
}
