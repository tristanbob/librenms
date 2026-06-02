<?php

/**
 * TotalBinding.php
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
 * Wraps a MetricBinding and computes the sum of all non-null samples in the query
 * window. Used as a GraphMarkerDefinition value (e.g. "Total traffic = 1.2 TB").
 *
 * Evaluation is application-side: the provider fetches the inner binding's time-series
 * data and sums the values. Intended for billing-style total-usage graphs.
 */
class TotalBinding
{
    public function __construct(
        public readonly MetricBinding $inner,
    ) {
    }
}
