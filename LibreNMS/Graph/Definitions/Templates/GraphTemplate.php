<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\DefaultVariables;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Graph\GraphSeriesDefinition;

abstract class GraphTemplate implements GraphDefinition
{
    use DefaultVariables;
    public function __construct(
        protected readonly string $graphType,
        protected readonly string $title,
        protected readonly string $unit,
        protected readonly array $display = [],
    ) {}

    public function graphType(): string
    {
        return $this->graphType;
    }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType . ':' . $device['device_id'];
    }

    public function title(array $device): string
    {
        return $this->title;
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return $device['hostname'] ?? '';
    }

    public function unit(array $device, GraphQuery $query): string
    {
        return $this->unit;
    }

    public function entityType(): string
    {
        return 'device';
    }

    public function display(): array
    {
        return $this->display + ['kind' => 'line', 'stacked' => false, 'area' => false, 'legend' => true];
    }

    public function markers(array $device, GraphQuery $query): array
    {
        return [];
    }

    protected function paletteColor(string $palette, int $index, string $fallback): string
    {
        $colors = (array) \LibreNMS\Config::get("graph_colours.$palette", []);

        return $colors[$index % max(1, count($colors))] ?? $fallback;
    }

    protected function stackedMultiplier(): int
    {
        return \App\Facades\LibrenmsConfig::get('webui.graph_stacked') == true ? 1 : -1;
    }

    /**
     * @return GraphSeriesDefinition[]
     */
    abstract public function series(array $device, GraphQuery $query): array;
}
