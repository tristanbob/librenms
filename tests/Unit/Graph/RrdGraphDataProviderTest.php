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
}
