import { runtimeConfig as _runtimeConfig } from './lib/runtime-config'

export const runtimeConfig = _runtimeConfig

export function useRuntimeConfig() {
  return runtimeConfig
}

declare global {
  interface ImportMetaEnv {
    VITE_APP_API_BASE_URL?: string
    VITE_APP_API_PROXY_URL?: string
    VITE_APP_POSTHOG_KEY?: string
    VITE_APP_POSTHOG_HOST?: string
    VITE_APP_REVERB_APP_KEY?: string
    VITE_APP_REVERB_HOST?: string
    VITE_APP_REVERB_PORT?: string
    VITE_APP_REVERB_SCHEME?: 'http' | 'https'
    VITE_APP_ILUM_BASE_URL?: string
  }
}
