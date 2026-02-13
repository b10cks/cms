import type { Broadcaster } from 'laravel-echo'
import type { App } from 'vue'

import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

import { api } from '~/api'
import { getXsrfHeaders } from '~/lib/csrf'
import { runtimeConfig } from '~/lib/runtime-config'

declare global {
  interface Window {
    Echo: Echo<keyof Broadcaster>
    Pusher: typeof Pusher
  }
}

export function installEcho(_app: App) {
  const echoConfig = runtimeConfig.public.echo
  const apiBaseUrl = runtimeConfig.public.apiBaseUrl || ''

  if (!echoConfig || !echoConfig.broadcaster) {
    console.warn('[Echo] Configuration is missing or invalid')
    return
  }

  const authEndpoint = `${apiBaseUrl}/auth/v1/broadcast`

  const echoOptions = {
    ...echoConfig,
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (
          error: boolean | Error,
          authData?: { auth: string; channel_data?: string }
        ) => void
      ) => {
        api.client
          .ensureCsrfCookie()
          .then(() =>
            fetch(authEndpoint, {
              method: 'POST',
              credentials: 'include',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...getXsrfHeaders(),
              },
              body: JSON.stringify({
                socket_id: socketId,
                channel_name: channel.name,
              }),
            })
          )
          .then(async (response) => {
            if (!response.ok) {
              if (response.status === 401 || response.status === 403) {
                throw new Error(`Authentication failed: ${response.status}`)
              }
              throw new Error(`Auth request failed: ${response.status}`)
            }

            const data = await response.json()

            if (!data.auth) {
              throw new Error('Invalid auth response: missing auth token')
            }

            callback(false, {
              auth: data.auth,
              channel_data: data.channel_data,
            })
          })
          .catch((error) => {
            console.error('[Echo] Authorization failed for channel:', channel.name, error)
            callback(error instanceof Error ? error : new Error('Authorization failed'))
          })
      },
    }),
  }

  window.Pusher = Pusher
  // @ts-expect-error - Echo configuration types are complex and runtime config is dynamic
  window.Echo = new Echo(echoOptions)

  console.log('[Echo] Initialized with Sanctum authorizer')
}
