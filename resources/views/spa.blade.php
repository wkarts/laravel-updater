<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ $__updaterBootstrap['context']['csrfToken'] ?? '' }}">
    <title>{{ $__updaterBootstrap['context']['branding']['app_name_full'] ?? 'Updater Manager' }}</title>
    @if(!empty($__updaterBootstrap['context']['branding']['favicon_url']))
    <link rel="icon" href="{{ $__updaterBootstrap['context']['branding']['favicon_url'] }}">
    @endif
    @foreach(($__updaterAssets['css'] ?? []) as $stylesheet)
    <link rel="stylesheet" href="{{ $stylesheet }}">
    @endforeach
</head>
<body>
    <div id="updater-app"></div>
    <script type="application/json" id="updater-bootstrap">{!! json_encode($__updaterBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @if(!empty($__updaterAssets['js']))
    <script type="module" src="{{ $__updaterAssets['js'] }}"></script>
    @endif
</body>
</html>
