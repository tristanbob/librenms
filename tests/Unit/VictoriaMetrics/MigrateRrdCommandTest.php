<?php

/**
 * MigrateRrdCommandTest.php
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

use App\Console\Commands\VictoriaMetrics\MigrateRrd;
use App\Facades\Rrd;
use App\Models\Device;
use App\Models\Port;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LibreNMS\Tests\InMemoryDbTestCase;

/**
 * Test subclass that:
 *  - Replaces rrdtool Process calls with controlled fake data keyed by rrdFile path
 *  - Reports a file as "existing" iff it has an entry in fakeData
 */
class FakeMigrateRrd extends MigrateRrd
{
    /** @var array<string, array<string, list<array{int, float|null}>>> */
    public array $fakeData = [];

    protected function rrdFileExists(string $path): bool
    {
        return isset($this->fakeData[$path]);
    }

    protected function fetchRrd(string $rrdFile): array
    {
        return $this->fakeData[$rrdFile] ?? [];
    }
}

final class MigrateRrdCommandTest extends InMemoryDbTestCase
{
    private const VM_URL = 'http://vm.test:8428/api/v1/import/prometheus';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ── dry-run ───────────────────────────────────────────────────────────────

    public function testDryRunNeverPosts(): void
    {
        $device = $this->makeDevice();
        $this->registerFakeCommand($device->hostname, [], []);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'  => $device->hostname,
            '--dry-run' => true,
            '--url'     => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── no matching devices ───────────────────────────────────────────────────

    public function testNoDevicesExitsCleanly(): void
    {
        $this->artisan('victoriametrics:migrate-rrd', [
            '--device' => 'nonexistent-host-xyz-12345',
            '--url'    => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── missing RRD file is skipped silently ──────────────────────────────────

    public function testMissingRrdFileIsSkipped(): void
    {
        $device = $this->makeDevice();
        // fakeData is empty → rrdFileExists returns false → nothing queued
        $this->registerFakeCommand($device->hostname, [], []);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device' => $device->hostname,
            '--url'    => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── gauge samples are formatted and posted ────────────────────────────────

    public function testGaugeSamplesPostedWithCorrectLabels(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => Http::response('', 204)]);

        $portData = [
            'INOCTETS'  => [[300_000, 1000.0]],  // 1000 octets/sec → 8000 bps
            'OUTOCTETS' => [[300_000, 500.0]],    // 500 octets/sec  → 4000 bps
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) use ($device, $port) {
            $body = (string) $request->body();

            return str_contains($body, 'librenms_port_if_in_bits_per_second')
                && str_contains($body, 'librenms_port_if_out_bits_per_second')
                && str_contains($body, "hostname=\"{$device->hostname}\"")
                && str_contains($body, "ifIndex=\"{$port->ifIndex}\"")
                && ! str_contains($body, 'port_id=')
                && ! str_contains($body, 'device_id=')
                && str_contains($body, '8000')   // INOCTETS × 8
                && str_contains($body, '4000');  // OUTOCTETS × 8
        });
    }

    // ── NaN/null samples are excluded ─────────────────────────────────────────

    public function testNullSamplesAreSkipped(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => Http::response('', 204)]);

        $portData = [
            'INOCTETS'  => [[300_000, null], [600_000, null]],
            'OUTOCTETS' => [],
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── batch flushing at batch-size threshold ────────────────────────────────

    public function testBatchFlushesAtBatchSize(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => Http::response('', 204)]);

        // 6 non-null INOCTETS samples → 6 queued lines
        $portData = [
            'INOCTETS'  => array_map(fn ($i) => [$i * 300_000, 100.0], range(1, 6)),
            'OUTOCTETS' => [],
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        // batch-size=3 → flush at sample 3, flush at sample 6, final flush empty → 2 POSTs
        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '3',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertSentCount(2);
    }

    // ── --counters flag enables counter synthesis ─────────────────────────────

    public function testWithoutCountersFlagNoCounterMetricsArePosted(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => Http::response('', 204)]);

        $portData = [
            'INOCTETS'   => [],
            'OUTOCTETS'  => [],
            'INERRORS'   => [[300_000, 5.0]],
            'OUTERRORS'  => [],
            'INDISCARDS' => [],
            'OUTDISCARDS'=> [],
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        // No --counters flag → no gauge data either → nothing posted
        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function testCounterFlagEnablesCounterSynthesis(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => Http::response('', 204)]);

        $portData = [
            'INOCTETS'   => [],
            'OUTOCTETS'  => [],
            'INERRORS'   => [[300_000, 5.0]],  // 5 errors/sec × 300s = 1500 cumulative
            'OUTERRORS'  => [],
            'INDISCARDS' => [],
            'OUTDISCARDS'=> [],
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--counters'   => true,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, 'librenms_port_if_in_errors_total')
                && str_contains($body, '1500');  // 5 errors/sec × 300s step
        });
    }

    // ── connection failure does not abort migration ───────────────────────────

    public function testConnectionFailureReturnsNonZeroButContinues(): void
    {
        $device = $this->makeDevice();
        $port = $this->makePort($device);

        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $portData = [
            'INOCTETS'  => [[300_000, 1000.0]],
            'OUTOCTETS' => [],
        ];

        $this->registerFakeCommand($device->hostname, [], [$port->port_id => $portData]);

        // Should exit 1 (failedBatches > 0) but not throw an exception
        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(1);
    }

    // ── poller-perf data is included ─────────────────────────────────────────

    public function testPollerPerfDataIsPosted(): void
    {
        $device = $this->makeDevice();

        Http::fake(['*' => Http::response('', 204)]);

        $pollerData = ['poller' => [[300_000, 12.5]]];

        $this->registerFakeCommand($device->hostname, $pollerData, []);

        $this->artisan('victoriametrics:migrate-rrd', [
            '--device'     => $device->hostname,
            '--batch-size' => '100',
            '--url'        => self::VM_URL,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) use ($device) {
            $body = (string) $request->body();

            return str_contains($body, 'librenms_device_poller_duration_seconds')
                && str_contains($body, "hostname=\"{$device->hostname}\"")
                && ! str_contains($body, 'device_id=')
                && str_contains($body, '12.5');
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeDevice(): Device
    {
        return Device::create([
            'hostname'        => 'test-router-' . uniqid(),
            'community'       => 'public',
            'snmpver'         => 'v2c',
            'snmp_disable'    => true,
            'status'          => true,
            'status_reason'   => '',
            'last_polled'     => now(),
            'last_discovered' => now(),
        ]);
    }

    private function makePort(Device $device): Port
    {
        return Port::create([
            'device_id'     => $device->device_id,
            'ifIndex'       => 1,
            'ifName'        => 'Gi0/0',
            'ifDescr'       => 'GigabitEthernet0/0',
            'ifOperStatus'  => 'up',
            'ifAdminStatus' => 'up',
            'deleted'       => 0,
        ]);
    }

    /**
     * Create and register a FakeMigrateRrd command in Artisan, with Rrd facade mocked.
     *
     * @param  string  $hostname  Device hostname (used to build predictable fake RRD paths)
     * @param  array<string, list<array{int, float|null}>>  $pollerData  DS data for poller-perf RRD
     * @param  array<int, array<string, list<array{int, float|null}>>>  $portDataByPortId  [port_id => [DS => samples]]
     */
    private function registerFakeCommand(string $hostname, array $pollerData, array $portDataByPortId): void
    {
        $fakeDir = '/fake-rrd';
        $allFakeData = [];

        // poller-perf
        if (! empty($pollerData)) {
            $allFakeData["{$fakeDir}/{$hostname}/poller-perf.rrd"] = $pollerData;
        }

        // ports
        foreach ($portDataByPortId as $portId => $dsData) {
            $allFakeData["{$fakeDir}/{$hostname}/port-id{$portId}.rrd"] = $dsData;
        }

        Rrd::shouldReceive('name')
            ->andReturnUsing(fn (string $host, string $extra) => "{$fakeDir}/{$host}/{$extra}.rrd");

        Rrd::shouldReceive('portName')
            ->andReturnUsing(fn (int $portId) => "port-id{$portId}");

        Rrd::shouldReceive('buildCommand')
            ->andReturn([]);

        $fakeCmd = new FakeMigrateRrd();
        $fakeCmd->fakeData = $allFakeData;

        // Register before the artisan call resolves the command.
        // Artisan::registerCommand() keyed by command name overwrites any prior binding.
        Artisan::registerCommand($fakeCmd);
    }
}
