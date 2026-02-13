import posthog from 'posthog-js'

import { runtimeConfig } from '~/lib/runtime-config'

export async function installPosthog() {
  if (import.meta.env.MODE === 'development') {
    return
  }

  const config = runtimeConfig

  if (!config.public.posthog.key) {
    return
  }

  posthog.init(config.public.posthog.key, {
    api_host: config.public.posthog.host || 'https://app.posthog.com',
    capture_pageview: false,
    capture_pageleave: true,
    loaded: (ph) => {
      if (import.meta.env.MODE === 'development') ph.debug()
    },
  })
}

export function getPosthog() {
  return posthog
}
