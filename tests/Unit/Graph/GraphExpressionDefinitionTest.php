<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\Definitions\Device\DeviceStatsGraphCatalog;
use LibreNMS\Graph\GraphMarkerDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphVariableDefinition;
use LibreNMS\Graph\PercentileBinding;
use LibreNMS\Graph\RrdMetricBinding;
use LibreNMS\Tests\TestCase;

final class GraphExpressionDefinitionTest extends TestCase
{
    public function testVariableDefinitionsResolveTypedDefaultsAndBounds(): void
    {
        $duration = GraphVariableDefinition::integer('duration', 86400, 1, 604800);
        $previous = GraphVariableDefinition::boolean('previous');

        $this->assertSame(86400, $duration->resolve([]));
        $this->assertSame(1, $duration->resolve(['duration' => -100]));
        $this->assertSame(604800, $duration->resolve(['duration' => 999999]));
        $this->assertTrue($previous->resolve(['previous' => 'true']));
        $this->assertFalse($previous->resolve([]));
    }

    public function testAvailabilityDeclaresDurationVariableAndUsesItForRrdName(): void
    {
        $graph = $this->definition('device_availability');

        $variableNames = array_map(fn ($v) => $v->name, $graph->variables());
        $this->assertSame(['duration'], $variableNames);

        $query = new GraphQuery('device', 'device_availability', 1000, 2000, 1200, 300, ['device_id' => 1], ['duration' => 172800]);
        $series = $graph->series(['device_id' => 1, 'hostname' => 'router1'], $query);

        $binding = $series[0]->binding(RrdMetricBinding::SOURCE);
        $this->assertInstanceOf(RrdMetricBinding::class, $binding);
        $this->assertSame(['availability', 172800], $binding->rrdName);
    }

    public function testSimpleStatsEmitPercentileMarkers(): void
    {
        $graph = $this->definition('device_hr_processes');

        $query   = new GraphQuery('device', 'device_hr_processes', 1000, 2000, 1200, 300, ['device_id' => 1]);
        $markers = $graph->markers(['device_id' => 1, 'hostname' => 'router1'], $query);

        $this->assertCount(3, $markers);
        $this->assertContainsOnlyInstancesOf(GraphMarkerDefinition::class, $markers);

        $names = array_map(fn ($m) => $m->name, $markers);
        $this->assertSame(['25th Percentile', '50th Percentile', '75th Percentile'], $names);

        foreach ($markers as $marker) {
            $this->assertInstanceOf(PercentileBinding::class, $marker->value);
        }
    }

    private function definition(string $type): \LibreNMS\Graph\GraphDefinition
    {
        foreach (DeviceStatsGraphCatalog::definitions() as $definition) {
            if ($definition->graphType() === $type) {
                return $definition;
            }
        }

        throw new \RuntimeException("Missing definition $type");
    }
}
