<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\RrdGraphDataProvider;
use LibreNMS\Tests\TestCase;

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
            public function __construct() {}

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
}
