<?php

/**
 * GraphQuery.php
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

class GraphQuery
{
    public function __construct(
        public readonly string $graphType,
        public readonly int    $from,
        public readonly int    $to,
        public readonly int    $step,
        public readonly int    $width,
        public readonly array  $entities,
    ) {}

    public static function fromRequest(
        string $graphType,
        array  $entities,
        int    $from  = 0,
        int    $to    = 0,
        int    $width = 1200,
    ): self {
        $to   = $to   ?: time();
        $from = $from ?: $to - 86400;
        $step = max(300, (int) ceil(($to - $from) / $width));

        return new self($graphType, $from, $to, $step, $width, $entities);
    }
}
