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
      window.__APP_CONFIG__ = {{ Js::from([
        'version' => config('app.version'),
        'docsUrl' => config('app.docs_url'),
        'communityUrl' => config('app.community_url'),
        'sidebarMenu' => config('app.sidebar_menu', []),
        'apiBaseUrl' => config('app.api_url', ''),
        'socialAuth' => [
          'providers' => collect(\App\Models\User\UserSocialLink::SOCIAL_SERVICES)
            ->filter(fn (string $provider) => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")))
            ->map(fn (string $provider) => [
              'key' => $provider,
              'url' => route('auth.social.redirect', ['provider' => $provider]),
              'linkUrl' => route('auth.social.link.redirect', ['provider' => $provider]),
            ])
            ->values(),
        ],
        'posthog' => [
          'key' => config('services.posthog.api_key'),
          'host' => config('services.posthog.settings.host'),
        ],
        'echo' => [
          'broadcaster' => 'reverb',
          'key' => config('reverb.apps.apps.0.key'),
          'wsHost' => config('reverb.apps.apps.0.options.host'),
          'wsPort' => (string) config('reverb.apps.apps.0.options.port'),
          'wssPort' => (string) config('reverb.apps.apps.0.options.port'),
          'forceTLS' => (bool) config('reverb.apps.apps.0.options.useTLS', true),
          'enabledTransports' => ['ws', 'wss'],
        ],
        'ilum' => [
          'baseURL' => config('ilum.base_url'),
        ],
      ]) }};
    </script>
    @vite(['resources/js/main.ts'])
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
