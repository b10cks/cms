<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import ServerLocationSelect from '~/components/ServerLocationSelect.vue'
import AccessTokenSettings from '~/components/space-settings/AccessTokenSettings.vue'
import DangerZone from '~/components/space-settings/DangerZone.vue'
import OnboardingSettings from '~/components/space-settings/OnboardingSettings.vue'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { FormField, InputField } from '~/components/ui/form'
import { useFileUpload } from '~/composables/useFileUpload'
import { queryKeys } from '~/composables/useQueryClient'

const route = useRoute()
const { t } = useI18n()
const queryClient = useQueryClient()
const spaceId = route.params.space as string

const { useUpdateSpaceMutation, useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)
const { mutate: updateSpace, isPending: isUpdating } = useUpdateSpaceMutation()

useSeoMeta({
  title: computed(() => t('labels.settings.general.title')),
})

const spaceName = ref('')
const iconInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref(0)
const { upload, isUploading: fileUploadIsUploading } = useFileUpload()

// Keyed on the name alone, so an icon upload refreshing the space keeps an unsaved name edit
watch(
  () => space.value?.name,
  (name) => {
    if (name !== undefined) {
      spaceName.value = name
    }
  },
  { immediate: true }
)

/** The mutation toasts success and failure. */
const handleSave = () => updateSpace({ id: spaceId, payload: { name: spaceName.value } })

const handleIconFile = async (file: File) => {
  if (!file) return
  uploadProgress.value = 0
  try {
    const response = await upload<ApiResponse<SpaceResource>>(file, {
      url: `/mgmt/v1/spaces/${spaceId}/icon`,
      fieldName: 'icon',
      onProgress: (p) => (uploadProgress.value = p),
    })
    if (response?.data?.icon) {
      // The endpoint returns the fresh space: seed the detail cache for the preview
      // and refetch the lists that feed the header, switcher and dashboard.
      queryClient.setQueryData(queryKeys.spaces.detail(spaceId), response.data)
      queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
      toast.success('Icon uploaded successfully')
    } else {
      toast.error('Upload succeeded but no icon returned')
    }
  } catch (e) {
    toast.error((e as Error).message || 'Failed to upload icon')
  }
}

const handleUploadIcon = () => {
  iconInputRef.value?.click()
}

const onIconInputChange = (e: Event) => {
  const files = (e.target as HTMLInputElement).files
  if (files && files[0]) {
    handleIconFile(files[0])
  }
}

const onDropIcon = (e: DragEvent) => {
  e.preventDefault()
  if (e.dataTransfer?.files && e.dataTransfer.files[0]) {
    handleIconFile(e.dataTransfer.files[0])
  }
}

const onDragOverIcon = (e: DragEvent) => {
  e.preventDefault()
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.general.title')"
      :description="$t('labels.settings.general.description')"
    />

    <div class="pt-6 space-y-12 divide-y divide-border">
      <Card
        v-if="space"
        variant="none"
      >
        <CardHeader>
          <CardTitle>{{ $t('labels.settings.space.title') }}</CardTitle>
          <CardDescription>{{ $t('labels.settings.space.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-6">
          <InputField
            v-model="spaceName"
            :label="$t('labels.settings.space.name')"
            :placeholder="$t('labels.settings.space.namePlaceholder')"
            :description="$t('labels.settings.space.nameDescription')"
            name="space-name"
            required
          />

          <div class="space-y-2">
            <FormField
              name="space-icon"
              :label="$t('labels.settings.space.icon')"
              :description="$t('labels.settings.space.iconDescription')"
            >
              <div
                class="flex items-center gap-4"
                @drop="onDropIcon"
                @dragover="onDragOverIcon"
              >
                <div
                  v-if="space.icon"
                  role="button"
                  tabindex="0"
                  :aria-label="$t('labels.settings.space.uploadIcon')"
                  class="flex h-16 w-16 cursor-pointer items-center justify-center rounded-sm bg-surface"
                  @click="handleUploadIcon"
                  @keydown.enter.prevent="handleUploadIcon"
                  @keydown.space.prevent="handleUploadIcon"
                >
                  <NuxtImg
                    :src="space.icon"
                    alt="Space icon"
                    class="h-14 w-14"
                  />
                </div>
                <div
                  v-else
                  role="button"
                  tabindex="0"
                  :aria-label="$t('labels.settings.space.uploadIcon')"
                  class="flex h-16 w-16 cursor-pointer items-center justify-center rounded-md border border-dashed border-muted bg-surface"
                  @click="handleUploadIcon"
                  @keydown.enter.prevent="handleUploadIcon"
                  @keydown.space.prevent="handleUploadIcon"
                >
                  <Icon
                    name="lucide:image"
                    class="h-8 w-8 text-muted"
                  />
                </div>
                <input
                  ref="iconInputRef"
                  type="file"
                  accept="image/*"
                  class="hidden"
                  @change="onIconInputChange"
                />
                <span
                  v-if="fileUploadIsUploading"
                  class="ml-2 text-xs text-muted"
                  >{{ uploadProgress }}%</span
                >
              </div>
            </FormField>
          </div>

          <InputField
            :label="$t('labels.settings.space.spaceId')"
            :description="$t('labels.settings.space.spaceIdDescription')"
            name="space-id"
            :model-value="space.id"
            readonly
            :actions="['copy']"
          />

          <ServerLocationSelect
            :model-value="space.settings.region"
            disabled
          />
        </CardContent>
        <CardFooter>
          <Button
            variant="primary"
            :disabled="isUpdating"
            @click="handleSave"
          >
            <Icon
              v-if="isUpdating"
              name="lucide:loader"
              class="animate-spin"
            />
            {{ $t('actions.saveChanges') }}
          </Button>
        </CardFooter>
      </Card>
      <AccessTokenSettings
        v-if="space"
        :space="space"
      />
      <OnboardingSettings
        v-if="space"
        :space="space"
      />
    </div>
    <DangerZone
      v-if="space"
      :space="space"
    />
  </div>
</template>
