<?php

/**
 * GraphDefinition.php
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

interface GraphDefinition
{
    public function graphType(): string;

    public function id(array $device, GraphQuery $query): string;

    public function title(array $device): string;

    public function subtitle(array $device, GraphQuery $query): string;

    public function unit(array $device, GraphQuery $query): string;

    /**
     * Return the series to render for this graph.
     *
     * Receiving $query lets definitions make time-range decisions (e.g. skip
     * the weekly average line when the window is less than 8 days).
     *
     * @return GraphSeriesDefinition[]
     */
    public function series(array $device, GraphQuery $query): array;

    public function markers(array $device, GraphQuery $query): array;

    public function thresholds(array $device, GraphQuery $query): array;

    /**
     * The primary entity type this graph belongs to.
     * Used by the view layer to build the correct data URL.
     * Examples: 'device', 'port', 'sensor', 'bill'
     */
    public function entityType(): string;

    /**
     * Frontend renderer hints for this graph type.
     * Merged with base display defaults before serialization.
     * Keys: kind ('line'|'bar'), stacked (bool), area (bool)
     */
    public function display(): array;
}
