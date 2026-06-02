<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Data\Store\Rrd;
use LibreNMS\Exceptions\RrdException;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\RrdGraphDataProvider;
use LibreNMS\RRD\RrdProcess;
use LibreNMS\Tests\TestCase;
use Mockery;

final class RrdGraphDataProviderTest extends TestCase
{
    public function testParsesRrdFetchOutput(): void
    {
        $output = "INOCTETS OUTOCTETS\n\n1000: 1.000000e+00 NaN\n1300: 2.500000e+00 3.000000e+00\n";

        $parsed = RrdGraphDataProvider::parseRrdFetchOutput($output);

        $this->assertSame([[1000000, 1.0], [1300000, 2.5]], $parsed['INOCTETS']);
        $this->assertSame([[1000000, null], [1300000, 3.0]], $parsed['OUTOCTETS']);
    }

    public function testTreatsAllNonFiniteRrdValuesAsGaps(): void
    {
        // rrdtool emits platform-dependent unknown markers; none may become 0.0.
        $output = "DS\n\n1000: NaN\n1300: -nan\n1600: nan\n1900: inf\n2200: -inf\n2500: 4.200000e+01\n";

        $parsed = RrdGraphDataProvider::parseRrdFetchOutput($output);

        $this->assertSame(
            [[1000000, null], [1300000, null], [1600000, null], [1900000, null], [2200000, null], [2500000, 42.0]],
            $parsed['DS'],
        );
    }

    public function testCalculatedMultiDatasourceBindingsDoNotMutateReadonlyBinding(): void
    {
        $binding = new \LibreNMS\Graph\RrdMetricBinding(
            rrdName: 'mempool',
            ds: ['used', 'free'],
            transform: fn (array $values) => $values['used'] / ($values['used'] + $values['free']) * 100,
        );

        $provider = new class extends RrdGraphDataProvider {
            public function __construct()
            {
            }

            public function expose(array $allData, \LibreNMS\Graph\RrdMetricBinding $binding): array
            {
                $method = new \ReflectionMethod(RrdGraphDataProvider::class, 'pointsForBinding');

                return $method->invoke($this, $allData, $binding);
            }
        };

        $points = $provider->expose([
            'used' => [[1000000, 25.0], [1300000, 40.0]],
            'free' => [[1000000, 75.0], [1300000, 60.0]],
        ], $binding);

        $this->assertSame([[1000000, 25.0], [1300000, 40.0]], $points);
    }

    public function testExecuteRrdFetchUsesPersistentRrdProcessWithQuotedCommand(): void
    {
        $rrd = Mockery::mock(Rrd::class);
        $rrdProcess = Mockery::mock(RrdProcess::class);
        $rrdProcess->shouldReceive('run')
            ->once()
            ->with('"fetch" "router one/poller-perf.rrd" "AVERAGE" "--start" "1000"')
            ->andReturn("poller\n\n1000: 1.000000e+00\n");

        $provider = new RrdGraphDataProvider($rrd, $rrdProcess, new GraphDefinitionRegistry());
        $method = new \ReflectionMethod(RrdGraphDataProvider::class, 'executeRrdFetch');

        $this->assertSame(
            "poller\n\n1000: 1.000000e+00\n",
            $method->invoke($provider, ['fetch', 'router one/poller-perf.rrd', 'AVERAGE', '--start', '1000']),
        );
    }

    public function testExecuteRrdFetchConvertsRrdProcessFailureToRuntimeException(): void
    {
        $rrd = Mockery::mock(Rrd::class);
        $rrdProcess = Mockery::mock(RrdProcess::class);
        $rrdProcess->shouldReceive('run')
            ->once()
            ->andThrow(new RrdException('No such file or directory'));

        $provider = new RrdGraphDataProvider($rrd, $rrdProcess, new GraphDefinitionRegistry());
        $method = new \ReflectionMethod(RrdGraphDataProvider::class, 'executeRrdFetch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rrdtool fetch failed: No such file or directory');

        $method->invoke($provider, ['fetch', 'missing.rrd', 'AVERAGE']);
    }

    public function testFetchRrdDataMemoizesIdenticalFetches(): void
    {
        $query = new GraphQuery('device', 'device_poller_perf', 1000, 1600, 1200, 300, ['device_id' => 1], step: 300);
        $command = ['fetch', 'router1/poller-perf.rrd', 'AVERAGE', '--start', '1000', '--end', '1600', '--resolution', '300'];

        $rrd = Mockery::mock(Rrd::class);
        $rrd->shouldReceive('buildCommand')
            ->once()
            ->with('fetch', '/opt/librenms/rrd/router1/poller-perf.rrd', [
                'AVERAGE',
                '--start', '1000',
                '--end', '1600',
                '--resolution', '300',
            ])
            ->andReturn($command);

        $rrdProcess = Mockery::mock(RrdProcess::class);
        $rrdProcess->shouldReceive('run')
            ->once()
            ->with('"fetch" "router1/poller-perf.rrd" "AVERAGE" "--start" "1000" "--end" "1600" "--resolution" "300"')
            ->andReturn("poller\n\n1000: 1.000000e+00\n1300: 2.000000e+00\n");

        $provider = new RrdGraphDataProvider($rrd, $rrdProcess, new GraphDefinitionRegistry());
        $method = new \ReflectionMethod(RrdGraphDataProvider::class, 'fetchRrdData');

        $first = $method->invoke($provider, '/opt/librenms/rrd/router1/poller-perf.rrd', $query, 'AVERAGE');
        $second = $method->invoke($provider, '/opt/librenms/rrd/router1/poller-perf.rrd', $query, 'AVERAGE');

        $this->assertSame($first, $second);
        $this->assertSame([[1000000, 1.0], [1300000, 2.0]], $first['poller']);
    }
}
