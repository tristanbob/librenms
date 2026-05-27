<?php

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\DefaultVariables;
use LibreNMS\Graph\GraphDefinition;
use LibreNMS\Graph\GraphQuery;

abstract class EntityGraph implements GraphDefinition
{
    use DefaultVariables;

    public function __construct(
        protected readonly string $graphType,
        protected readonly string $title,
        protected readonly string $unit,
        protected readonly string $entityType,
        protected readonly string $entityIdKey,
        protected readonly string $entityDescrKey,
        protected readonly array $display = [],
    ) {}

    public function graphType(): string { return $this->graphType; }

    public function id(array $device, GraphQuery $query): string
    {
        return $this->graphType . ':' . ($query->entities[$this->entityIdKey] ?? '');
    }

    public function title(array $device): string { return $this->title; }

    public function subtitle(array $device, GraphQuery $query): string
    {
        return ($device['hostname'] ?? '') . ' - ' . ($query->entities[$this->entityDescrKey] ?? '');
    }

    public function unit(array $device, GraphQuery $query): string { return $this->unit; }

    public function entityType(): string { return $this->entityType; }

    public function display(): array
    {
        return $this->display + ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function markers(array $device, GraphQuery $query): array { return []; }

    abstract public function series(array $device, GraphQuery $query): array;
}
