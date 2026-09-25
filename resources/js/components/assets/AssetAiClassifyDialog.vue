<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { CheckboxField, ComboboxField, TextField } from '~/components/ui/form'
import { useAssetAiClassification } from '~/composables/useAssetAiClassification'

const props = withDefaults(
  defineProps<{
    spaceId: string
    /** Selected assets; ignored when scope is `all` */
    assets?: AssetResource[]
    scope?: 'selection' | 'all'
  }>(),
  {
    assets: () => [],
    scope: 'selection',
  }
)

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  queued: []
}>()

const { $t } = useI18n()
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => props.spaceId)
const { isImage, useClassifyAssetsMutation } = useAssetAiClassification(() => props.spaceId)
const { mutateAsync: classify, isPending } = useClassifyAssetsMutation()

const languageOptions = computed(() => {
  const settings = space.value?.settings
  const defaultCode = settings?.default_language ?? 'en'

  return [
    { value: '_default', label: `${$t('labels.assets.defaultLanguage')} (${defaultCode})` },
    ...(settings?.languages ?? []).map((language) => ({
      value: language.code,
      label: language.name,
    })),
  ]
})

const languages = ref<string[]>([])
const overwrite = ref(false)
const altContext = ref('')
const decorative = ref(false)
const highDetail = ref(false)

const imageAssets = computed(() => props.assets.filter(isImage))
const isAll = computed(() => props.scope === 'all')
const isSingleImage = computed(() => !isAll.value && imageAssets.value.length === 1)

const canStart = computed(
  () => languages.value.length > 0 && (isAll.value || imageAssets.value.length > 0)
)

const handleStart = async () => {
  if (!canStart.value) {
    return
  }

  await classify({
    scope: props.scope,
    asset_ids: isAll.value ? undefined : imageAssets.value.map((asset) => asset.id),
    languages: languages.value,
    overwrite: overwrite.value,
    alt_context:
      isSingleImage.value && !decorative.value ? altContext.value.trim() || undefined : undefined,
    decorative: isSingleImage.value && decorative.value ? true : undefined,
    high_detail: isSingleImage.value && highDetail.value ? true : undefined,
  })

  emit('queued')
  open.value = false
}

watch(open, (isOpen) => {
  if (isOpen) {
    languages.value = languageOptions.value.map((option) => option.value)
    overwrite.value = false
    altContext.value = ''
    decorative.value = false
    highDetail.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="
          isAll
            ? $t('labels.assets.aiClassify.titleAll')
            : $t('labels.assets.aiClassify.title', { count: imageAssets.length }, imageAssets.length)
        "
        :description="
          isAll
            ? $t('labels.assets.aiClassify.descriptionAll')
            : $t('labels.assets.aiClassify.description')
        "
      />

      <div class="grid gap-4 py-2">
        <p
          v-if="!isAll && imageAssets.length === 0"
          class="text-sm text-warning"
        >
          {{ $t('labels.assets.aiClassify.noImages') }}
        </p>

        <ComboboxField
          v-model="languages"
          name="classification_languages"
          :label="$t('labels.assets.aiClassify.languages')"
          :options="languageOptions"
          multiple
        />

        <CheckboxField
          v-model="overwrite"
          name="classification_overwrite"
          :label="$t('labels.assets.aiClassify.overwrite')"
          :description="$t('labels.assets.aiClassify.overwriteHint')"
        />

        <div
          v-if="isSingleImage"
          class="grid gap-4"
        >
          <TextField
            v-model="altContext"
            name="classification_alt_context"
            :label="$t('labels.assets.aiClassify.altContext')"
            :description="$t('labels.assets.aiClassify.altContextHint')"
            :placeholder="$t('labels.assets.aiClassify.altContextPlaceholder')"
            :disabled="decorative"
            :maxlength="1000"
            :rows="2"
          />

          <CheckboxField
            v-model="decorative"
            name="classification_decorative"
            :label="$t('labels.assets.aiClassify.decorative')"
            :description="$t('labels.assets.aiClassify.decorativeHint')"
          />

          <CheckboxField
            v-model="highDetail"
            name="classification_high_detail"
            :label="$t('labels.assets.aiClassify.highDetail')"
            :description="$t('labels.assets.aiClassify.highDetailHint')"
          />
        </div>

        <p class="text-xs text-muted">
          {{ $t('labels.assets.aiClassify.hint') }}
        </p>
      </div>

      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          @click="open = false"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :loading="isPending"
          :disabled="!canStart"
          @click="handleStart"
        >
          <Icon
            v-if="!isPending"
            name="lucide:sparkles"
          />
          {{ $t('actions.assets.startClassification') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
