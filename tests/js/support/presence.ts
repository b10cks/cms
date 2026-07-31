import { computed, ref } from 'vue'

import type { PresenceUser } from '~/composables/usePresence'

export interface PresenceController {
  users: ReturnType<typeof ref<PresenceUser[]>>
  count: ReturnType<typeof computed<number>>
  isConnected: ReturnType<typeof ref<boolean>>
  isConnecting: ReturnType<typeof ref<boolean>>
  error: ReturnType<typeof ref<Error | null>>
  currentUser: ReturnType<typeof ref<PresenceUser | null>>
  refresh: () => void
  disconnect: () => void
  whisper: (event: string, payload: unknown) => void
  onWhisper: <T>(event: string, callback: (payload: T) => void) => () => void
  /** Deliver a whisper as if a peer had sent it. */
  fire: (event: string, payload: unknown) => void
  /** Every whisper this client sent, in order. */
  sent: Array<{ event: string; payload: unknown }>
  setUsers: (users: PresenceUser[]) => void
}

export const presenceUser = (id: string, name = id): PresenceUser =>
  ({
    id,
    name,
    firstname: name,
    lastname: 'Tester',
    email: `${id}@test`,
    joined_at: '2026-07-29T00:00:00.000Z',
    created_at: '2026-07-29T00:00:00.000Z',
    updated_at: '2026-07-29T00:00:00.000Z',
  }) as unknown as PresenceUser

/**
 * Stands in for `useContentPresence`. The real one owns an Echo websocket and
 * cannot connect in tests — but the collaboration protocol layered above it
 * (whisper payloads, ordering, self-echo suppression, convergence) is exactly
 * what needs testing. This exposes the transport as a controllable seam.
 */
export function createPresenceController(currentUserId = 'me'): PresenceController {
  const users = ref<PresenceUser[]>([presenceUser(currentUserId)])
  const listeners = new Map<string, Array<(payload: unknown) => void>>()
  const sent: Array<{ event: string; payload: unknown }> = []

  return {
    users,
    count: computed(() => users.value.length),
    isConnected: ref(true),
    isConnecting: ref(false),
    error: ref(null),
    currentUser: ref(presenceUser(currentUserId)),
    refresh: () => {},
    disconnect: () => {},
    whisper: (event: string, payload: unknown) => {
      sent.push({ event, payload })
    },
    onWhisper: <T>(event: string, callback: (payload: T) => void) => {
      const list = listeners.get(event) ?? []
      list.push(callback as (payload: unknown) => void)
      listeners.set(event, list)

      return () => {
        listeners.set(
          event,
          (listeners.get(event) ?? []).filter((entry) => entry !== callback)
        )
      }
    },
    fire: (event: string, payload: unknown) => {
      listeners.get(event)?.forEach((callback) => callback(payload))
    },
    sent,
    setUsers: (next: PresenceUser[]) => {
      users.value = next
    },
  } as unknown as PresenceController
}
