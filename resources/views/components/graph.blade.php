@if($useEcharts)
    <div
        style="width: {{ $width }}px; height: {{ $height }}px;"
        data-graph-url="{{ $echartsDataUrl }}"
        data-link-url="{{ $link }}"
        data-hide-legend="true"
        data-hide-tooltip="true"
        data-sparkline="true"
        @if($echartsTheme) data-theme="{{ $echartsTheme }}" @endif
        {{ $attributes->filter($filterAttributes)->merge(['class' => 'lnms-echart ' . ($attributes->get('img-class') ?? '')]) }}
    ></div>
@else
    <img width="{{ $width }}" height="{{ $height }}" src="{{ $src }}" alt="{{ $type }}" {{ $attributes->filter($filterAttributes)->merge(['class' => 'graph-image ' . ($attributes->get('img-class') ?? '')]) }} {{ $attributes->only('loading') }}>
@endif
