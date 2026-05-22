<?php

namespace LibreNMS\Tests\Unit\VictoriaMetrics;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LibreNMS\Data\Store\VictoriaMetrics;
use LibreNMS\Tests\TestCase;

final class VictoriaMetricsDatastoreTest extends TestCase
{
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        LibrenmsConfig::set('victoriametrics.enable', true);
        LibrenmsConfig::set('victoriametrics.write_url', 'http://vmagent-fake:8429/api/v1/import/prometheus');
        LibrenmsConfig::set('victoriametrics.batch_size', 500);
        LibrenmsConfig::set('victoriametrics.timeout', 2);
        LibrenmsConfig::set('victoriametrics.verify_ssl', true);
        LibrenmsConfig::set('victoriametrics.debug', false);

        $this->device = new Device();
        $this->device->device_id = 1;
        $this->device->hostname = 'test.host';
    }

    public function testIsEnabledReturnsFalseByDefault(): void
    {
        LibrenmsConfig::set('victoriametrics.enable', false);

        $this->assertFalse(VictoriaMetrics::isEnabled());
    }

    public function testIsEnabledReturnsTrueWhenConfigSet(): void
    {
        LibrenmsConfig::set('victoriametrics.enable', true);

        $this->assertTrue(VictoriaMetrics::isEnabled());
    }

    public function testGetNameReturnsVictoriaMetrics(): void
    {
        $this->assertSame('VictoriaMetrics', (new VictoriaMetrics())->getName());
    }

    public function testNonFiniteAndUnknownValuesAreSkipped(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('ports', [
            'INOCTETS' => NAN,
            'OUTOCTETS' => INF,
            'ifAlias' => 1.0,
        ], [], ['device' => $this->device]);
        $store->terminate();

        Http::assertNothingSent();
    }

    public function testPrometheusTextFormatIsPosted(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('poller-perf', ['poller' => 1.5], ['module' => 'ALL'], ['device' => $this->device]);
        $store->terminate();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = $request->body();
            $this->assertStringContainsString("# TYPE librenms_device_poller_duration_seconds gauge\n", $body);
            $this->assertStringContainsString('librenms_device_poller_duration_seconds{', $body);
            $this->assertStringContainsString('device_id="1"', $body);
            $this->assertStringContainsString('hostname="test.host"', $body);
            $this->assertStringContainsString('entity_type="device"', $body);
            $this->assertStringContainsString('module="ALL"', $body);
            $this->assertMatchesRegularExpression('/ 1\.5 \d{13}\n$/', $body);

            return true;
        });
    }

    public function testPortLabelsIncludePortId(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('ports', ['INOCTETS' => 1000.0], ['port_id' => 7, 'ifName' => 'Gi0/0'], ['device' => $this->device]);
        $store->terminate();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = $request->body();
            $this->assertStringContainsString("# TYPE librenms_port_if_in_octets_total counter\n", $body);
            $this->assertStringContainsString('entity_type="port"', $body);
            $this->assertStringContainsString('port_id="7"', $body);
            $this->assertStringContainsString('ifName="Gi0/0"', $body);

            return true;
        });
    }

    public function testBatchFlushesAtBatchSize(): void
    {
        LibrenmsConfig::set('victoriametrics.batch_size', 3);
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('ports', [
            'INOCTETS' => 1.0,
            'OUTOCTETS' => 2.0,
            'INERRORS' => 3.0,
        ], ['port_id' => 7], ['device' => $this->device]);

        Http::assertSentCount(1);
    }

    public function testTerminateFlushesPartialBatch(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('poller-perf', ['poller' => 0.42], [], ['device' => $this->device]);
        Http::assertNothingSent();

        $store->terminate();
        Http::assertSentCount(1);
    }

    public function testWriteFailureIsLoggedAndDoesNotThrow(): void
    {
        Http::fake(['*' => Http::response('Bad Request', 400)]);
        $store = new VictoriaMetrics();

        \Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::on(fn ($msg) => str_contains($msg, 'HTTP 400')));

        $store->write('ports', ['INOCTETS' => 42.0], ['port_id' => 7], ['device' => $this->device]);
        $store->terminate();
    }

    public function testConnectionExceptionTemporarilyDisablesWrites(): void
    {
        Http::fake(fn () => throw new ConnectionException('no route'));
        $store = new VictoriaMetrics();

        \Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::on(fn ($msg) => str_contains($msg, 'temporarily disabling')));

        $store->write('ports', ['INOCTETS' => 42.0], ['port_id' => 7], ['device' => $this->device]);
        $store->terminate();
        $store->write('ports', ['OUTOCTETS' => 43.0], ['port_id' => 7], ['device' => $this->device]);
        $store->terminate();
    }

    public function testPostUrlUsesConfiguredWriteUrl(): void
    {
        LibrenmsConfig::set('victoriametrics.write_url', 'http://victoriametrics:8428/api/v1/import/prometheus');
        Http::fake(['*' => Http::response('', 204)]);
        $store = new VictoriaMetrics();

        $store->write('ports', ['INOCTETS' => 1.0], ['port_id' => 7], ['device' => $this->device]);
        $store->terminate();

        Http::assertSent(fn ($request) => $request->url() === 'http://victoriametrics:8428/api/v1/import/prometheus');
    }
}
