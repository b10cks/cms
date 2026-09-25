import type { PostHog } from 'posthog-js'
import type { App } from 'vue'

import { runtimeConfig } from '~/lib/runtime-config'

let posthog: PostHog | undefined
let unavailable = false
const pending: Array<(client: PostHog) => void> = []

function withPosthog(action: (client: PostHog) => void) {
  if (!runtimeConfig.public.posthog.key || unavailable) return
  if (posthog) action(posthog)
  else pending.push(action)
}

const authClient = {
  identify(...args: Parameters<PostHog['identify']>) {
    withPosthog((client) => client.identify(...args))
  },
  reset(...args: Parameters<PostHog['reset']>) {
    withPosthog((client) => client.reset(...args))
  },
}

export async function installPosthog(app: App) {
  const config = runtimeConfig

  if (!config.public.posthog.key) {
    return
  }

  // Vue swallows component errors before they reach window.onerror, so
  // exception autocapture never sees them. Report them here with the
  // component context attached. Not rethrown: that would bubble to
  // window.onerror and capture the same error twice.
  app.config.errorHandler = (err, instance, info) => {
    console.error(err)
    const properties = {
      $exception_source: 'vue',
      vue_component: instance?.$options.name,
      vue_lifecycle_hook: info,
    }
    withPosthog((client) => client.captureException(err, properties))
  }

  // Keep exception capture active while the SDK chunk is downloading.
  const onError = (event: ErrorEvent) => {
    const error = event.error ?? event.message
    withPosthog((client) => client.captureException(error))
  }
  const onRejection = (event: PromiseRejectionEvent) => {
    const error: unknown = event.reason
    withPosthog((client) => client.captureException(error))
  }
  window.addEventListener('error', onError)
  window.addEventListener('unhandledrejection', onRejection)

  try {
    const { default: client } = await import('posthog-js')
    client.init(config.public.posthog.key, {
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
    posthog = client
    for (const action of pending.splice(0)) action(client)
  } catch (error) {
    unavailable = true
    pending.length = 0
    throw error
  } finally {
    window.removeEventListener('error', onError)
    window.removeEventListener('unhandledrejection', onRejection)
  }
}

export function getPosthog() {
  return authClient
}
