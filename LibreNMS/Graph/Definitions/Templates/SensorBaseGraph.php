<?php

/**
 * SensorBaseGraph.php
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

abstract class SensorBaseGraph implements GraphDefinition
{
    use DefaultVariables;

    private bool $transformComputed = false;
    /** @var callable|null */
    private $transform = null;

    public function id(GraphContext $context): string
    {
        return $this->graphType() . ':' . ($context->query->entities['sensor_id'] ?? '');
    }

    public function subtitle(GraphContext $context): string
    {
        return ($context['hostname'] ?? '') . ' — ' . ($context->query->entities['sensor_descr'] ?? '');
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
