<?php

/**
 * AbstractGraphDataProvider.php
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
 * @copyright  2026 LibreNMS Contributors
 */

namespace LibreNMS\Graph;

use App\Models\Device;

abstract class AbstractGraphDataProvider implements GraphDataProvider
{
    public function __construct(
        protected readonly GraphDefinitionRegistry $registry,
    ) {}

    /** @throws \RuntimeException if device_id is missing or the graph type is not registered */
    final public function query(GraphQuery $query): GraphDataResult
    {
        $deviceId = $query->entities['device_id'] ?? null;
        if ($deviceId === null) {
            throw new \RuntimeException(
                "GraphQuery is missing 'device_id' in entities for graph type '{$query->graphType}'."
            );
        }

        $device = Device::findOrFail($deviceId)->toArray();
        $def    = $this->registry->definitionFor($query->graphType);
        $variables = [];
        foreach ($def->variables() as $variable) {
            $variables[$variable->name] = $variable->resolve($query->options);
        }
        if ($variables !== []) {
            $query = $query->withOptions($variables + $query->options);
        }

        $result = new GraphDataResult(
            id:       $def->id($device, $query),
            type:     $query->graphType,
            title:    $def->title($device),
            subtitle: $def->subtitle($device, $query),
            unit:     $def->unit($device, $query),
            from:     $query->from,
            to:       $query->to,
            step:     $query->step,
        );
        $result->setDisplay(array_merge(
            ['renderer' => 'timeseries', 'legend' => true, 'tooltip' => true],
            $def->display()
        ));
        $result->setVariables($variables);
        if (isset($query->options['scale_min'])) {
            $result->overrideYAxisMin((int) $query->options['scale_min']);
        }
        if (isset($query->options['scale_max'])) {
            $result->overrideYAxisMax((int) $query->options['scale_max']);
        }
        $this->fillSeries($result, $def, $device, $query);
        foreach ($def->markers($device, $query) as $marker) {
            if ($marker instanceof GraphMarkerDefinition) {
                $value = $this->resolveMarkerValue($marker->value, $device, $query);
                if ($value === null || ! is_finite($value)) {
                    continue;
                }
                $result->addMarker(array_filter([
                    'type'      => $marker->type,
                    'name'      => $marker->name,
                    'value'     => round($value, 4),
                    'severity'  => $marker->severity,
                    'color'     => $marker->color,
                    'lineStyle' => $marker->lineStyle,
                ], fn ($v) => $v !== null));
                continue;
            }
            $result->addMarker($marker);
        }

        return $result;
    }

    /**
     * @param array{device_id: int, hostname: string, os: string, sysName?: string,
     *             display?: string, location_id?: int} $device  Eloquent Device model as array
     */
    abstract protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        array           $device,
        GraphQuery      $query
    ): void;

    /**
     * Fetch all data points for a single metric binding as an associative array
     * keyed by timestamp ms. Returns only finite, non-null values.
     * Used internally to evaluate PercentileBinding and TotalBinding marker values.
     *
     * @return array<int, float>
     */
    abstract protected function evaluateBindingPoints(
        MetricBinding $binding,
        array $device,
        GraphQuery $query,
    ): array;

    /**
     * Resolve a GraphMarkerDefinition value to a concrete float.
     * Handles PercentileBinding and TotalBinding by fetching the inner binding's
     * data points and computing the aggregation application-side.
     */
    protected function resolveMarkerValue(
        PercentileBinding|TotalBinding|float|int $value,
        array $device,
        GraphQuery $query,
    ): ?float {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $points = array_values($this->evaluateBindingPoints($value->inner, $device, $query));
        if ($points === []) {
            return null;
        }

        if ($value instanceof PercentileBinding) {
            sort($points, SORT_NUMERIC);
            $idx = (count($points) - 1) * ($value->percentile / 100);
            $lo  = (int) floor($idx);
            $hi  = (int) ceil($idx);

            return $lo === $hi ? $points[$lo] : $points[$lo] + ($points[$hi] - $points[$lo]) * ($idx - $lo);
        }

        return array_sum($points);
    }

    protected function emptySeries(GraphSeriesDefinition $seriesDef): GraphSeries
    {
        return new GraphSeries(
            name:        $seriesDef->name,
            key:         $seriesDef->key,
            unit:        $seriesDef->unit,
            type:        $seriesDef->type,
            area:        $seriesDef->area,
            stack:       $seriesDef->stack,
            color:       $seriesDef->color,
            lineColor:   $seriesDef->lineColor,
            areaOpacity: $seriesDef->areaOpacity,
            lineOpacity: $seriesDef->lineOpacity,
            lineWidth:   $seriesDef->lineWidth,
            negate:      $seriesDef->negate,
            yAxisIndex:  $seriesDef->yAxisIndex,
        );
    }
}
