<?php

/**
 * GraphDataTest.php
 *
 * Tests for the JSON graph data API endpoint introduced in Stage 1 of graph modernization.
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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use LibreNMS\Config as LibrenmsConfig;
use LibreNMS\Tests\DBTestCase;

class GraphDataTest extends DBTestCase
{
    use DatabaseTransactions;

    private User     $adminUser;
    private ApiToken $adminToken;
    private Device   $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser  = User::factory()->admin()->create();
        $this->adminToken = ApiToken::generateToken($this->adminUser);
        $this->device     = Device::factory()->create();
    }

    public function testGraphDataEndpointReturnsJson(): void
    {
        $this->json(
            'GET',
            "/api/v0/devices/{$this->device->hostname}/graphs/device_poller_perf/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
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

    public function testGraphDataEndpointRequiresAuth(): void
    {
        $this->json('GET', "/api/v0/devices/{$this->device->hostname}/graphs/device_poller_perf/data")
             ->assertStatus(401);
    }

    public function testGraphDataEndpointReturnsErrorForUnsupportedType(): void
    {
        $this->json(
            'GET',
            "/api/v0/devices/{$this->device->hostname}/graphs/nonexistent_graph/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404)
        ->assertJson(['status' => 'error']);
    }

    public function testGraphDataRespectsTimeRange(): void
    {
        $from = time() - 3600;
        $to   = time();

        $response = $this->json(
            'GET',
            "/api/v0/devices/{$this->device->hostname}/graphs/device_poller_perf/data?from={$from}&to={$to}",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(200)
        ->assertJson(['status' => 'ok']);

        $data = $response->json();
        $this->assertEquals($from, $data['graph']['from']);
        $this->assertEquals($to,   $data['graph']['to']);
    }

    public function testGraphDataUsesVictoriaMetricsWhenEnabled(): void
    {
        LibrenmsConfig::set('victoriametrics.query_enabled', true);

        $vmResponse = [
            'status' => 'success',
            'data'   => [
                'resultType' => 'matrix',
                'result'     => [[
                    'metric' => ['device_id' => (string) $this->device->device_id],
                    'values' => [[time() - 300, '1.23'], [time(), '2.34']],
                ]],
            ],
        ];

        Http::fake(['*/api/v1/query_range*' => Http::response($vmResponse, 200)]);

        $this->json(
            'GET',
            "/api/v0/devices/{$this->device->hostname}/graphs/device_poller_perf/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(200)
        ->assertJson([
            'graph' => [
                'meta' => [
                    'source'        => 'victoriametrics',
                    'fallback_used' => false,
                ],
            ],
        ]);

        LibrenmsConfig::set('victoriametrics.query_enabled', false);
    }
}
