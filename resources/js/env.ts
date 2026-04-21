import { runtimeConfig as _runtimeConfig } from './lib/runtime-config'

export const runtimeConfig = _runtimeConfig

export function useRuntimeConfig() {
  return runtimeConfig
}
