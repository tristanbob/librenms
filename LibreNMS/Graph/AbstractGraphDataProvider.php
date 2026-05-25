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
        foreach ($def->markers($device, $query) as $marker) {
            $result->addMarker($marker);
        }
        foreach ($def->thresholds($device, $query) as $threshold) {
            $result->addThreshold($threshold);
        }

        $this->fillSeries($result, $def, $device, $query);

        return $result;
    }

    abstract protected function fillSeries(
        GraphDataResult $result,
        GraphDefinition $def,
        array           $device,
        GraphQuery      $query
    ): void;

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
        );
    }
}
