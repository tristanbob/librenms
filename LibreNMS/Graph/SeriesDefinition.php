<?php

/**
 * SeriesDefinition.php
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

/**
 * Describes one rendered line/area on a graph.
 *
 * The provider groups definitions by (rrdFile, step) and calls rrdtool fetch
 * once per unique combination, so multiple series sharing the same file and
 * step cost only one subprocess.
 *
 * @property-read mixed $transform  callable(float $v): float, or null
 */
class SeriesDefinition
{
    /**
     * @param string        $name      Legend label
     * @param string        $key       Unique machine key within the graph
     * @param string        $rrdFile   Absolute path to the RRD file
     * @param string        $ds        Data-source name inside the RRD file
     * @param string        $color     6-digit hex color (no leading #)
     * @param bool          $area      Render as filled area beneath the line
     * @param string|null   $stack     Stack group name (null = not stacked)
     * @param int|null      $step      RRA step override in seconds (null = use query step)
     * @param callable|null $transform Applied to each raw value before storage, e.g. fn($v) => $v * 8
     */
    public function __construct(
        public readonly string   $name,
        public readonly string   $key,
        public readonly string   $rrdFile,
        public readonly string   $ds,
        public readonly string   $color     = '663399',
        public readonly bool     $area      = false,
        public readonly ?string  $stack     = null,
        public readonly ?int     $step      = null,
        public readonly mixed    $transform = null,
    ) {}
}
