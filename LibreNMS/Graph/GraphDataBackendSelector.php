<?php

/**
 * GraphDataBackendSelector.php
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

namespace LibreNMS\Graph;

use App\Facades\LibrenmsConfig;
use Illuminate\Support\Facades\Log;
use LibreNMS\Graph\Exception\NoVmBindingException;

/**
 * Selects between RRD and VictoriaMetrics backends based on configuration.
 * Falls back to RRD automatically if VictoriaMetrics raises a RuntimeException.
 */
class GraphDataBackendSelector implements GraphDataProvider
{
    public function __construct(
        private readonly GraphDataProvider $rrd,
        private readonly GraphDataProvider $vm,
    ) {
    }

    public function query(GraphQuery $query): GraphDataResult
    {
        if (! LibrenmsConfig::get('victoriametrics.query_enabled', false)) {
            return $this->rrd->query($query);
        }

        try {
            return $this->vm->query($query);
        } catch (NoVmBindingException $e) {
            Log::debug('VictoriaMetrics not used, falling back to RRD: ' . $e->getMessage());

            return $this->rrd->query($query);
        } catch (\RuntimeException $e) {
            Log::warning('VictoriaMetrics query failed, falling back to RRD: ' . $e->getMessage());
            $result = $this->rrd->query($query);
            $result->setFallback(true);
            $result->addWarning('VictoriaMetrics query failed; RRD used as fallback.');

            return $result;
        }
    }
}
