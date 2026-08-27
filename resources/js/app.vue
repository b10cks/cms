<script setup lang="ts">
import { useDark } from '@vueuse/core'
import type { Component } from 'vue'
import { RouterView } from 'vue-router'
import { Toaster } from 'vue-sonner'

import { AlertDialogProvider } from '@/composables/useAlertDialog'
import UploadBatchPanel from '~/components/assets/UploadBatchPanel.vue'
import Command from '~/components/Command.vue'
import KeyboardShortcutsDialog from '~/components/KeyboardShortcutsDialog.vue'
import { useAssetUploadBatch } from '~/composables/useAssetUploadBatch'
import { useUrlNotifications } from '~/composables/useUrlNotifications'
import DefaultLayout from '~/layouts/default.vue'
import ShareLayout from '~/layouts/share.vue'
import StartLayout from '~/layouts/start.vue'
import UnauthenticatedLayout from '~/layouts/unauthenticated.vue'

useDark()
useUrlNotifications()
const commandOpen = ref(false)

provide('commandOpen', commandOpen)

const route = useRoute()
const { isAuthenticated, user } = useAuth()
const { reset: resetUploadBatch } = useAssetUploadBatch()

// The batch is module-scope state that outlives every route, so the session
// ending has to take it with it. Otherwise the next account on this browser
// sees the previous one's filenames and can retry their uploads.
watch(
  () => user.value?.id ?? null,
  (id, previousId) => {
    if (previousId && id !== previousId) {
      resetUploadBatch()
    }
  }
)

const layoutMap: Record<string, Component> = {
  default: DefaultLayout,
  share: ShareLayout,
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
    <UploadBatchPanel v-if="isAuthenticated" />
    <Command />
    <KeyboardShortcutsDialog />
  </div>
</template>
