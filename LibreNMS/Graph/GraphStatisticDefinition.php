<?php

namespace LibreNMS\Graph;

class GraphStatisticDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly GraphExpression $expression,
        public readonly string $aggregation,
    ) {}
}
