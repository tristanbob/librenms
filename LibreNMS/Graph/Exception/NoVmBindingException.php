<?php

namespace LibreNMS\Graph\Exception;

/**
 * Thrown when a graph definition has no VictoriaMetrics bindings and RRD should be used instead.
 * This is an expected condition, not an error — the backend selector silently falls back to RRD.
 */
class NoVmBindingException extends \RuntimeException {}
