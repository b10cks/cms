/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare module '*.svg?component' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent
  export default component
}

import type { Ref, ComputedRef } from 'vue'
import type { Router, RouteLocationNormalizedLoaded } from 'vue-router'

declare global {
  function ref<T>(value: T): Ref<T>
  function computed<T>(getter: () => T): ComputedRef<T>
  function reactive<T extends object>(target: T): T
  function watch<T extends ReadonlyArray<unknown> | object>(
    source: T,
    callback: (value: T, oldValue: T) => void,
    options?: { immediate?: boolean; deep?: boolean }
  ): void
  function watchEffect(effect: () => void): void
  function onMounted(hook: () => void): void
  function onUnmounted(hook: () => void): void
  function provide<T>(key: string | symbol, value: T): void
  function inject<T>(key: string | symbol): T | undefined
  function readonly<T>(object: T): Readonly<T>
  function unref<T>(ref: T | Ref<T>): T
  function nextTick(callback?: () => void): Promise<void>
  function useRoute(): RouteLocationNormalizedLoaded
  function useRouter(): Router
}
