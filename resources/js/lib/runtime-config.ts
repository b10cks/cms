declare global {
  interface Window {
    __APP_CONFIG__?: {
      version?: string
      sidebarMenu?: Array<{
        label?: string
        icon?: string
        href?: string
      }>
    }
  }
}

const appConfig = typeof window !== 'undefined' ? window.__APP_CONFIG__ : undefined

export const runtimeConfig = {
  public: {
    apiBaseUrl: import.meta.env.VITE_APP_API_BASE_URL || '',
    appVersion: appConfig?.version || '',
    sidebarMenu: appConfig?.sidebarMenu || [],
    posthog: {
      key: import.meta.env.VITE_APP_POSTHOG_KEY,
      host: import.meta.env.VITE_APP_POSTHOG_HOST,
    },
    echo: {
      broadcaster: 'reverb' as const,
      key: import.meta.env.VITE_APP_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_APP_REVERB_HOST,
      wsPort: import.meta.env.VITE_APP_REVERB_PORT,
      wssPort: import.meta.env.VITE_APP_REVERB_PORT,
      forceTLS: (import.meta.env.VITE_APP_REVERB_SCHEME ?? 'https') === 'https',
      enabledTransports: ['ws', 'wss'] as const,
    },
    ilum: {
      baseURL: import.meta.env.VITE_APP_ILUM_BASE_URL,
    },
  },
}
