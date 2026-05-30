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
 * @copyright  2026 Tristan Rhodes
 * @author     Tristan Rhodes <tristan.rhodes@gmail.com>
 */

namespace LibreNMS\Graph;

use App\Models\Device;

abstract class AbstractGraphDataProvider implements GraphDataProvider
{
    /**
     * Per-query memo of raw backend fetches, keyed by the fetch inputs (RRD file/expr +
     * range/step). Lets markers reuse the series fetch instead of issuing a second
     * rrdtool/VM round-trip. Stores RAW points only; each consumer still applies its own
     * transform, so caching cannot change a computed value.
     *
     * @var array<string, mixed>
     */
    private array $rawFetchCache = [];

    public function __construct(
        protected readonly GraphDefinitionRegistry $registry,
    ) {}

    /** @throws \RuntimeException if device_id is missing or the graph type is not registered */
    final public function query(GraphQuery $query): GraphDataResult
    {
        $this->rawFetchCache = [];

        $deviceId = $query->entities['device_id'] ?? null;
        if ($deviceId === null) {
            throw new \RuntimeException(
                "GraphQuery is missing 'device_id' in entities for graph type '{$query->graphType}'."
            );
        }

        $deviceModel = Device::findOrFail($deviceId);
        $def    = $this->registry->definitionFor($query->graphType);
        $variables = [];
        foreach ($def->variables() as $variable) {
            $variables[$variable->name] = $variable->resolve($query->options);
        }
        if ($variables !== []) {
            $query = $query->withOptions($variables + $query->options);
        }

        $context = new GraphContext($deviceModel, $query);

        $result = new GraphDataResult(
            id:       $def->id($context),
            type:     $query->graphType,
            title:    $def->title($context),
            subtitle: $def->subtitle($context),
            unit:     $def->unit($context),
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
        $this->fillSeries($result, $def, $context);
        foreach ($def->markers($context) as $marker) {
            if ($marker instanceof GraphMarkerDefinition) {
                $value = $this->resolveMarkerValue($marker->value, $context);
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

    abstract protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        GraphContext    $context
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
        GraphContext $context,
    ): array;

    /**
     * Resolve a GraphMarkerDefinition value to a concrete float.
     * Handles PercentileBinding and TotalBinding by fetching the inner binding's
     * data points and computing the aggregation application-side.
     */
    protected function resolveMarkerValue(
        PercentileBinding|TotalBinding|float|int $value,
        GraphContext $context,
    ): ?float {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $points = array_values($this->evaluateBindingPoints($value->inner, $context));
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

    /**
     * Unwrap a possible ShiftBinding once, shared by every backend so the
     * "current vs. previous period" offset logic is not re-implemented per provider.
     *
     * @return array{0: MetricBinding, 1: GraphQuery, 2: int}
     *         [inner binding, time-shifted query, shift in milliseconds]
     */
    protected function unwrapShift(MetricBinding $binding, GraphQuery $query): array
    {
        if ($binding instanceof ShiftBinding) {
            return [
                $binding->inner,
                $query->withTimeRange($query->from - $binding->offsetSeconds, $query->to - $binding->offsetSeconds),
                $binding->offsetSeconds * 1000,
            ];
        }

        return [$binding, $query, 0];
    }

    /**
     * Return the underlying binding, unwrapping a ShiftBinding if present. Used where
     * the time shift is irrelevant (e.g. percentile/total marker aggregation).
     */
    protected function innerBinding(MetricBinding $binding): MetricBinding
    {
        return $binding instanceof ShiftBinding ? $binding->inner : $binding;
    }

    /**
     * Memoize a raw backend fetch for the lifetime of the current query() call.
     * Failures are not cached, so a transient error is retried by later consumers.
     *
     * @template T
     * @param  callable(): T $fetch
     * @return T
     */
    protected function memoizeFetch(string $key, callable $fetch): mixed
    {
        if (! array_key_exists($key, $this->rawFetchCache)) {
            $this->rawFetchCache[$key] = $fetch();
        }

        return $this->rawFetchCache[$key];
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
