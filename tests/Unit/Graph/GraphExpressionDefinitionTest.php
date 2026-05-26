<?php

namespace LibreNMS\Tests\Unit\Graph;

use LibreNMS\Graph\Definitions\Device\DeviceStatsGraphCatalog;
use LibreNMS\Graph\GraphExpression;
use LibreNMS\Graph\GraphPlanDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphVariableDefinition;
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
        $this->assertInstanceOf(GraphPlanDefinition::class, $graph);
        $this->assertSame(['duration'], array_map(fn ($variable) => $variable->name, $graph->variables()));

        $query = new GraphQuery('device', 'device_availability', 1000, 2000, 1200, 300, ['device_id' => 1], ['duration' => 172800]);
        $plan = $graph->expressions(['device_id' => 1, 'hostname' => 'router1'], $query);
        $expression = $plan->series[0]->expression;

        $this->assertInstanceOf(GraphExpression::class, $expression);
        $this->assertSame('def', $expression->type);
        $this->assertSame(['availability', 172800], $expression->arguments['rrdName']);
    }

    public function testSimpleStatsEmitServerSidePercentileMarkers(): void
    {
        $graph = $this->definition('device_hr_processes');
        $this->assertInstanceOf(GraphPlanDefinition::class, $graph);

        $query = new GraphQuery('device', 'device_hr_processes', 1000, 2000, 1200, 300, ['device_id' => 1]);
        $plan = $graph->expressions(['device_id' => 1, 'hostname' => 'router1'], $query);

        $this->assertSame(['25th Percentile', '50th Percentile', '75th Percentile'], array_map(fn ($marker) => $marker->name, $plan->markers));
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
