<?php

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
