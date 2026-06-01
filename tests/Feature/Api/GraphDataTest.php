<?php

/**
 * GraphDataTest.php
 *
 * Tests for the JSON graph data API endpoint (api/v1).
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

namespace LibreNMS\Tests\Feature\Api;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use LibreNMS\Tests\DBTestCase;

class GraphDataTest extends DBTestCase
{
    use DatabaseTransactions;

    private User   $adminUser;
    private Device $device;
    private string $rrdDir;
    private string $rrdtool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->adminUser = User::factory()->admin()->create();
        $this->device = Device::factory()->create();
        $this->fakeRrdtool();
    }

    protected function tearDown(): void
    {
        if (isset($this->rrdtool) && is_file($this->rrdtool)) {
            unlink($this->rrdtool);
        }

        if (isset($this->rrdDir) && is_dir($this->rrdDir . '/' . $this->device->hostname)) {
            unlink($this->rrdDir . '/' . $this->device->hostname . '/poller-perf.rrd');
            rmdir($this->rrdDir . '/' . $this->device->hostname);
            rmdir($this->rrdDir);
        }

        parent::tearDown();
    }

    public function testGraphDataEndpointReturnsJson(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->getJson("/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data")
            ->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'graph' => [
                    'id', 'type', 'title', 'subtitle', 'unit',
                    'from', 'to', 'step',
                    'display' => ['renderer', 'kind', 'stacked', 'area', 'legend', 'tooltip'],
                    'series',
                    'meta' => ['source', 'fallback_used', 'empty_reason', 'warnings', 'generated_at'],
                ],
            ])
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'type' => 'device_poller_perf',
                    'meta' => ['source' => 'rrd', 'fallback_used' => false],
                ],
            ]);
    }

    public function testGraphDataEndpointReturnsRrdData(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(
            "/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from=1000&to=1600&width=2"
        )->assertStatus(200);

        $response->assertJsonPath('graph.series.0.key', 'poller_time')
            ->assertJsonPath('graph.series.0.data.0', [1000000, 1.25])
            ->assertJsonPath('graph.series.0.data.1', [1300000, 2.5])
            ->assertJsonPath('graph.series.0.stats.min', 1.25)
            ->assertJsonPath('graph.series.0.stats.max', 2.5)
            ->assertJsonPath('graph.series.0.stats.avg', 1.875)
            ->assertJsonPath('graph.meta.empty_reason', null);
    }

    public function testGraphDataEndpointRequiresAuth(): void
    {
        $this->getJson("/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data")
            ->assertStatus(401);
    }

    public function testGraphDataEndpointReturnsErrorForUnsupportedType(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->getJson("/api/v1/devices/{$this->device->hostname}/graphs/nonexistent_graph/data")
            ->assertStatus(404)
            ->assertJson(['status' => 'error']);
    }

    public function testGraphDataEndpointReturnsValidationErrorForInvalidQuery(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->getJson("/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from=2000&to=1000")
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);
    }

    public function testGraphDataEndpointHonorsDevicePolicy(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data")
            ->assertStatus(403);
    }

    public function testGraphDataRespectsTimeRange(): void
    {
        Sanctum::actingAs($this->adminUser);

        $from = time() - 3600;
        $to = time();

        $response = $this->getJson(
            "/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from={$from}&to={$to}"
        )->assertStatus(200)->assertJson(['status' => 'ok']);

        $data = $response->json();
        $this->assertEquals($from, $data['graph']['from']);
        $this->assertEquals($to, $data['graph']['to']);
    }

    public function testGraphDataSetsCacheControlForPastTimeRange(): void
    {
        Sanctum::actingAs($this->adminUser);

        $from = time() - 7200;
        $to = time() - 3600;

        $response = $this->getJson(
            "/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from={$from}&to={$to}"
        )->assertStatus(200);

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertEquals('300', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testGraphDataSetsCacheControlNoStoreForLiveTimeRange(): void
    {
        Sanctum::actingAs($this->adminUser);

        $from = time() - 3600;
        $to = time();

        $response = $this->getJson(
            "/api/v1/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from={$from}&to={$to}"
        )->assertStatus(200);

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function fakeRrdtool(): void
    {
        $this->rrdDir = sys_get_temp_dir() . '/librenms-rrd-' . uniqid('', true);
        $this->rrdtool = sys_get_temp_dir() . '/librenms-rrdtool-' . uniqid('', true);

        mkdir($this->rrdDir . '/' . $this->device->hostname, 0777, true);
        file_put_contents($this->rrdDir . '/' . $this->device->hostname . '/poller-perf.rrd', '');
        file_put_contents($this->rrdtool, "#!/bin/sh\nprintf 'poller\\n\\n1000: 1.250000e+00\\n1300: 2.500000e+00\\n'\n");
        chmod($this->rrdtool, 0755);

        LibrenmsConfig::set('rrd_dir', $this->rrdDir);
        LibrenmsConfig::set('rrdtool', $this->rrdtool);
        LibrenmsConfig::set('rrdcached', '');
    }
}
