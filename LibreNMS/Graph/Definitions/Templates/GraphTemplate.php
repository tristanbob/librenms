<?php

/**
 * GraphTemplate.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\DefaultVariables;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinition;
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

    public function id(GraphContext $context): string
    {
        return $this->graphType . ':' . $context['device_id'];
    }

    public function title(GraphContext $context): string
    {
        return $this->title;
    }

    public function subtitle(GraphContext $context): string
    {
        return $context['hostname'] ?? '';
    }

    public function unit(GraphContext $context): string
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

    public function markers(GraphContext $context): array
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
     * Build the trailing-average series (1h / 1d / 1w) shown progressively as the
     * window widens. Mirrors generic_stats.inc.php: the daily line appears for windows
     * wider than ~36h and the weekly line for windows wider than 8 days. Shared so the
     * step thresholds and naming are defined in exactly one place.
     *
     * @param callable(int $step): array $bindingFor returns the bindings for a given RRD step
     * @return GraphSeriesDefinition[]
     */
    protected function trailingAverageSeries(
        GraphContext $context,
        string $keyPrefix,
        string $palette,
        callable $bindingFor,
    ): array {
        $timeDiff = $context->query->to - $context->query->from;
        $unit     = $this->unit($context);

        // [key suffix, display name, min window (s) to show, palette index, fallback colour, RRD step (s)]
        $rows = [
            ['1h', '1 hour avg', 0,      4, '3366BB', 3600],
            ['1d', '1 day avg',  129600, 5, 'AA3355', 86400],
            ['1w', '1 week avg', 691200, 6, '881177', 604800],
        ];

        $series = [];
        foreach ($rows as [$suffix, $name, $threshold, $colorIndex, $fallback, $step]) {
            if ($timeDiff < $threshold) {
                continue;
            }

            $series[] = new GraphSeriesDefinition(
                name:     $name,
                key:      $keyPrefix . '_' . $suffix,
                unit:     $unit,
                color:    $this->paletteColor($palette, $colorIndex, $fallback),
                bindings: $bindingFor($step),
            );
        }

        return $series;
    }

    /**
     * @return GraphSeriesDefinition[]
     */
    abstract public function series(GraphContext $context): array;
}
