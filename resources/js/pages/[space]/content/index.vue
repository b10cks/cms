<script setup lang="ts">
import { RouterLink } from 'vue-router'

import ContentsIcon from '~/assets/images/contents.svg?component'
import Icon from '~/components/Icon.vue'
import ExportContentTranslationsDialog from '~/components/content/ExportContentTranslationsDialog.vue'
import ImportContentTranslationsDialog from '~/components/content/ImportContentTranslationsDialog.vue'
import { Button } from '~/components/ui/button'
import { getActionAccessRequirement } from '~/lib/access-control'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canAccessCanvas = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('space.canvas'))
)
const canViewContent = computed(() => access.hasAbility('content.view'))
const canManageContent = computed(() => access.hasAbility('content.manage'))

const exportDialogOpen = ref(false)
const importDialogOpen = ref(false)

const { useContentMenuQuery, getRootItems } = useContentMenu(spaceId)
const { data } = useContentMenuQuery(canViewContent)

useSeoMeta({
  title: computed(() => t('labels.contents.title')),
})

const rootItems = computed(() => getRootItems(data.value) || [])
</script>

<template>
  <div class="flex h-full grow bg-background p-6">
    <div class="grow flex justify-center flex-col text-center">
      <ContentsIcon class="mx-auto mb-6 w-32 text-muted" />
      <template v-if="rootItems.length === 0">
        <h3 class="mb-2 text-xl font-bold">{{ $t('labels.contents.emptyTitle') }}</h3>
        <p class="mb-6 text-muted">{{ $t('labels.contents.emptyDescription') }}</p>
      </template>
      <template v-else>
        <h3 class="mb-2 text-xl font-bold">{{ $t('labels.contents.managerTitle') }}</h3>
        <p class="mb-6 text-muted">{{ $t('labels.contents.managerDescription') }}</p>
      </template>
      <div class="flex flex-wrap items-center justify-center gap-2">
        <Button
          v-if="canAccessCanvas"
          :as="RouterLink"
          :to="{ name: 'space-canvas', params: { space: spaceId } }"
          variant="outline"
        >
          <Icon name="lucide:network" />
          {{ $t('labels.contents.canvas.entry') }}
        </Button>
        <Button
          v-if="canViewContent"
          variant="outline"
          @click="exportDialogOpen = true"
        >
          <Icon name="lucide:download" />
          {{ $t('labels.contents.translationExport.entry') }}
        </Button>
        <Button
          v-if="canManageContent"
          variant="outline"
          @click="importDialogOpen = true"
        >
          <Icon name="lucide:upload" />
          {{ $t('labels.contents.translationImport.entry') }}
        </Button>
      </div>
    </div>

    <ExportContentTranslationsDialog
      v-if="canViewContent"
      v-model:open="exportDialogOpen"
      :space-id="spaceId"
    />
    <ImportContentTranslationsDialog
      v-if="canManageContent"
      v-model:open="importDialogOpen"
      :space-id="spaceId"
    />
  </div>
</template>
