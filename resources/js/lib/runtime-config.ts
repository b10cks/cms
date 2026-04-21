declare global {
  interface Window {
    __APP_CONFIG__?: {
      version?: string
      apiBaseUrl?: string
      sidebarMenu?: Array<{
        label?: string
        icon?: string
        href?: string
      }>
      posthog?: {
        key?: string
        host?: string
      }
      echo?: {
        broadcaster?: 'reverb'
        key?: string
        wsHost?: string
        wsPort?: string
        wssPort?: string
        forceTLS?: boolean
        enabledTransports?: Array<'ws' | 'wss'>
      }
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
    sidebarMenu: appConfig?.sidebarMenu || [],
    posthog: {
      key: appConfig?.posthog?.key,
      host: appConfig?.posthog?.host,
    },
    echo: {
      broadcaster: appConfig?.echo?.broadcaster || 'reverb',
      key: appConfig?.echo?.key,
      wsHost: appConfig?.echo?.wsHost,
      wsPort: appConfig?.echo?.wsPort,
      wssPort: appConfig?.echo?.wssPort,
      forceTLS: appConfig?.echo?.forceTLS ?? true,
      enabledTransports: ['ws', 'wss'] as const,
    },
    ilum: {
      baseURL: appConfig?.ilum?.baseURL,
    },
  },
}
