<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;
use LibreNMS\Graph\VictoriaMetricsExpressionBinding;
use LibreNMS\Graph\VictoriaMetricsMetricBinding;
use LibreNMS\Tests\TestCase;

final class VictoriaMetricsGraphDataProviderTest extends TestCase
{
    // ── parseQueryRangeResponse ──────────────────────────────────────────────

    public function testParsesMatrixResponseToDataPoints(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    [
                        'metric' => ['__name__' => 'librenms_device_poller_duration_seconds', 'device_id' => '1'],
                        'values' => [[1000, '1.5'], [1300, '2.0']],
                    ],
                ],
            ],
        ]);

        $points = VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body);

        $this->assertSame([[1000000, 1.5], [1300000, 2.0]], $points);
    }

    public function testReturnsEmptyArrayForEmptyResultSet(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => ['resultType' => 'matrix', 'result' => []],
        ]);

        $points = VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body);

        $this->assertSame([], $points);
    }

    public function testThrowsOnFailureStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseQueryRangeResponse(
            json_encode(['status' => 'error', 'error' => 'bad query'])
        );
    }

    public function testThrowsOnUnexpectedResultType(): void
    {
        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseQueryRangeResponse(
            json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])
        );
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseQueryRangeResponse('not json');
    }

    public function testNonNumericValueBecomesNull(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [[
                    'metric' => [],
                    'values' => [[1000, 'NaN'], [1300, '3.0']],
                ]],
            ],
        ]);

        $points = VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body);

        $this->assertNull($points[0][1]);
        $this->assertSame(3.0, $points[1][1]);
    }

    public function testThrowsWhenMatrixResponseHasMultipleSeries(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    ['metric' => ['instance' => 'a'], 'values' => [[1000, '1']]],
                    ['metric' => ['instance' => 'b'], 'values' => [[1000, '2']]],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body, 'my_metric');
    }

    // ── buildExpr ───────────────────────────────────────────────────────────

    public function testBuildExprWithSingleLabel(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['device_id']);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['device_id' => '42']);

        $this->assertSame('my_metric{device_id="42"}', $expr);
    }

    public function testBuildExprWithMultipleLabels(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['device_id', 'port_id']);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['device_id' => '1', 'port_id' => '2']);

        $this->assertSame('my_metric{device_id="1",port_id="2"}', $expr);
    }

    public function testBuildExprThrowsOnMissingEntityKeys(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['device_id', 'port_id']);

        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::buildExpr($binding, ['device_id' => '1']);
    }

    public function testBuildExprWithNoLabels(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', []);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, []);

        $this->assertSame('my_metric', $expr);
    }

    public function testBuildExprEscapesSpecialCharacters(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['hostname']);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['hostname' => 'host"with\\slash']);

        $this->assertSame('my_metric{hostname="host\\"with\\\\slash"}', $expr);
    }

    public function testBuildExprFromExpressionBinding(): void
    {
        $binding = new VictoriaMetricsExpressionBinding(
            fn (array $entities): string => 'rate(my_metric{device_id="' . $entities['device_id'] . '"}[5m])',
            ['device_id'],
        );

        $expr = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['device_id' => '42']);

        $this->assertSame('rate(my_metric{device_id="42"}[5m])', $expr);
    }
}
