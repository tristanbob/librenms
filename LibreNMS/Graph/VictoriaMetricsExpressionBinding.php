<?php

/**
 * VictoriaMetricsExpressionBinding.php
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
 * @copyright  2026 Tristan
 */

namespace LibreNMS\Graph;

final readonly class VictoriaMetricsExpressionBinding implements MetricBinding
{
    public const SOURCE = VictoriaMetricsMetricBinding::SOURCE;

    /**
     * @param callable(array<string, string>): string $expressionBuilder
     * @param string[] $labelKeys
     */
    public function __construct(
        public mixed $expressionBuilder,
        public array $labelKeys = ['device_id'],
        public mixed $transform = null,
    ) {
    }

    public function expression(array $entities): string
    {
        return ($this->expressionBuilder)($entities);
    }

    public function source(): string
    {
        return self::SOURCE;
    }
}
