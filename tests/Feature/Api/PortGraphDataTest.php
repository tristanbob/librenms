<?php

/**
 * PortGraphDataTest.php
 *
 * Tests for the port_bits JSON graph data API endpoint introduced in Stage 3.
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

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeries;
use LibreNMS\Tests\DBTestCase;

class PortGraphDataTest extends DBTestCase
{
    use DatabaseTransactions;

    private User     $adminUser;
    private ApiToken $adminToken;
    private Device   $device;
    private Port     $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser  = User::factory()->admin()->create();
        $this->adminToken = ApiToken::generateToken($this->adminUser);
        $this->device     = Device::factory()->create();
        $this->port       = Port::factory()->create(['device_id' => $this->device->device_id]);

        // Bind a stub GraphDataProvider that returns realistic port graph results without hitting rrdtool.
        $this->app->bind(GraphDataProvider::class, function () {
            return new class implements GraphDataProvider {
                private const GRAPH_META = [
                    'port_bits'    => ['title' => 'Traffic',  'unit' => 'bps', 'in_key' => 'bits_in',    'out_key' => 'bits_out'],
                    'port_packets' => ['title' => 'Packets',  'unit' => 'pps', 'in_key' => 'packets_in', 'out_key' => 'packets_out'],
                    'port_errors'  => ['title' => 'Errors',   'unit' => 'eps', 'in_key' => 'errors_in',  'out_key' => 'errors_out'],
                    'port_discards'=> ['title' => 'Discards', 'unit' => 'dps', 'in_key' => 'discards_in','out_key' => 'discards_out'],
                ];

                public function query(GraphQuery $query): GraphDataResult
                {
                    $meta = self::GRAPH_META[$query->graphType] ?? null;
                    if ($meta === null) {
                        throw new \RuntimeException("Graph type '{$query->graphType}' is not yet supported by the JSON graph data API.");
                    }

                    $result = new GraphDataResult(
                        id:       $query->graphType . ':' . $query->entities['port_id'],
                        type:     $query->graphType,
                        title:    $meta['title'],
                        subtitle: 'test-device eth0',
                        unit:     $meta['unit'],
                        from:     $query->from,
                        to:       $query->to,
                        step:     $query->step,
                    );
                    $result->setSource('rrd');
                    $result->setDisplay(['renderer' => 'timeseries', 'kind' => 'line', 'stacked' => false, 'area' => true, 'legend' => true, 'tooltip' => true]);

                    $seriesIn  = new GraphSeries(name: 'In',  key: $meta['in_key'],  unit: $meta['unit'], area: true, stack: null, color: '006600');
                    $seriesOut = new GraphSeries(name: 'Out', key: $meta['out_key'], unit: $meta['unit'], area: true, stack: null, color: '000099', negate: true);
                    $ts = $query->from * 1000;
                    $seriesIn->addPoint($ts, 1000.0);
                    $seriesOut->addPoint($ts, 2000.0);

                    $result->addSeries($seriesIn);
                    $result->addSeries($seriesOut);

                    return $result;
                }
            };
        });
    }

    public function testPortBitsEndpointReturnsInAndOutSeries(): void
    {
        $portId = $this->port->port_id;

        $this->json(
            'GET',
            "/api/v0/ports/{$portId}/graphs/port_bits/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'graph' => [
                'id', 'type', 'title', 'subtitle', 'unit',
                'from', 'to', 'step',
                'series',
                'meta' => ['source', 'fallback_used', 'empty_reason', 'warnings', 'generated_at'],
            ],
        ])
        ->assertJson(['status' => 'ok'])
        ->assertJson([
            'graph' => [
                'id'   => "port_bits:{$portId}",
                'type' => 'port_bits',
                'unit' => 'bps',
                'meta' => ['source' => 'rrd'],
            ],
        ]);

        $data = $this->json('GET', "/api/v0/ports/{$portId}/graphs/port_bits/data", [], ['X-Auth-Token' => $this->adminToken->token_hash])->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('bits_in',  $seriesKeys);
        $this->assertContains('bits_out', $seriesKeys);
    }

    public function testPortBitsEndpointRequiresAuth(): void
    {
        $portId = $this->port->port_id;

        $this->json('GET', "/api/v0/ports/{$portId}/graphs/port_bits/data")
             ->assertStatus(401);
    }

    public function testPortBitsEndpointReturnsErrorForUnsupportedGraphType(): void
    {
        $portId = $this->port->port_id;

        $this->json(
            'GET',
            "/api/v0/ports/{$portId}/graphs/nonexistent_type/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404)
        ->assertJson(['status' => 'error']);
    }

    public function testPortBitsEndpointReturnsErrorForNonExistentPort(): void
    {
        $this->json(
            'GET',
            '/api/v0/ports/999999999/graphs/port_bits/data',
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        )
        ->assertStatus(404);
    }

    public function testPortPacketsEndpointReturnsInAndOutSeries(): void
    {
        $portId = $this->port->port_id;

        $response = $this->json(
            'GET',
            "/api/v0/ports/{$portId}/graphs/port_packets/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "port_packets:{$portId}",
                    'type' => 'port_packets',
                    'unit' => 'pps',
                ],
            ]);

        $data       = $response->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('packets_in',  $seriesKeys);
        $this->assertContains('packets_out', $seriesKeys);
    }

    public function testPortErrorsEndpointReturnsInAndOutSeries(): void
    {
        $portId = $this->port->port_id;

        $response = $this->json(
            'GET',
            "/api/v0/ports/{$portId}/graphs/port_errors/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "port_errors:{$portId}",
                    'type' => 'port_errors',
                    'unit' => 'eps',
                ],
            ]);

        $data       = $response->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('errors_in',  $seriesKeys);
        $this->assertContains('errors_out', $seriesKeys);
    }

    public function testPortDiscardsEndpointReturnsInAndOutSeries(): void
    {
        $portId = $this->port->port_id;

        $response = $this->json(
            'GET',
            "/api/v0/ports/{$portId}/graphs/port_discards/data",
            [],
            ['X-Auth-Token' => $this->adminToken->token_hash]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJson([
                'graph' => [
                    'id'   => "port_discards:{$portId}",
                    'type' => 'port_discards',
                    'unit' => 'dps',
                ],
            ]);

        $data       = $response->json();
        $seriesKeys = array_column($data['graph']['series'], 'key');
        $this->assertContains('discards_in',  $seriesKeys);
        $this->assertContains('discards_out', $seriesKeys);
    }
}
