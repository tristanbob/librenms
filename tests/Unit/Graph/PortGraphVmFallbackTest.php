<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Graph\Definitions\Port\PortGraphCatalog;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\DBTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PortGraphVmFallbackTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::factory()->create();

        LibrenmsConfig::set('victoriametrics.query_enabled', true);
    }

    #[DataProvider('portGraphTypesWithVmBindings')]
    public function testPortGraphSeriesHaveVmBindings(string $graphType): void
    {
        $registry = new GraphDefinitionRegistry();
        foreach (PortGraphCatalog::definitions() as $def) {
            $registry->register($def);
        }
        $definition = $registry->definitionFor($graphType);

        $query = GraphQuery::fromRequest(
            'port',
            $graphType,
            ['device_id' => $this->device->device_id, 'port_id' => 1, 'ifIndex' => 1],
            time() - 3600,
            time(),
        );

        $series = $definition->series(new GraphContext($this->device, $query));
        $this->assertNotEmpty($series, "$graphType must return at least one series");

        foreach ($series as $s) {
            $this->assertNotNull(
                $s->binding('victoriametrics'),
                "Series '{$s->key}' of $graphType must include a VictoriaMetrics binding"
            );
        }
    }

    public static function portGraphTypesWithVmBindings(): array
    {
        return [
            'port_bits'     => ['port_bits'],
            'port_packets'  => ['port_packets'],
            'port_discards' => ['port_discards'],
            'port_errors'   => ['port_errors'],
        ];
    }
}
