<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#ffffff" />
    <link rel="manifest" href="/build/manifest.webmanifest" />
    <title>{{ config('app.name') }}</title>
    <script>
      window.__APP_CONFIG__ = {{ Js::from(app(\App\Services\AppConfigPayload::class)->toArray()) }};
    </script>
    @vite(['resources/js/main.ts'])
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
