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
 * @copyright  2024 LibreNMS Contributors
 */

namespace LibreNMS\Graph;

interface GraphDefinition
{
    public function title(array $device): string;

    public function unit(): string;

    /**
     * Return the series to render for this graph.
     *
     * Receiving $query lets definitions make time-range decisions (e.g. skip
     * the weekly average line when the window is less than 8 days).
     *
     * @return SeriesDefinition[]
     */
    public function series(array $device, GraphQuery $query): array;
}
