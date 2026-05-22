<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\Definitions\Device\PollerPerfGraph;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Tests\TestCase;

final class GraphDefinitionRegistryTest extends TestCase
{
    public function testResolvesKnownGraphType(): void
    {
        $registry = new GraphDefinitionRegistry([PollerPerfGraph::class]);

        $this->assertTrue($registry->supports('device_poller_perf'));
        $this->assertInstanceOf(PollerPerfGraph::class, $registry->definitionFor('device_poller_perf'));
    }

    public function testRejectsUnsupportedGraphType(): void
    {
        $this->expectException(\RuntimeException::class);

        (new GraphDefinitionRegistry())->definitionFor('missing_graph');
    }

    public function testRegistriesDoNotShareStaticState(): void
    {
        $withDefinition = new GraphDefinitionRegistry([PollerPerfGraph::class]);
        $empty = new GraphDefinitionRegistry();

        $this->assertTrue($withDefinition->supports('device_poller_perf'));
        $this->assertFalse($empty->supports('device_poller_perf'));
    }
}
