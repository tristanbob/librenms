<?php

namespace LibreNMS\Graph;

class GraphMarkerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly PercentileBinding|TotalBinding|float|int $value,
        public readonly string $type = 'horizontal_line',
        public readonly ?string $severity = null,
        public readonly ?string $color = null,
        public readonly string $lineStyle = 'solid',
    ) {}

    public static function percentile(string $name, MetricBinding $inner, float $percentile, ?string $color = null): self
    {
        return new self($name, new PercentileBinding($inner, $percentile), color: $color);
    }
}
