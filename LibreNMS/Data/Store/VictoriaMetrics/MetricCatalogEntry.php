<?php

/**
 * MetricCatalogEntry.php
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

namespace LibreNMS\Data\Store\VictoriaMetrics;

final readonly class MetricCatalogEntry
{
    /**
     * @param string[]      $identityLabels  Low-cardinality labels used as query matchers (the reader's selector keys).
     * @param string[]      $descriptiveLabels Extra human-facing labels written but NOT used to query (e.g. ifName).
     * @param string|null   $rrdDs           RRD data source name for this metric (often equals $field).
     *                                        The RRD file name is entity-derived at query time and is not stored here.
     * @param string        $consolidation   RRD consolidation function used when reading this metric.
     * @param array{op?: string, inputs?: string[], factor?: float}|null $derived
     *                                        Optional derivation describing how this metric is computed from other
     *                                        catalog keys (e.g. ['op' => 'rate'] or ['op' => 'scale', 'factor' => 8]).
     *                                        Lets the schema model composite/derived series (multi-input, counter->rate)
     *                                        without a per-DS-only assumption.
     */
    public function __construct(
        public string $key,
        public string $measurement,
        public string $field,
        public MetricDefinition $definition,
        public array $identityLabels = ['hostname'],
        public array $descriptiveLabels = [],
        public ?string $rrdDs = null,
        public string $consolidation = 'AVERAGE',
        public ?array $derived = null,
    ) {
    }
}
