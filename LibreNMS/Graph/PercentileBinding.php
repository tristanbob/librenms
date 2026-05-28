<?php

/**
 * PercentileBinding.php
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

/**
 * Wraps a MetricBinding and computes the Nth percentile over all non-null samples
 * in the query window. Used as a GraphMarkerDefinition value to render a horizontal
 * reference line (e.g. "95th percentile = 42 Mbps").
 *
 * Evaluation is application-side: the provider fetches the inner binding's time-series
 * data and sorts the values to find the percentile.
 */
class PercentileBinding
{
    public function __construct(
        public readonly MetricBinding $inner,
        public readonly float $percentile,
    ) {}
}
