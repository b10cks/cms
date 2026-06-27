<script lang="ts" setup>
import { toast } from 'vue-sonner'

import type { SpaceAiConfig } from '~/api/resources/ai'
import AssetBlock from '~/components/editor/AssetBlock.vue'
import LinkEditor from '~/components/editor/LinkEditor.vue'
import RobotsDirectiveEditor from '~/components/editor/RobotsDirectiveEditor.vue'
import Icon from '~/components/Icon.vue'
import SplitButton from '~/components/ui/button/SplitButton.vue'
import { DropdownMenuItem } from '~/components/ui/dropdown-menu'
import { InputField, TextField } from '~/components/ui/form'
import Label from '~/components/ui/form/Label.vue'
import { useAiConfigs } from '~/composables/useAiModels'
import { parseAiJson } from '~/lib/aiJson'
import { normalizeLanguageIso } from '~/lib/content-i18n'
import type { AssetValue } from '~/types/assets'
import type { ContentResource } from '~/types/contents'

import { Badge } from '../ui/badge'

interface MetaSchema {
  key: string
  name?: string
  description?: string
  required?: boolean
  has_og_tags?: boolean
}

type ContentValue = Partial<
  Pick<ContentResource, 'name' | 'full_slug' | 'content' | 'language_iso'>
>

interface MetaValue {
  title?: string
  description?: string
  canonical?: string | LinkValue | null
  robots?: string
  ogTitle?: string
  ogDescription?: string
  ogImage?: AssetValue
}

const props = defineProps<{
  item: MetaSchema & { key: string }
  modelValue?: unknown
  spaceId: string
  readOnly?: boolean
}>()

const content = inject<Ref<ContentValue>>('content', ref({}))
const { t } = useI18n()
const { streamMetaTags } = useAiMetaTags(toRef(props, 'spaceId'))
const { showAiError } = useAiErrorToast()
const { useAiConfigsQuery } = useAiConfigs(toRef(props, 'spaceId'))
const { data: aiConfigs } = useAiConfigsQuery()

const emit = defineEmits<{
  (e: 'update:model-value', value: unknown): void
}>()

const isLinkValue = (value: unknown): value is LinkValue => {
  return (
    typeof value === 'object' && value !== null && typeof (value as LinkValue).type === 'string'
  )
}

const normalizeOptionalString = (
  value: string | undefined | null,
  options: { trim?: boolean } = {}
) => {
  if (typeof value !== 'string') {
    return undefined
  }

  const trim = options.trim ?? true
  const normalized = trim ? value.trim() : value

  return normalized.trim() === '' ? undefined : normalized
}

const normalizeCanonicalForEditor = (value: MetaValue['canonical']): LinkValue | null => {
  if (!value) {
    return null
  }

  if (typeof value === 'string') {
    const url = value.trim()

    return url
      ? {
          type: 'url',
          url,
        }
      : null
  }

  if (!isLinkValue(value)) {
    return null
  }

  return { ...value }
}

const normalizeCanonicalForSave = (value: LinkValue | null): LinkValue | null => {
  if (!value) {
    return null
  }

  if (value.type === 'url') {
    const url = value.url?.trim()

    return url
      ? {
          ...value,
          url,
        }
      : null
  }

  if (value.type === 'email') {
    const email = normalizeOptionalString(value.email)

    return email
      ? {
          email,
          ...(normalizeOptionalString(value.subject)
            ? { subject: normalizeOptionalString(value.subject) }
            : {}),
          ...(normalizeOptionalString(value.body, { trim: false })
            ? { body: normalizeOptionalString(value.body, { trim: false }) }
            : {}),
          ...(normalizeOptionalString(value.cc) ? { cc: normalizeOptionalString(value.cc) } : {}),
          ...(normalizeOptionalString(value.bcc)
            ? { bcc: normalizeOptionalString(value.bcc) }
            : {}),
          type: 'email',
        }
      : null
  }

  const contentId = normalizeOptionalString(value.content)

  return contentId
    ? {
        content: contentId,
        ...(normalizeOptionalString(value.anchor)
          ? { anchor: normalizeOptionalString(value.anchor) }
          : {}),
        ...(value.target ? { target: value.target } : {}),
        type: 'internal',
      }
    : null
}

const localValue = ref<MetaValue>((props.modelValue as MetaValue) || {})
const isGenerating = ref(false)
const selectedConfigId = ref<string | null>(null)
const canonicalValue = computed(() => normalizeCanonicalForEditor(localValue.value?.canonical))

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

const defaultAiConfig = computed((): SpaceAiConfig | null => {
  return aiConfigs.value?.find((config) => config.is_default) ?? null
})

const selectedAiConfig = computed((): SpaceAiConfig | null => {
  if (!selectedConfigId.value) {
    return null
  }

  return aiConfigs.value?.find((config) => config.id === selectedConfigId.value) ?? null
})

watch(
  () => defaultAiConfig.value,
  (config) => {
    if (config && !selectedConfigId.value) {
      selectedConfigId.value = config.id
    }
  },
  { immediate: true }
)

const currentLanguage = computed((): string | undefined => {
  return normalizeLanguageIso(content.value?.language_iso)
})

const canGenerateWithAI = computed((): boolean => {
  return !!selectedConfigId.value && !!hasContent.value && !props.readOnly && !isGenerating.value
})

const generateMetaWithAI = async (
  configId: string | null = selectedConfigId.value
): Promise<void> => {
  if (!configId) {
    toast.error('Please select an AI configuration first.')
    return
  }
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

  let accumulated = ''

  await streamMetaTags(
    { context: requestData, config_id: configId, language: currentLanguage.value },
    {
      onDelta: (chunk) => {
        accumulated += chunk
      },
      onDone: (streamContent) => {
        const generatedMeta = parseAiJson<{
          title?: string
          description?: string
          ogTitle?: string
          ogDescription?: string
        }>(streamContent || accumulated)

        if (!generatedMeta) {
          showAiError('no_result')
          return
        }

        const newValue = {
          ...localValue.value,
          title: generatedMeta.title ?? localValue.value?.title ?? '',
          description: generatedMeta.description ?? localValue.value?.description ?? '',
          ...(props.item.has_og_tags && {
            ogTitle: generatedMeta.ogTitle ?? localValue.value?.ogTitle ?? '',
            ogDescription: generatedMeta.ogDescription ?? localValue.value?.ogDescription ?? '',
          }),
        }

        localValue.value = newValue
        emit('update:model-value', newValue)

        toast.success(t('composables.aiMeta.success') as string)
      },
      onError: (message, reason) => {
        showAiError(reason, message)
      },
    }
  )

  isGenerating.value = false
}

const hasContent = computed((): boolean => {
  return !!(content.value?.name || content.value?.content)
})

const handleGenerateWithConfig = (configId: string): void => {
  selectedConfigId.value = configId
  void generateMetaWithAI(configId)
}

const updateCanonicalValue = (value: LinkValue | null): void => {
  updateValue('canonical', normalizeCanonicalForSave(value))
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="space-y-1">
      <Label
        :label="item.name || item.key"
        :required="item.required"
      />
      <p
        v-if="item.description"
        class="text-xs text-muted-foreground"
      >
        {{ item.description }}
      </p>
    </div>
    <div class="relative rounded-lg border border-input bg-background p-3">
      <div class="mb-2 text-sm text-muted">
        {{ serpUrl }}
      </div>
      <div class="absolute top-1 right-1">
        <SplitButton
          size="sm"
          :disabled="!canGenerateWithAI"
          :menu-disabled="isGenerating"
          :has-menu="(aiConfigs?.length || 0) > 1"
          :loading="isGenerating"
          :primary-action="() => generateMetaWithAI()"
        >
          <Icon
            v-if="!isGenerating"
            name="lucide:wand-sparkles"
            class="text-ai"
          />
          <span>{{ isGenerating ? 'Generating...' : 'AI Generate' }}</span>
          <template #menu>
            <DropdownMenuItem
              v-for="config in aiConfigs"
              :key="config.id"
              :disabled="isGenerating"
              @select="handleGenerateWithConfig(config.id)"
            >
              <div class="flex items-center gap-2">
                <span class="font-medium">
                  {{ config.name }}
                </span>
                <Badge
                  v-if="config.is_default"
                  size="sm"
                  >Default</Badge
                >
              </div>
            </DropdownMenuItem>
          </template>
        </SplitButton>
      </div>
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
      :disabled="props.readOnly || isGenerating"
      @update:model-value="updateValue('title', $event)"
    />
    <TextField
      v-model="localValue.description"
      :name="item.key + '-description'"
      :label="$t('labels.contents.fields.meta.description')"
      :placeholder="$t('labels.contents.fields.meta.descriptionPlaceholder')"
      :disabled="props.readOnly || isGenerating"
      auto-size
      @update:model-value="updateValue('description', $event)"
    />
    <div class="space-y-3">
      <div class="space-y-1">
        <Label :label="$t('labels.contents.fields.meta.canonical')" />
        <p class="text-xs text-muted-foreground">
          {{ $t('labels.contents.fields.meta.canonicalDescription') }}
        </p>
      </div>
      <LinkEditor
        :model-value="canonicalValue"
        :space-id="spaceId"
        :disabled="props.readOnly || isGenerating"
        @update:model-value="updateCanonicalValue"
      />
    </div>
    <RobotsDirectiveEditor
      :model-value="localValue.robots"
      :name="item.key + '-robots'"
      :label="$t('labels.contents.fields.meta.robots')"
      :tooltip="$t('labels.contents.fields.meta.robotsDescription')"
      :disabled="props.readOnly || isGenerating"
      @update:model-value="updateValue('robots', $event)"
    />
    <template v-if="item.has_og_tags">
      <AssetBlock
        :model-value="localValue.ogImage"
        :space-id="spaceId"
        :read-only="props.readOnly"
        :item="{ name: 'ogImage', key: 'ogImage', type: 'asset', file_types: ['image'] }"
        @update:model-value="updateValue('ogImage', $event)"
      />
      <InputField
        v-model="localValue.ogTitle"
        :name="item.key + '-ogTitle'"
        :label="$t('labels.contents.fields.meta.ogTitle')"
        :placeholder="$t('labels.contents.fields.meta.ogTitlePlaceholder')"
        :disabled="props.readOnly || isGenerating"
        @update:model-value="updateValue('ogTitle', $event)"
      />
      <TextField
        v-model="localValue.ogDescription"
        :name="item.key + '-ogDescription'"
        :label="$t('labels.contents.fields.meta.ogDescription')"
        :placeholder="$t('labels.contents.fields.meta.ogDescriptionPlaceholder')"
        :disabled="props.readOnly || isGenerating"
        auto-size
        @update:model-value="updateValue('ogDescription', $event)"
      />
    </template>
  </div>
</template>
