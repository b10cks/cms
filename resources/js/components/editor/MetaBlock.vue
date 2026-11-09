<script lang="ts" setup>
import { toast } from 'vue-sonner'

import type { SpaceAiConfig } from '~/api/resources/ai'
import AssetBlock from '~/components/editor/AssetBlock.vue'
import LinkEditor from '~/components/editor/LinkEditor.vue'
import MetaPreview from '~/components/editor/meta/MetaPreview.vue'
import type { MetaPreviewData } from '~/components/editor/meta/types'
import RobotsDirectiveEditor from '~/components/editor/RobotsDirectiveEditor.vue'
import AiConfigMenuItems from '~/components/ai/AiConfigMenuItems.vue'
import Icon from '~/components/Icon.vue'
import SplitButton from '~/components/ui/button/SplitButton.vue'
import { FormField, InputField, TextField } from '~/components/ui/form'
import Label from '~/components/ui/form/Label.vue'
import { TooltipIcon } from '~/components/ui/tooltip'
import { useAiConfigs } from '~/composables/useAiModels'
import { normalizeLanguageIso } from '~/lib/content-i18n'
import { buildContentPath, buildPreviewUrl, resolveEnvironment } from '~/lib/preview-url'
import { fitSerp } from '~/lib/serpMetrics'
import type { ContentResource } from '~/types/contents'


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

interface MetaTags {
  title?: string
  description?: string
  ogTitle?: string
  ogDescription?: string
}

const props = defineProps<{
  item: MetaSchema & { key: string }
  modelValue?: unknown
  spaceId: string
  readOnly?: boolean
}>()

const content = inject<Ref<ContentValue>>('content', ref({}))
const { t } = useI18n()
const metaRun = useAiRun(toRef(props, 'spaceId'))
const aiEnabled = useAiEnabled(() => props.spaceId)
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

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId) as { data: Ref<SpaceResource | null> }
const { settings: userSettings } = useSpaceSettings(props.spaceId)

/** The entry's address on the preview environment the user picked, else its site path. */
const pageUrl = computed((): URL | null => {
  const spaceSettings = space.value?.settings
  const environment = resolveEnvironment(
    userSettings.value.content.environment as SpaceEnvironment | null,
    spaceSettings
  )
  const args = [
    spaceSettings,
    content.value?.language_iso,
    content.value?.full_slug,
    userSettings.value.content.siteLocale,
  ] as const
  const url = buildPreviewUrl(environment?.url, ...args)
  if (url) return new URL(url)

  const path = buildContentPath(...args)
  return path ? new URL(path, 'https://example.com') : null
})

const titleFit = computed(() => fitSerp(serpTitle.value, 'title'))
const descriptionFit = computed(() => fitSerp(serpDescription.value, 'description'))

const previewData = computed(
  (): MetaPreviewData => ({
    url: pageUrl.value,
    siteName: space.value?.name || pageUrl.value?.host || '',
    siteIcon: space.value?.icon ?? null,
    title: serpTitle.value,
    description: serpDescription.value,
    shareTitle: localValue.value?.ogTitle || serpTitle.value,
    shareDescription: localValue.value?.ogDescription || serpDescription.value,
    shareTitleSource: localValue.value?.ogTitle ? 'og' : 'search',
    image: localValue.value?.ogImage?.full_path ?? null,
  })
)

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

  const outcome = await metaRun.start<MetaTags>({
    surface: 'meta-tags',
    skill: 'meta-tags',
    context: { language: currentLanguage.value, source_locale: currentLanguage.value, page: requestData },
    config_id: configId,
  })

  const generatedMeta = outcome.result?.data
  if (outcome.status === 'failed') {
    showAiError(outcome.error?.reason, outcome.error?.message)
  } else if (outcome.status === 'completed' && !generatedMeta) {
    showAiError('no_result')
  } else if (generatedMeta) {
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
  }

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
    <div class="flex min-h-8 items-center gap-2">
      <Label
        :label="item.name || item.key"
        :required="item.required"
      />
      <TooltipIcon v-if="item.description">
        <span class="block max-w-xs whitespace-pre-line">{{ item.description }}</span>
      </TooltipIcon>
      <div
        v-if="aiEnabled"
        class="mr-8 ml-auto"
      >
        <SplitButton
          size="sm"
          variant="ai"
          :disabled="!canGenerateWithAI"
          :menu-disabled="isGenerating"
          :has-menu="(aiConfigs?.length || 0) > 1"
          :loading="isGenerating"
          :primary-action="() => generateMetaWithAI()"
        >
          <Icon
            v-if="!isGenerating"
            name="ai:bot-star"
          />
          <span>{{ isGenerating ? $t('components.metaBlock.generating') : $t('components.metaBlock.generate') }}</span>
          <template #menu>
            <AiConfigMenuItems
              :configs="aiConfigs ?? []"
              :selected-id="aiConfigs?.find((config) => config.is_default)?.id ?? null"
              :disabled="isGenerating"
              @select="handleGenerateWithConfig"
            />
          </template>
        </SplitButton>
      </div>
    </div>
    <MetaPreview :data="previewData" />

    <InputField
      v-model="localValue.title"
      :name="item.key + '-title'"
      :label="$t('labels.contents.fields.meta.title')"
      :placeholder="content?.name || $t('labels.contents.fields.meta.titlePlaceholder')"
      :disabled="props.readOnly || isGenerating"
      @update:model-value="updateValue('title', $event)"
    >
      <template #label-end>
        <span
          :class="['text-sm tabular-nums', titleFit.cut ? 'text-warning-ink' : 'text-muted']"
          :title="$t('components.metaPreview.pixelsHint')"
        >
          {{ $t('components.metaPreview.pixels', { width: Math.round(titleFit.width), limit: titleFit.limit }) }}
        </span>
      </template>
    </InputField>
    <TextField
      v-model="localValue.description"
      :name="item.key + '-description'"
      :label="$t('labels.contents.fields.meta.description')"
      :placeholder="$t('labels.contents.fields.meta.descriptionPlaceholder')"
      :disabled="props.readOnly || isGenerating"
      auto-size
      @update:model-value="updateValue('description', $event)"
    >
      <template #label-end>
        <span
          :class="['text-sm tabular-nums', descriptionFit.cut ? 'text-warning-ink' : 'text-muted']"
          :title="$t('components.metaPreview.pixelsHint')"
        >
          {{ $t('components.metaPreview.pixels', { width: Math.round(descriptionFit.width), limit: descriptionFit.limit }) }}
        </span>
      </template>
    </TextField>
    <FormField
      :name="item.key + '-canonical'"
      :label="$t('labels.contents.fields.meta.canonical')"
      :description="$t('labels.contents.fields.meta.canonicalDescription')"
    >
      <LinkEditor
        :model-value="canonicalValue"
        :space-id="spaceId"
        :disabled="props.readOnly || isGenerating"
        @update:model-value="updateCanonicalValue"
      />
    </FormField>
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
        :item="{ name: $t('labels.contents.fields.meta.ogImage'), key: 'ogImage', type: 'asset', file_types: ['image'] }"
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
