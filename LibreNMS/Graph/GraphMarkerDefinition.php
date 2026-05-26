<?php

namespace LibreNMS\Graph;

class GraphMarkerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly GraphExpression|float|int $value,
        public readonly string $type = 'horizontal_line',
        public readonly ?string $severity = null,
        public readonly ?string $color = null,
        public readonly string $lineStyle = 'solid',
    ) {}

    public static function percentile(string $name, GraphExpression $expression, float $percentile, ?string $color = null): self
    {
        return new self($name, GraphExpression::percentile($expression, $percentile), color: $color);
    }
}
