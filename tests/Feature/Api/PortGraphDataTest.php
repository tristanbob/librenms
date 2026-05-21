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
 * @copyright  2024 LibreNMS Contributors
 */

namespace LibreNMS\Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\DataProvider;
use LibreNMS\Graph\GraphDataResult;
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

        // Bind a stub DataProvider that returns a realistic port_bits result without hitting rrdtool.
        $this->app->bind(DataProvider::class, function () {
            return new class implements DataProvider {
                public function query(GraphQuery $query, array $device): GraphDataResult
                {
                    if (! in_array($query->graphType, ['port_bits'], true)) {
                        throw new \RuntimeException("Graph type '{$query->graphType}' is not yet supported by the JSON graph data API.");
                    }

                    $result = new GraphDataResult(
                        id:       'port_bits:' . $query->entities['port_id'],
                        type:     $query->graphType,
                        title:    'Traffic',
                        subtitle: 'test-device eth0',
                        unit:     'bps',
                        from:     $query->from,
                        to:       $query->to,
                        step:     $query->step,
                    );
                    $result->setSource('rrd');

                    $seriesIn  = new GraphSeries(name: 'In',  key: 'bits_in',  unit: 'bps', area: true,  stack: null, color: '006600');
                    $seriesOut = new GraphSeries(name: 'Out', key: 'bits_out', unit: 'bps', area: true,  stack: null, color: '000099');
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
}
