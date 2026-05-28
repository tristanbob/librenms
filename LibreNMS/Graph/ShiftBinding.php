<?php

/**
 * ShiftBinding.php
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
 * Wraps a MetricBinding and fetches its data from a time range shifted back by
 * $offsetSeconds. The returned timestamps are advanced forward by +$offsetSeconds
 * so the series aligns with the current window — enabling "current vs. previous
 * period" overlays.
 *
 * source() delegates to the inner binding so the provider that handles the inner
 * binding type also handles this wrapper.
 */
class ShiftBinding implements MetricBinding
{
    public function __construct(
        public readonly MetricBinding $inner,
        public readonly int $offsetSeconds,
    ) {}

    public function source(): string
    {
        return $this->inner->source();
    }
}
