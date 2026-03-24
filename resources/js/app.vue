<script setup lang="ts">
import { useDark } from '@vueuse/core'
import type { Component } from 'vue'
import { RouterView } from 'vue-router'
import { Toaster } from 'vue-sonner'

import { AlertDialogProvider } from '@/composables/useAlertDialog'
import Command from '~/components/Command.vue'
import { useUrlNotifications } from '~/composables/useUrlNotifications'
import DefaultLayout from '~/layouts/default.vue'
import StartLayout from '~/layouts/start.vue'
import UnauthenticatedLayout from '~/layouts/unauthenticated.vue'

useDark()
useUrlNotifications()
const commandOpen = ref(false)


provide('commandOpen', commandOpen)


const route = useRoute()
const { isAuthenticated } = useAuth()


const layoutMap: Record<string, Component> = {
  default: DefaultLayout,
  start: StartLayout,
  unauthenticated: UnauthenticatedLayout,
}


const currentLayout = computed(() => {
  if (route.name === 'invite' && !isAuthenticated.value) {
    return UnauthenticatedLayout
  }

  const layoutName = route.meta.layout as string | undefined
  return layoutName ? layoutMap[layoutName] || DefaultLayout : DefaultLayout
})
</script>

<template>
  <div>
    <AlertDialogProvider>
      <component :is="currentLayout">
        <RouterView />
      </component>
    </AlertDialogProvider>
    <Toaster rich-colors />
    <Command />
  </div>
</template>
