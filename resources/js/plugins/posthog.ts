import posthog from 'posthog-js'

import { runtimeConfig } from '~/lib/runtime-config'

export async function installPosthog() {
  const config = runtimeConfig

  if (!config.public.posthog.key) {
    return
  }

  posthog.init(config.public.posthog.key, {
    api_host: config.public.posthog.host || 'https://app.posthog.com',
    capture_pageview: 'history_change',
    capture_pageleave: true,
    mask_all_text: true,
    loaded: (ph) => {
      if (import.meta.env.MODE === 'development') ph.debug()
    },
  })
}

export function getPosthog() {
  return posthog
}
