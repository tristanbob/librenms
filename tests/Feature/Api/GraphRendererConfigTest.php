<?php

/**
 * GraphRendererConfigTest.php
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

namespace LibreNMS\Tests\Feature\Api;

use App\Facades\LibrenmsConfig;
use LibreNMS\Tests\DBTestCase;

class GraphRendererConfigTest extends DBTestCase
{
    public function test_graphs_renderer_defaults_to_rrd(): void
    {
        $this->assertSame('rrd', LibrenmsConfig::get('graphs.renderer'));
    }

    public function test_graphs_renderer_can_be_set_to_echarts(): void
    {
        LibrenmsConfig::set('graphs.renderer', 'echarts');
        $this->assertSame('echarts', LibrenmsConfig::get('graphs.renderer'));
        LibrenmsConfig::set('graphs.renderer', 'rrd');
    }
}
