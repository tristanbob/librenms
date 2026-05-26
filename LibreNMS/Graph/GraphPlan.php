<?php

namespace LibreNMS\Graph;

class GraphPlan
{
    /**
     * @param GraphSeriesDefinition[] $series
     * @param array<int, GraphMarkerDefinition|array> $markers
     * @param GraphStatisticDefinition[] $statistics
     */
    public function __construct(
        public readonly array $series,
        public readonly array $markers = [],
        public readonly array $statistics = [],
    ) {}
}
