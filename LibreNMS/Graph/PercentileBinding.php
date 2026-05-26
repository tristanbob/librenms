<?php

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
