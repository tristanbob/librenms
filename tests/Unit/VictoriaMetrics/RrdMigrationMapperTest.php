<?php

/**
 * RrdMigrationMapperTest.php
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

use LibreNMS\Data\Store\VictoriaMetrics\RrdMigrationMapper;
use LibreNMS\Tests\TestCase;

final class RrdMigrationMapperTest extends TestCase
{
    // ── gaugeMappings ─────────────────────────────────────────────────────────

    public function testGaugeMappingsHaveExpectedKeys(): void
    {
        $mappings = RrdMigrationMapper::gaugeMappings();

        $this->assertArrayHasKey('INOCTETS', $mappings);
        $this->assertArrayHasKey('OUTOCTETS', $mappings);
    }

    public function testInOctetsMetricNameAndType(): void
    {
        [$metric] = RrdMigrationMapper::gaugeMappings()['INOCTETS'];

        $this->assertSame('librenms_port_if_in_bits_per_second', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testOutOctetsMetricNameAndType(): void
    {
        [$metric] = RrdMigrationMapper::gaugeMappings()['OUTOCTETS'];

        $this->assertSame('librenms_port_if_out_bits_per_second', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    public function testInOctetsTransformMultipliesBy8(): void
    {
        [, $transform] = RrdMigrationMapper::gaugeMappings()['INOCTETS'];

        $this->assertSame(8000.0, $transform(1000.0));
        $this->assertSame(0.0, $transform(0.0));
        $this->assertSame(800.0, $transform(100.0));
    }

    public function testOutOctetsTransformMultipliesBy8(): void
    {
        [, $transform] = RrdMigrationMapper::gaugeMappings()['OUTOCTETS'];

        $this->assertSame(80.0, $transform(10.0));
    }

    // ── counterMappings ───────────────────────────────────────────────────────

    public function testCounterMappingsHaveExpectedKeys(): void
    {
        $mappings = RrdMigrationMapper::counterMappings();

        $this->assertArrayHasKey('INERRORS', $mappings);
        $this->assertArrayHasKey('OUTERRORS', $mappings);
        $this->assertArrayHasKey('INDISCARDS', $mappings);
        $this->assertArrayHasKey('OUTDISCARDS', $mappings);
    }

    public function testCounterMetricNamesAndTypes(): void
    {
        $expected = [
            'INERRORS'   => ['librenms_port_if_in_errors_total',    'counter'],
            'OUTERRORS'  => ['librenms_port_if_out_errors_total',   'counter'],
            'INDISCARDS' => ['librenms_port_if_in_discards_total',  'counter'],
            'OUTDISCARDS'=> ['librenms_port_if_out_discards_total', 'counter'],
        ];

        foreach ($expected as $ds => [$name, $type]) {
            [$metric] = RrdMigrationMapper::counterMappings()[$ds];
            $this->assertSame($name, $metric->name, "Wrong metric name for DS {$ds}");
            $this->assertSame($type, $metric->type, "Wrong metric type for DS {$ds}");
        }
    }

    public function testCounterDeltaFormulaIsRateTimesStep(): void
    {
        [, $delta] = RrdMigrationMapper::counterMappings()['INERRORS'];

        // 5 errors/sec × 300-second step = 1500 errors in that interval
        $this->assertSame(1500.0, $delta(5.0, 300));
        $this->assertSame(0.0, $delta(0.0, 300));
        $this->assertSame(300.0, $delta(1.0, 300));
    }

    // ── pollerPerf ────────────────────────────────────────────────────────────

    public function testPollerPerfDsName(): void
    {
        $this->assertSame('poller', RrdMigrationMapper::pollerPerfDs());
    }

    public function testPollerPerfMetricDefinition(): void
    {
        $metric = RrdMigrationMapper::pollerPerfMetric();

        $this->assertSame('librenms_device_poller_duration_seconds', $metric->name);
        $this->assertSame('gauge', $metric->type);
    }

    // ── synthesizeCounter ─────────────────────────────────────────────────────

    public function testSynthesizeCounterAccumulatesCorrectly(): void
    {
        // rate 100 octets/sec × step 300 = 30,000 delta per interval
        $samples = [
            [300_000, 100.0],
            [600_000, 200.0],
            [900_000, 150.0],
        ];

        $result = RrdMigrationMapper::synthesizeCounter($samples, 300);

        $this->assertCount(3, $result);
        $this->assertSame(300_000, $result[0][0]);
        $this->assertEqualsWithDelta(30_000.0, $result[0][1], 0.001);
        $this->assertEqualsWithDelta(90_000.0, $result[1][1], 0.001);   // +60,000
        $this->assertEqualsWithDelta(135_000.0, $result[2][1], 0.001);  // +45,000
    }

    public function testSynthesizeCounterSkipsNullRates(): void
    {
        $samples = [
            [300_000, 100.0],
            [600_000, null],
            [900_000, 50.0],
        ];

        $result = RrdMigrationMapper::synthesizeCounter($samples, 300);

        // null sample is skipped; only 2 output points
        $this->assertCount(2, $result);
        $this->assertSame(300_000, $result[0][0]);
        $this->assertSame(900_000, $result[1][0]);
        // counter jumps by 50 × 300 = 15,000 from the last valid sample
        $this->assertEqualsWithDelta(30_000.0 + 15_000.0, $result[1][1], 0.001);
    }

    public function testSynthesizeCounterSkipsNegativeRates(): void
    {
        $samples = [
            [300_000,  100.0],
            [600_000, -5.0],   // negative — should be skipped
            [900_000,  50.0],
        ];

        $result = RrdMigrationMapper::synthesizeCounter($samples, 300);

        $this->assertCount(2, $result);
        // Counter should only accumulate positive contributions
        $this->assertEqualsWithDelta(30_000.0, $result[0][1], 0.001);
        $this->assertEqualsWithDelta(45_000.0, $result[1][1], 0.001);
    }

    public function testSynthesizeCounterEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], RrdMigrationMapper::synthesizeCounter([], 300));
    }

    public function testSynthesizeCounterAllNullsReturnsEmpty(): void
    {
        $samples = [[300_000, null], [600_000, null]];

        $this->assertSame([], RrdMigrationMapper::synthesizeCounter($samples, 300));
    }

    public function testSynthesizeCounterStartsAtZero(): void
    {
        $samples = [[300_000, 10.0]];

        $result = RrdMigrationMapper::synthesizeCounter($samples, 300);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(3_000.0, $result[0][1], 0.001);
    }
}
