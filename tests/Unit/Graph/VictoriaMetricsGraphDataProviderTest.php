<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\VictoriaMetricsGraphDataProvider;
use LibreNMS\Graph\VictoriaMetricsBatchBinding;
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

    public function testSelectsMostRecentlyActiveSeriesWhenMatrixHasMultipleSeries(): void
    {
        // Series 'b' has the newest non-null sample (1300 > 1000), so it wins.
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    ['metric' => ['ifIndex' => '2'], 'values' => [[1000, '1']]],
                    ['metric' => ['ifIndex' => '7'], 'values' => [[1000, '2'], [1300, '5']]],
                ],
            ],
        ]);

        $points = VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body, 'my_metric');

        $this->assertSame([[1000_000, 2.0], [1300_000, 5.0]], $points);
    }

    public function testMultiSeriesTieBreaksOnLexicographicallySmallestLabelSet(): void
    {
        // Both newest at 1000; deterministic tie-break picks the smaller JSON-encoded label set.
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    ['metric' => ['ifIndex' => '9'], 'values' => [[1000, '9']]],
                    ['metric' => ['ifIndex' => '1'], 'values' => [[1000, '1']]],
                ],
            ],
        ]);

        $points = VictoriaMetricsGraphDataProvider::parseQueryRangeResponse($body, 'my_metric');

        $this->assertSame([[1000_000, 1.0]], $points);
    }

    // ── buildExpr ───────────────────────────────────────────────────────────

    public function testBuildExprWithSingleLabel(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['hostname']);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['hostname' => 'router1']);

        $this->assertSame('my_metric{hostname="router1"}', $expr);
    }

    public function testBuildExprWithMultipleLabels(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['hostname', 'ifIndex']);
        $expr    = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['hostname' => 'router1', 'ifIndex' => '2']);

        $this->assertSame('my_metric{hostname="router1",ifIndex="2"}', $expr);
    }

    public function testBuildExprThrowsOnMissingEntityKeys(): void
    {
        $binding = new VictoriaMetricsMetricBinding('my_metric', ['hostname', 'ifIndex']);

        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::buildExpr($binding, ['hostname' => 'router1']);
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
            fn (array $entities): string => 'rate(my_metric{hostname="' . $entities['hostname'] . '"}[5m])',
            ['hostname'],
        );

        $expr = VictoriaMetricsGraphDataProvider::buildExpr($binding, ['hostname' => 'router1']);

        $this->assertSame('rate(my_metric{hostname="router1"}[5m])', $expr);
    }

    // ── parseBatchQueryRangeResponse ─────────────────────────────────────────

    public function testParseBatchResponseReturnsMultipleSeries(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [
                    [
                        'metric' => ['ifIndex' => '1'],
                        'values' => [[1000, '10.0'], [1300, '20.0']],
                    ],
                    [
                        'metric' => ['ifIndex' => '2'],
                        'values' => [[1000, '30.0']],
                    ],
                ],
            ],
        ]);

        $results = VictoriaMetricsGraphDataProvider::parseBatchQueryRangeResponse($body);

        $this->assertCount(2, $results);
        $this->assertSame(['ifIndex' => '1'], $results[0]['metric']);
        $this->assertSame([[1000000, 10.0], [1300000, 20.0]], $results[0]['points']);
        $this->assertSame(['ifIndex' => '2'], $results[1]['metric']);
        $this->assertSame([[1000000, 30.0]], $results[1]['points']);
    }

    public function testParseBatchResponseReturnsEmptyForNoResults(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => ['resultType' => 'matrix', 'result' => []],
        ]);

        $this->assertSame([], VictoriaMetricsGraphDataProvider::parseBatchQueryRangeResponse($body));
    }

    public function testParseBatchResponseThrowsOnFailureStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseBatchQueryRangeResponse(
            json_encode(['status' => 'error', 'error' => 'bad query'])
        );
    }

    public function testParseBatchResponseThrowsOnUnexpectedResultType(): void
    {
        $this->expectException(\RuntimeException::class);
        VictoriaMetricsGraphDataProvider::parseBatchQueryRangeResponse(
            json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])
        );
    }

    public function testParseBatchResponseHandlesNonNumericAsNull(): void
    {
        $body = json_encode([
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [[
                    'metric' => ['ifIndex' => '1'],
                    'values' => [[1000, 'NaN'], [1300, '5.0']],
                ]],
            ],
        ]);

        $results = VictoriaMetricsGraphDataProvider::parseBatchQueryRangeResponse($body);

        $this->assertNull($results[0]['points'][0][1]);
        $this->assertSame(5.0, $results[0]['points'][1][1]);
    }

    // ── VictoriaMetricsBatchBinding ──────────────────────────────────────────

    public function testBatchBindingBuildsExprFromEntities(): void
    {
        $binding = new VictoriaMetricsBatchBinding(
            batchExprBuilder: fn (array $e) => 'my_metric{hostname="' . $e['hostname'] . '"}',
            demuxValues: ['ifIndex' => '2'],
            labelKeys: ['hostname'],
        );

        $this->assertSame('my_metric{hostname="router1"}', $binding->batchExpr(['hostname' => 'router1']));
        $this->assertSame(VictoriaMetricsMetricBinding::SOURCE, $binding->source());
        $this->assertSame(['ifIndex' => '2'], $binding->demuxValues);
    }

    // ── MetricSeries::aggregate() ────────────────────────────────────────────

    public function testAggregateProducesBatchBindingWithHostnameOnlyBatchExpr(): void
    {
        $rrd     = new \LibreNMS\Graph\RrdMetricBinding('port-id1', 'INOCTETS', transform: fn ($v) => $v * 8);
        $result  = \LibreNMS\Graph\MetricSeries::aggregate('port.if_in_bits_rate', $rrd, ['ifIndex' => '3']);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(\LibreNMS\Graph\RrdMetricBinding::class, $result[0]);
        $this->assertInstanceOf(VictoriaMetricsBatchBinding::class, $result[1]);

        /** @var VictoriaMetricsBatchBinding $batch */
        $batch = $result[1];
        $this->assertSame(['ifIndex' => '3'], $batch->demuxValues);

        $expr = $batch->batchExpr(['hostname' => 'router1']);
        $this->assertSame('librenms_port_if_in_bits_per_second{hostname="router1"}', $expr);
        $this->assertStringNotContainsString('ifIndex', $expr);
    }

    public function testAggregateAppliesRateForCounterMetric(): void
    {
        $rrd    = new \LibreNMS\Graph\RrdMetricBinding(['ucd_diskio', 'sda'], 'reads');
        $result = \LibreNMS\Graph\MetricSeries::aggregate('diskio.reads', $rrd, ['descr' => 'sda']);

        /** @var VictoriaMetricsBatchBinding $batch */
        $batch = $result[1];
        $expr  = $batch->batchExpr(['hostname' => 'router1']);

        $this->assertStringStartsWith('rate(', $expr);
        $this->assertStringContainsString('librenms_diskio_reads_total', $expr);
        $this->assertStringNotContainsString('descr', $expr);
    }

    public function testAggregateMultiLabelDemuxOmitsAllDemuxLabelsFromBatchExpr(): void
    {
        $rrd    = new \LibreNMS\Graph\RrdMetricBinding(['processor', 'intel', '0'], 'usage');
        $result = \LibreNMS\Graph\MetricSeries::aggregate(
            'processor.usage',
            $rrd,
            ['processor_type' => 'intel', 'processor_index' => '0'],
        );

        /** @var VictoriaMetricsBatchBinding $batch */
        $batch = $result[1];
        $this->assertSame(['processor_type' => 'intel', 'processor_index' => '0'], $batch->demuxValues);

        $expr = $batch->batchExpr(['hostname' => 'router1']);
        $this->assertStringNotContainsString('processor_type', $expr);
        $this->assertStringNotContainsString('processor_index', $expr);
        $this->assertStringContainsString('hostname="router1"', $expr);
    }
}
