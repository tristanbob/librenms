<?php

/**
 * PrometheusTextFormatter.php
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

namespace LibreNMS\Data\Store\VictoriaMetrics;

class PrometheusTextFormatter
{
    /**
     * @param  array<string, string>  $labels
     */
    public static function format(MetricDefinition $metric, array $labels, float $value, int $timestampMs): string
    {
        $labelText = self::formatLabels($labels);

        return "# TYPE {$metric->name} {$metric->type}\n"
            . "{$metric->name}{$labelText} " . self::formatValue($value) . " {$timestampMs}";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        ksort($labels);

        $pairs = [];
        foreach ($labels as $key => $value) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                continue;
            }

            $pairs[] = $key . '="' . self::escapeLabelValue((string) $value) . '"';
        }

        return empty($pairs) ? '' : '{' . implode(',', $pairs) . '}';
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(
            ["\\", "\n", '"'],
            ["\\\\", "\\n", '\\"'],
            $value
        );
    }

    private static function formatValue(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }
}
