<?php

namespace LibreNMS\Graph;

class GraphExpression
{
    private function __construct(
        public readonly string $type,
        public readonly array $arguments = [],
    ) {}

    public static function def(
        string|array $rrdName,
        string $ds,
        string $consolidation = 'AVERAGE',
        ?int $step = null,
    ): self {
        return new self('def', compact('rrdName', 'ds', 'consolidation', 'step'));
    }

    public static function scale(self $expression, float $factor): self
    {
        return new self('scale', compact('expression', 'factor'));
    }

    public static function sum(self ...$expressions): self
    {
        return new self('sum', compact('expressions'));
    }

    public static function max(self ...$expressions): self
    {
        return new self('max', compact('expressions'));
    }

    public static function divide(self $numerator, self $denominator): self
    {
        return new self('divide', compact('numerator', 'denominator'));
    }

    public static function percent(self $numerator, self $denominator): self
    {
        return self::scale(self::divide($numerator, $denominator), 100);
    }

    public static function negate(self $expression): self
    {
        return self::scale($expression, -1);
    }

    public static function shift(self $expression, int $seconds): self
    {
        return new self('shift', compact('expression', 'seconds'));
    }

    public static function percentile(self $expression, float $percentile): self
    {
        return new self('percentile', compact('expression', 'percentile'));
    }

    public static function total(self $expression): self
    {
        return new self('total', compact('expression'));
    }
}
