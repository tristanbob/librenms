<?php

namespace LibreNMS\Graph;

interface GraphPlanDefinition extends GraphDefinition
{
    /**
     * @return GraphVariableDefinition[]
     */
    public function variables(): array;

    public function expressions(array $device, GraphQuery $query): GraphPlan;
}
