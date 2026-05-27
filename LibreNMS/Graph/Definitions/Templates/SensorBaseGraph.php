<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\DefaultVariables;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;

abstract class SensorBaseGraph implements GraphDefinition
{
    use DefaultVariables;

    private bool $transformComputed = false;
    /** @var callable|null */
    private $transform = null;

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType() . ':' . ($query->entities['sensor_id'] ?? '');
    }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' — ' . ($query->entities['sensor_descr'] ?? '');
    }

    public function display(): array
    {
        return ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    protected function marker(string $name, mixed $value, string $severity): array
    {
        $value     = (float) $value;
        $transform = $this->valueTransform();
        if ($transform !== null) {
            $value = $transform($value);
        }

        return ['type' => 'horizontal_line', 'name' => $name, 'value' => $value, 'severity' => $severity];
    }

    protected function valueTransform(): ?callable
    {
        if (! $this->transformComputed) {
            $this->transform        = $this->computeTransform();
            $this->transformComputed = true;
        }

        return $this->transform;
    }

    abstract protected function computeTransform(): ?callable;
}
