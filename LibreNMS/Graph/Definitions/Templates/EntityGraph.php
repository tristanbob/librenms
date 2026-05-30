<?php

/**
 * EntityGraph.php
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

namespace LibreNMS\Graph\Definitions\Templates;

use LibreNMS\Graph\DefaultVariables;
use LibreNMS\Graph\GraphContext;
use LibreNMS\Graph\GraphDefinition;

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

    public function id(GraphContext $context): string
    {
        return $this->graphType . ':' . ($context->query->entities[$this->entityIdKey] ?? '');
    }

    public function title(GraphContext $context): string { return $this->title; }

    public function subtitle(GraphContext $context): string
    {
        return ($context['hostname'] ?? '') . ' - ' . ($context->query->entities[$this->entityDescrKey] ?? '');
    }

    public function unit(GraphContext $context): string { return $this->unit; }

    public function entityType(): string { return $this->entityType; }

    public function display(): array
    {
        return $this->display + ['kind' => 'line', 'stacked' => false, 'area' => true];
    }

    public function markers(GraphContext $context): array { return []; }

    abstract public function series(GraphContext $context): array;
}
