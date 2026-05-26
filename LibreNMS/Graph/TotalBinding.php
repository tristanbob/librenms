<?php

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
    ) {}
}
