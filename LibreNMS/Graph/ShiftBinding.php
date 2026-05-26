<?php

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
