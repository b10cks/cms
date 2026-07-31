declare global {
  interface Window {
    __APP_CONFIG__?: {
      version?: string
      docsUrl?: string
      communityUrl?: string
      apiBaseUrl?: string
      socialAuth?: {
        providers?: Array<{
          key: string
          url: string
          linkUrl?: string
        }>
      }
      sidebarMenu?: Array<{
        label?: string
        icon?: string
        href?: string
      }>
      posthog?: {
        key?: string
        host?: string
      }
      features?: {
        billing?: boolean
        ai?: boolean
        realtime?: boolean
        registration?: boolean
      }
      echo?: {
        broadcaster?: 'reverb'
        key?: string
        wsHost?: string
        wsPort?: string
        wssPort?: string
        forceTLS?: boolean
        enabledTransports?: Array<'ws' | 'wss'>
      } | null
      ilum?: {
        baseURL?: string
      }
    }
  }
}

const appConfig = typeof window !== 'undefined' ? window.__APP_CONFIG__ : undefined

export const runtimeConfig = {
  public: {
    apiBaseUrl: appConfig?.apiBaseUrl || '',
    appVersion: appConfig?.version || '',
    docsUrl: appConfig?.docsUrl || 'https://www.b10cks.com/docs',
    communityUrl: appConfig?.communityUrl || 'https://discord.gg/mdcDktFFcp',
    socialAuth: {
      providers: appConfig?.socialAuth?.providers || [],
    },
    sidebarMenu: appConfig?.sidebarMenu || [],
    posthog: {
      key: appConfig?.posthog?.key,
      host: appConfig?.posthog?.host,
    },
    features: {
      // Absent payload (e.g. dev without a fresh page load) means SaaS defaults.
      billing: appConfig?.features?.billing ?? true,
      ai: appConfig?.features?.ai ?? true,
      // An echo block without a key cannot connect, so it must not enable realtime.
      realtime: appConfig?.features?.realtime ?? Boolean(appConfig?.echo?.key),
      registration: appConfig?.features?.registration ?? true,
    },
    echo: appConfig?.echo?.key
      ? {
          broadcaster: appConfig.echo.broadcaster || 'reverb',
          key: appConfig.echo.key,
          wsHost: appConfig.echo.wsHost,
          wsPort: appConfig.echo.wsPort,
          wssPort: appConfig.echo.wssPort,
          forceTLS: appConfig.echo.forceTLS ?? true,
          // A ws-only deployment can narrow this; both by default.
          enabledTransports: appConfig.echo.enabledTransports ?? ['ws', 'wss'],
        }
      : null,
    ilum: {
      baseURL: appConfig?.ilum?.baseURL || '/ilum',
    },
  },
}
