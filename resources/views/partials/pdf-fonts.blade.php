@php
    $fontDir = resource_path('fonts');
    $clear = base64_encode(file_get_contents($fontDir . '/BeausiteClassicWeb-Clear.woff'));
    $semibold = base64_encode(file_get_contents($fontDir . '/BeausiteClassicWeb-Semibold.woff'));
@endphp
<style>
    @font-face {
        font-family: 'Beausite';
        font-weight: 400;
        font-style: normal;
        src: url(data:font/woff;base64,{{ $clear }}) format('woff');
    }
    @font-face {
        font-family: 'Beausite';
        font-weight: 600 700;
        font-style: normal;
        src: url(data:font/woff;base64,{{ $semibold }}) format('woff');
    }
</style>
