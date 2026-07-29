import type { App } from 'vue'

import posthog from 'posthog-js'

import { runtimeConfig } from '~/lib/runtime-config'

export async function installPosthog(app: App) {
  const config = runtimeConfig

  if (!config.public.posthog.key) {
    return
  }

  posthog.init(config.public.posthog.key, {
    api_host: config.public.posthog.host || 'https://app.posthog.com',
    capture_pageview: 'history_change',
    capture_pageleave: true,
    // Autocaptures window.onerror and unhandled promise rejections.
    capture_exceptions: true,
    mask_all_text: true,
    loaded: (ph) => {
      if (import.meta.env.MODE === 'development') ph.debug()
    },
  })

  // Vue swallows component errors before they reach window.onerror, so
  // exception autocapture never sees them. Report them here with the
  // component context attached. Not rethrown: that would bubble to
  // window.onerror and capture the same error twice.
  app.config.errorHandler = (err, instance, info) => {
    console.error(err)
    posthog.captureException(err, {
      $exception_source: 'vue',
      vue_component: instance?.$options.name,
      vue_lifecycle_hook: info,
    })
  }
}

export function getPosthog() {
  return posthog
}
