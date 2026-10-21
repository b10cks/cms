<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#f0f1f4" data-light="#f0f1f4" data-dark="#0b0b0d" />
    <link rel="manifest" href="/build/manifest.webmanifest" />
    <title>{{ config('app.name') }}</title>
    <style>
      /* The --surface token from main.css, so the page is never white before the stylesheet and app load.
         The theme-color hex values above are the same colours. tests/js/theme-colors.test.ts fails if either drifts. */
      html { background: oklch(95.82% 0.004 271.37); }
      html.dark { background: oklch(15.15% 0.004 285.34); }
    </style>
    <script>
      // Same storage key and values as vueuse's useDark() in app.vue: 'dark' | 'light' | 'auto' (or unset).
      // app.vue keeps the theme-color meta in sync after the in-app toggle.
      (function () {
        var mode = null;
        try { mode = localStorage.getItem('vueuse-color-scheme'); } catch (e) {}
        if (mode === 'dark' || (mode !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
          document.documentElement.classList.add('dark');
          var meta = document.querySelector('meta[name="theme-color"]');
          meta.content = meta.dataset.dark;
        }
      })();
    </script>
    <script>
      window.__APP_CONFIG__ = {{ Js::from(app(\App\Services\AppConfigPayload::class)->toArray()) }};
    </script>
    @vite(['resources/js/main.ts'])
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
