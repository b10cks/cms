<script lang="ts" setup>
import { toast } from 'vue-sonner'
import AssetBlock from '~/components/editor/AssetBlock.vue'
import { Button } from '~/components/ui/button'
import { InputField, TextField } from '~/components/ui/form'
import type { ApiResponse } from '~/types'

interface MetaSchema {
  key: string
  has_og_tags?: boolean
}

interface ContentValue {
  name?: string
  full_slug?: string
  content?: string | Record<string, unknown>
}

interface MetaValue {
  title?: string
  description?: string
  canonical?: string
  robots?: string
  ogTitle?: string
  ogDescription?: string
  ogImage?: AssetValue
}

const props = defineProps<{
  item: MetaSchema & { key: string }
  modelValue?: unknown
  spaceId: string
}>()

const content = inject<Ref<ContentValue>>('content')
const { client: apiClient } = useApiClient()

const emit = defineEmits<{
  (e: 'update:model-value', value: unknown): void
}>()

const localValue = ref<MetaValue>((props.modelValue as MetaValue) || {})
const isGenerating = ref(false)

const updateValue = (key: keyof MetaValue, value: unknown): void => {
  const newValue = {
    ...localValue.value,
    [key]: value,
  }
  localValue.value = newValue
  emit('update:model-value', newValue)
}

watch(
  () => props.modelValue,
  (newValue: unknown) => {
    localValue.value = newValue ? { ...(newValue as MetaValue) } : {}
  },
  { immediate: true, deep: true }
)

const serpTitle = computed((): string => {
  return localValue.value?.title || content.value?.name || ''
})

const serpDescription = computed((): string => {
  return localValue.value?.description || ''
})

const serpUrl = computed((): string => {
  return `https://example.com${content.value?.full_slug || ''}`
})

const truncatedDescription = computed((): string => {
  const desc = serpDescription.value
  if (desc.length <= 155) return desc

  // Find the last space before the 155 character limit
  const truncated = desc.substring(0, 155)
  const lastSpace = truncated.lastIndexOf(' ')

  return lastSpace > 0 ? truncated.substring(0, lastSpace) + '...' : truncated + '...'
})

const truncatedTitle = computed((): string => {
  const title = serpTitle.value
  if (title.length <= 60) return title

  const truncated = title.substring(0, 60)
  const lastSpace = truncated.lastIndexOf(' ')

  return lastSpace > 0 ? truncated.substring(0, lastSpace) + '...' : truncated + '...'
})

const generateMetaWithAI = async (): Promise<void> => {
  try {
    isGenerating.value = true
    const requestData = {
      name: content.value?.name || '',
      slug: content.value?.full_slug || '',
      body:
        typeof content.value?.content === 'string'
          ? content.value.content
          : JSON.stringify(content.value?.content || {}),
      current_meta: {
        title: localValue.value?.title || '',
        description: localValue.value?.description || '',
        ogTitle: localValue.value?.ogTitle || '',
        ogDescription: localValue.value?.ogDescription || '',
      },
    }

    const response = await apiClient.post<
      ApiResponse<{
        title: string
        description: string
        ogTitle: string
        ogDescription: string
      }>
    >('/mgmt/v1/ai/meta-tags', { context: requestData }, { query: { spaceId: props.spaceId } })

    // Update local values and emit changes
    const generatedMeta = response.data
    const newValue = {
      ...localValue.value,
      title: generatedMeta.title,
      description: generatedMeta.description,
      ...(props.item.has_og_tags && {
        ogTitle: generatedMeta.ogTitle,
        ogDescription: generatedMeta.ogDescription,
      }),
    }

    localValue.value = newValue
    emit('update:model-value', newValue)

    toast.success('Meta tags generated successfully!')
  } catch (error: unknown) {
    const errorMessage = error instanceof Error ? error.message : 'Unknown error'
    toast.error(`Meta generation failed: ${errorMessage}`)
  } finally {
    isGenerating.value = false
  }
}

const hasContent = computed((): boolean => {
  return !!(content.value?.name || content.value?.content)
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <h3 class="text-sm font-semibold text-primary">Meta Tags</h3>
    <div class="relative rounded-lg border border-input bg-background p-3">
      <div class="mb-2 text-sm text-muted">
        {{ serpUrl }}
      </div>
      <Button
        size="sm"
        :disabled="isGenerating || !hasContent"
        class="absolute top-1 right-1 flex items-center gap-2"
        @click="generateMetaWithAI"
      >
        <Icon
          v-if="isGenerating"
          name="lucide:loader"
          class="animate-spin text-ai"
        />
        <Icon
          v-else
          name="lucide:sparkles"
          class="text-ai"
        />
        <span>{{ isGenerating ? 'Generating...' : 'AI Generate' }}</span>
      </Button>
      <h3 class="mb-1 cursor-pointer text-lg leading-tight font-semibold text-info">
        {{ truncatedTitle }}
      </h3>
      <p class="text-sm leading-relaxed text-muted">
        {{ truncatedDescription }}
      </p>
      <div class="mt-3 flex gap-4 border-t border-border pt-3 text-xs text-muted">
        <span :class="{ 'text-destructive': serpTitle.length > 60 }">
          Title: {{ serpTitle.length }}/60 chars
        </span>
        <span :class="{ 'text-destructive': serpDescription.length > 155 }">
          Description: {{ serpDescription.length }}/155 chars
        </span>
      </div>
    </div>

    <InputField
      v-model="localValue.title"
      :name="item.key + '-title'"
      :label="$t('labels.contents.fields.meta.title')"
      :placeholder="$t('labels.contents.fields.meta.titlePlaceholder')"
      :disabled="isGenerating"
      @update:model-value="updateValue('title', $event)"
    />
    <TextField
      v-model="localValue.description"
      :name="item.key + '-description'"
      :label="$t('labels.contents.fields.meta.description')"
      :placeholder="$t('labels.contents.fields.meta.descriptionPlaceholder')"
      :disabled="isGenerating"
      auto-size
      @update:model-value="updateValue('description', $event)"
    />
    <InputField
      v-model="localValue.canonical"
      :name="item.key + '-canonical'"
      :label="$t('labels.contents.fields.meta.canonical')"
      :tooltip="$t('labels.contents.fields.meta.canonicalDescription')"
      :disabled="isGenerating"
      @update:model-value="updateValue('canonical', $event)"
    />
    <InputField
      v-model="localValue.robots"
      :name="item.key + '-robots'"
      :label="$t('labels.contents.fields.meta.robots')"
      :tooltip="$t('labels.contents.fields.meta.robotsDescription')"
      :disabled="isGenerating"
      @update:model-value="updateValue('robots', $event)"
    />
    <template v-if="item.has_og_tags">
      <AssetBlock
        :model-value="localValue.ogImage"
        :space-id="spaceId"
        :item="{ name: 'ogImage', key: 'ogImage', type: 'asset', file_types: ['image'] }"
        @update:model-value="updateValue('ogImage', $event)"
      />
      <InputField
        v-model="localValue.ogTitle"
        :name="item.key + '-ogTitle'"
        :label="$t('labels.contents.fields.meta.ogTitle')"
        :placeholder="$t('labels.contents.fields.meta.ogTitlePlaceholder')"
        :disabled="isGenerating"
        @update:model-value="updateValue('ogTitle', $event)"
      />
      <TextField
        v-model="localValue.ogDescription"
        :name="item.key + '-ogDescription'"
        :label="$t('labels.contents.fields.meta.ogDescription')"
        :placeholder="$t('labels.contents.fields.meta.ogDescriptionPlaceholder')"
        :disabled="isGenerating"
        auto-size
        @update:model-value="updateValue('ogDescription', $event)"
      />
    </template>
  </div>
</template>
