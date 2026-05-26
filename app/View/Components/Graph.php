<?php

namespace App\View\Components;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Port;
use Illuminate\View\Component;
use LibreNMS\Graph\GraphDataUrl;
use LibreNMS\Graph\GraphDefinitionRegistry;
use LibreNMS\Util\Time;

class Graph extends Component
{
    const DEFAULT_WIDE_WIDTH = 340;
    const DEFAULT_WIDE_HEIGHT = 100;
    const DEFAULT_NORMAL_WIDTH = 300;
    const DEFAULT_NORMAL_HEIGHT = 150;

    /**
     * @var array
     */
    public $vars;
    /**
     * @var int|null
     */
    public $width;
    /**
     * @var int|null
     */
    public $height;
    /**
     * @var string
     */
    public $type;
    /**
     * @var string
     */
    public $legend;
    /**
     * @var int
     */
    public $absolute_size;
    /**
     * @var bool
     */
    private $popup;
    public bool $useEcharts = false;
    public ?string $echartsDataUrl = null;
    public ?string $echartsTheme = null;

    /**
     * Create a new component instance.
     *
     * @param  string  $type
     * @param  array  $vars
     * @param  int|string  $from
     * @param  int|string  $to
     * @param  string  $legend
     * @param  string  $aspect
     * @param  int|null  $width
     * @param  int|null  $height
     * @param  int  $absolute_size
     * @param  Device|int|null  $device
     * @param  Port|int|null  $port
     * @param  bool  $link
     * @param  string  $popupTitle
     */
    public function __construct(
        string $type = '',
        array $vars = [],
        public $from = '-1d',
        public $to = null,
        string $legend = 'no',
        string $aspect = 'normal',
        ?int $width = null,
        ?int $height = null,
        int $absolute_size = 0,
        private $link = true,
        $popup = false,
        public mixed $popupTitle = '',
        $device = null,
        $port = null
    ) {
        $this->type = $type;
        $this->vars = $vars;
        $this->legend = $legend;
        $this->absolute_size = $absolute_size;
        $this->width = $width ?: ($aspect == 'wide' ? self::DEFAULT_WIDE_WIDTH : self::DEFAULT_NORMAL_WIDTH);
        $this->height = $height ?: ($aspect == 'wide' ? self::DEFAULT_WIDE_HEIGHT : self::DEFAULT_NORMAL_HEIGHT);
        $this->popup = filter_var($popup, FILTER_VALIDATE_BOOLEAN);

        // handle device and port ids/models for convenience could be set in $vars
        if ($device instanceof Device) {
            $this->vars['device'] = $device->device_id;
        } elseif (is_numeric($device)) {
            $this->vars['device'] = $device;
        } elseif ($port instanceof Port) {
            $this->vars['id'] = $port->port_id;
        } elseif (is_numeric($port)) {
            $this->vars['id'] = $port;
        }
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|\Closure|string
     */
    public function render()
    {
        $view = $this->popup ? 'components.graph-popup' : ($this->link === false ? 'components.graph' : 'components.linked-graph');
        $data = [
            'link' => $this->getLink(),
            'src' => $this->getSrc(),
        ];
        $this->echartsDataUrl = $this->getEchartsDataUrl();
        $this->useEcharts = $this->echartsDataUrl !== null;
        $this->echartsTheme = $this->useEcharts ? json_encode($this->getEchartsTheme()) : null;

        return view($view, $data);
    }

    /**
     * @param  mixed  $value
     * @param  int|string  $key
     * @return bool
     */
    public function filterAttributes($value, $key): bool
    {
        $filtered = [
            'legend',
            'height',
            'loading',
            'img-class',
        ];

        // do not add class and style to the image, add them to the outer link
        if ($this->link) {
            $filtered[] = 'class';
            $filtered[] = 'style';
        }

        return ! in_array($key, $filtered);
    }

    private function getSrc(): string
    {
        return url('graph.php') . '?' . http_build_query($this->vars + [
            'type' => $this->type,
            'legend' => $this->legend,
            'absolute_size' => $this->absolute_size,
            'width' => $this->width,
            'height' => $this->height,
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    private function getLink(): string
    {
        return match ($this->link) {
            true => url('graphs') . '/' . http_build_query($this->vars + [
                'type' => $this->type,
                'from' => $this->from,
                'to' => $this->to,
            ], '', '/'),
            false => '',
            default => $this->link,
        };
    }

    private function getEchartsTheme(): array
    {
        $out = [];
        $defaults = [
            'light' => ['back' => 'transparent', 'grid' => '#a5a5a5', 'frame' => '#5e5e5e', 'font' => '#000000'],
            'dark'  => ['back' => '#2e3338',      'grid' => '#292929', 'frame' => '#5e5e5e', 'font' => '#f8f9f9'],
        ];
        foreach (['light' => 'rrdgraph_def_text', 'dark' => 'rrdgraph_def_text_dark'] as $mode => $key) {
            $def = LibrenmsConfig::get($key, '');
            preg_match_all('/-c ([A-Z]+)#([0-9A-Fa-f]{6,8})/', $def, $m);
            $colors = $m[1] ? array_combine($m[1], $m[2]) : [];
            $fontKey = $mode === 'dark' ? 'rrdgraph_def_text_color_dark' : 'rrdgraph_def_text_color';
            $fontDefault = $defaults[$mode]['font'];
            $out[$mode] = [
                'font'       => '#' . ltrim(LibrenmsConfig::get($fontKey, ltrim($fontDefault, '#')), '#'),
                'background' => isset($colors['BACK']) ? '#' . substr($colors['BACK'], 0, 6) : $defaults[$mode]['back'],
                'grid'       => isset($colors['GRID']) ? '#' . $colors['GRID'] : $defaults[$mode]['grid'],
                'frame'      => isset($colors['FRAME']) ? '#' . $colors['FRAME'] : $defaults[$mode]['frame'],
            ];
        }

        return $out;
    }

    private function getEchartsDataUrl(): ?string
    {
        if (LibrenmsConfig::get('graphs.renderer', 'rrd') !== 'echarts' || $this->type === '') {
            return null;
        }

        $registry = app(GraphDefinitionRegistry::class);
        if (! $registry->supports($this->type)) {
            return null;
        }

        $query = array_filter([
            'from'      => Time::parseAt($this->from ?: '-1d'),
            'to'        => $this->to === null || $this->to === '' ? time() : Time::parseAt($this->to),
            'width'     => $this->width,
            'height'    => $this->height,
            'scale_min' => isset($this->vars['scale_min']) ? (int) $this->vars['scale_min'] : null,
            'scale_max' => isset($this->vars['scale_max']) ? (int) $this->vars['scale_max'] : null,
        ], fn ($v) => $v !== null);

        $definition = $registry->definitionFor($this->type);

        return match ($definition->entityType()) {
            'device' => isset($this->vars['device'])
                ? GraphDataUrl::device((int) $this->vars['device'], $this->type, $query)
                : null,
            'port' => isset($this->vars['id'])
                ? GraphDataUrl::port((int) $this->vars['id'], $this->type, $query)
                : null,
            'sensor' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::sensor((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            'wireless_sensor' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::wireless((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            'processor' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::processor((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            'mempool' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::mempool((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            'storage' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::storage((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            'printer_supply' => isset($this->vars['device'], $this->vars['id'])
                ? GraphDataUrl::printerSupply((int) $this->vars['device'], (int) $this->vars['id'], $this->type, $query)
                : null,
            default => null,
        };
    }
}
