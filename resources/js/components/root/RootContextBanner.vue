<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { RouterLink } from 'vue-router'

import Icon from '~/components/Icon.vue'
import ElevateDialog from '~/components/root/ElevateDialog.vue'
import { Button } from '~/components/ui/button'
import { useRootAccess } from '~/composables/useRootAccess'
import { cn } from '~/lib/utils'
import type { AuthorizationPayload } from '~/types/authorization'

/**
 * The root context banner under the app header: neutral while root views a team
 * or space they are no member of, warning while elevated, AI purple while an
 * operator is signed in as someone else. Mounted once by AppHeader.
 */
const route = useRoute()
const { user } = useAuth()
const { formatTime } = useFormat()
const { useAuthorizationQuery } = useAuthorization()
const { useEndElevationMutation, useStopImpersonatingMutation } = useRootAccess()
const queryClient = useQueryClient()

const params = computed(() => {
  const space = route.params.space
  const team = route.params.team
  if (typeof space === 'string' && space) return { space_id: space }
  if (typeof team === 'string' && team) return { team_id: team }
  return {}
})

const { data: authorization } = useAuthorizationQuery(params)
const endElevation = useEndElevationMutation()
const stopImpersonating = useStopImpersonatingMutation()
const elevateOpen = ref(false)

const isMember = (payload: AuthorizationPayload) => {
  if (payload.space) {
    return payload.space.team_role_keys.length > 0 || !!payload.space.space_role_key
  }
  return (payload.team?.role_keys.length ?? 0) > 0
}

const mode = computed<'impersonating' | 'elevated' | 'viewing' | null>(() => {
  const payload = authorization.value
  if (!payload) return null
  if (payload.impersonation) return 'impersonating'
  if (!payload.is_root) return null
  if (payload.is_elevated) return 'elevated'
  return (payload.team || payload.space) && !isMember(payload) ? 'viewing' : null
})

const contextLabel = computed(() => (params.value.space_id ? 'space' : 'team'))

/** The server lets an elevation or impersonation lapse on its own; the UI follows at the same moment. */
const lapseAt = computed(() => {
  const payload = authorization.value
  return payload?.impersonation?.expires_at ?? (payload?.is_elevated ? payload.elevated_until : null)
})

let lapseTimer: ReturnType<typeof setTimeout> | undefined
watch(
  lapseAt,
  (at) => {
    clearTimeout(lapseTimer)
    if (!at) return
    const delay = Math.max(new Date(at).getTime() - Date.now(), 0) + 1000
    lapseTimer = setTimeout(() => {
      if (authorization.value?.impersonation) {
        stopImpersonating.mutate()
      } else {
        void queryClient.invalidateQueries()
      }
    }, delay)
  },
  { immediate: true }
)
onBeforeUnmount(() => clearTimeout(lapseTimer))

const userName = computed(() => {
  const current = user.value
  if (!current) return ''
  return `${current.firstname ?? ''} ${current.lastname ?? ''}`.trim() || current.email
})
</script>

<template>
  <div
    v-if="mode"
    role="status"
    :class="
      cn(
        'sticky top-14 z-10 flex min-h-11 items-center gap-3 border-b px-4 py-1.5 text-sm',
        mode === 'viewing' && 'border-border bg-surface text-foreground',
        mode === 'elevated' && 'border-warning/40 bg-warning-background/40 text-warning-foreground',
        mode === 'impersonating' && 'border-ai/40 bg-ai-background/30 text-ai-foreground'
      )
    "
  >
    <template v-if="mode === 'viewing'">
      <Icon
        name="lucide:shield"
        class="shrink-0 text-warning"
      />
      <span class="min-w-0 grow truncate">
        {{ $t(`rootAccess.banner.viewing.${contextLabel}`) }}
      </span>
      <Button
        size="sm"
        variant="outline"
        @click="elevateOpen = true"
      >
        {{ $t('rootAccess.banner.elevate') }}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        :as="RouterLink"
        :to="{ name: 'provider-tenants' }"
      >
        {{ $t('rootAccess.banner.backToTenants') }}
      </Button>
    </template>

    <template v-else-if="mode === 'elevated' && authorization">
      <Icon
        name="lucide:shield-alert"
        class="shrink-0"
      />
      <span class="min-w-0 grow truncate">
        <span class="font-semibold">
          {{ $t('rootAccess.banner.elevatedUntil', { time: formatTime(authorization.elevated_until ?? '') }) }}
        </span>
        {{ authorization.elevation_reason }}
      </span>
      <Button
        size="sm"
        variant="outline"
        class="text-inherit"
        :loading="endElevation.isPending.value"
        @click="endElevation.mutate()"
      >
        {{ $t('rootAccess.banner.endNow') }}
      </Button>
    </template>

    <template v-else-if="mode === 'impersonating' && authorization?.impersonation">
      <Icon
        name="lucide:log-in"
        class="shrink-0"
      />
      <span class="min-w-0 grow truncate">
        <span class="font-semibold">
          {{ $t('rootAccess.banner.signedInAs', { name: userName }) }}
        </span>
        {{ $t('rootAccess.banner.endsAt', { time: formatTime(authorization.impersonation.expires_at) }) }}
      </span>
      <Button
        size="sm"
        variant="outline"
        class="text-inherit"
        :loading="stopImpersonating.isPending.value"
        @click="stopImpersonating.mutate()"
      >
        {{ $t('rootAccess.banner.return') }}
      </Button>
    </template>

    <ElevateDialog v-model:open="elevateOpen" />
  </div>
</template>
