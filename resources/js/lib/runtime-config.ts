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
    communityUrl: appConfig?.communityUrl || 'https://discord.gg/zAz6sBDpHT',
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
      realtime: appConfig?.features?.realtime ?? Boolean(appConfig?.echo),
    },
    echo: appConfig?.echo
      ? {
          broadcaster: appConfig.echo.broadcaster || 'reverb',
          key: appConfig.echo.key,
          wsHost: appConfig.echo.wsHost,
          wsPort: appConfig.echo.wsPort,
          wssPort: appConfig.echo.wssPort,
          forceTLS: appConfig.echo.forceTLS ?? true,
          enabledTransports: ['ws', 'wss'] as const,
        }
      : null,
    ilum: {
      baseURL: appConfig?.ilum?.baseURL,
    },
  },
}
