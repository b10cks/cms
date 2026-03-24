<script setup lang="ts">
import { RouterLink } from 'vue-router'

import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import { useAuthorization } from '~/composables/useAuthorization'
import { accessDeniedMetadataByScope } from '~/lib/access-control'

const route = useRoute()

const scope = computed<'space' | 'team' | 'global'>(() => {
  const value = route.query.scope
  return value === 'space' || value === 'team' ? value : 'global'
})

const spaceId = computed(() => {
  return typeof route.query.space === 'string' ? route.query.space : null
})

const teamId = computed(() => {
  return typeof route.query.team === 'string' ? route.query.team : null
})

const authorizationParams = computed(() => ({
  ...(spaceId.value ? { space_id: spaceId.value } : {}),
  ...(teamId.value ? { team_id: teamId.value } : {}),
}))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(authorizationParams)

const metadata = computed(() => accessDeniedMetadataByScope[scope.value])
const sourcePath = computed(() => {
  return typeof route.query.from === 'string' ? route.query.from : null
})

const canReturnToSpaceHome = computed(() => {
  return !!spaceId.value && access.canAccessRoute('space')
})

const canReturnToTeam = computed(() => {
  return !!teamId.value && access.canAccessRoute('team')
})

const canOpenFallbackSpaceRoute = computed(() => {
  return !!spaceId.value && !!access.firstAllowedRouteForSpace(spaceId.value)
})

const fallbackSpaceRoute = computed(() => {
  if (!spaceId.value) {
    return null
  }

  return access.firstAllowedRouteForSpace(spaceId.value)
})

useSeoMeta({
  title: computed(() => metadata.value.title),
})
</script>

<template>
  <AppHeader />
  <div class="flex min-h-[calc(100vh-3.5rem)] items-center justify-center bg-background px-6 py-12">
    <Card class="w-full max-w-2xl">
      <CardHeader class="space-y-4">
        <div
          class="flex size-12 items-center justify-center rounded-full bg-warning/10 text-warning"
        >
          <Icon
            name="lucide:shield-alert"
            size="24"
          />
        </div>
        <div class="space-y-2">
          <CardTitle>{{ metadata.title }}</CardTitle>
          <CardDescription>{{ metadata.description }}</CardDescription>
        </div>
      </CardHeader>
      <CardContent class="space-y-6">
        <p
          v-if="sourcePath"
          class="text-sm text-muted-foreground"
        >
          {{ $t('labels.accessDenied.from', 'Requested page') }}:
          <span class="font-mono">{{ sourcePath }}</span>
        </p>

        <div class="flex flex-wrap gap-3">
          <Button
            v-if="canReturnToSpaceHome && spaceId"
            :as="RouterLink"
            :to="{ name: 'space', params: { space: spaceId } }"
          >
            {{ $t('actions.accessDenied.spaceHome', 'Back to space home') }}
          </Button>
          <Button
            v-else-if="canOpenFallbackSpaceRoute && fallbackSpaceRoute"
            :as="RouterLink"
            :to="fallbackSpaceRoute"
          >
            {{ $t('actions.accessDenied.spaceFallback', 'Open available space page') }}
          </Button>
          <Button
            v-if="canReturnToTeam && teamId"
            :as="RouterLink"
            :to="{ name: 'team', params: { team: teamId } }"
            variant="outline"
          >
            {{ $t('actions.accessDenied.teamOverview', 'Back to team overview') }}
          </Button>
          <Button
            :as="RouterLink"
            :to="{ name: 'index' }"
            variant="ghost"
          >
            {{ $t('actions.toDashboard') }}
          </Button>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
