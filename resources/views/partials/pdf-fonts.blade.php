@php
    // Inlined so headless Chrome renders the PDF without any network access.
    $fontDir = resource_path('fonts');
    $b64 = fn (string $file) => base64_encode(file_get_contents($fontDir.'/'.$file));
@endphp
<style>
    @font-face {
        font-family: 'Beausite';
        font-weight: 400;
        font-style: normal;
        src: url(data:font/woff;base64,{{ $b64('BeausiteClassicWeb-Clear.woff') }}) format('woff');
    }
    @font-face {
        font-family: 'Beausite';
        font-weight: 600 700;
        font-style: normal;
        src: url(data:font/woff;base64,{{ $b64('BeausiteClassicWeb-Semibold.woff') }}) format('woff');
    }
    @font-face {
        font-family: 'Iosevka Aile';
        font-weight: 400;
        font-style: normal;
        src: url(data:font/woff2;base64,{{ $b64('IosevkaAile-Regular.woff2') }}) format('woff2');
    }
    @font-face {
        font-family: 'Iosevka Aile';
        font-weight: 600 700;
        font-style: normal;
        src: url(data:font/woff2;base64,{{ $b64('IosevkaAile-Semibold.woff2') }}) format('woff2');
    }
</style>
