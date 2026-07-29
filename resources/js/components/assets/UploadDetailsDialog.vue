<script setup lang="ts">
import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import Icon from '~/components/Icon.vue'
import { Alert, AlertDescription } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogHeaderCombined,
} from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'

const { formatFileSize, formatDateTime } = useFormat()
const { getFileIcon } = useFileUtils()

const props = defineProps<{
  file: UploadFile
  folderId?: string | null
  spaceId: string
  open: boolean
  onReplace: () => void
}>()

const emit = defineEmits(['update:open', 'update:file'])

const {
  ensureAssetFieldData,
  getEffectiveFields,
  getFieldValue,
  getMissingRequiredFields,
  isFieldRequiredForLanguage,
  languageTabs,
  setFieldValue,
} = useAssetRequirements(props.spaceId)

const localFile = ref<UploadFile>(structuredClone(props.file))
const selectedLanguage = ref('_default')
const effectiveFields = computed(() => {
  return getEffectiveFields({
    folderId: props.folderId ?? localFile.value.folder_id ?? null,
  })
})

watch(
  () => props.file,
  (file) => {
    localFile.value = structuredClone(file)
    ensureAssetFieldData(localFile.value)
    selectedLanguage.value = '_default'
  },
  { immediate: true, deep: true }
)

const missingRequiredFields = computed(() =>
  getMissingRequiredFields(localFile.value, props.folderId ?? localFile.value.folder_id ?? null)
)

const handleFinish = () => {
  emit('update:file', structuredClone(localFile.value))
  emit('update:open', false)
}

const onOpenChange = (open: boolean) => {
  emit('update:open', open)
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogContent class="sm:max-w-4xl">
      <DialogHeaderCombined :title="$t('labels.assets.uploadDetails')" />
      <DialogHeader />

      <Alert
        v-if="missingRequiredFields.length"
        icon="lucide:triangle-alert"
        color="warning"
      >
        <AlertDescription class="flex items-start justify-between gap-3">
          <span>{{ $t('labels.assets.uploadRequirementsWarning') }}</span>
          <AssetComplianceIndicator
            :issues="missingRequiredFields"
            severity="warning"
          />
        </AlertDescription>
      </Alert>

      <div class="grid gap-6 py-4 md:grid-cols-2">
        <div class="checkerboard flex flex-col items-center justify-center rounded-xl p-4">
          <div
            v-if="localFile.type === 'image' && localFile.preview"
            class="relative h-75 w-full"
          >
            <img
              :src="localFile.preview"
              :alt="localFile.file.name"
              class="h-full w-full object-contain"
            />
          </div>
          <div
            v-else
            class="flex h-75 w-full flex-col items-center justify-center gap-4"
          >
            <Icon
              :name="getFileIcon(localFile.type)"
              class="h-16 w-16"
            />
            <div class="text-center">
              <p class="font-semibold">{{ localFile.file.name }}</p>
              <p class="text-sm text-muted">{{ formatFileSize(localFile.file.size) }}</p>
            </div>
          </div>
        </div>

        <div class="space-y-4">
          <dl class="grid grid-cols-2 gap-2 rounded-lg bg-surface p-3 text-sm">
            <dt class="font-semibold">{{ $t('labels.content.name') }}:</dt>
            <dd>{{ localFile.file.name }}</dd>
            <dt class="font-semibold">{{ $t('labels.content.type') }}:</dt>
            <dd>{{ localFile.file.type || $t('labels.assets.unknown') }}</dd>
            <dt class="font-semibold">{{ $t('labels.assets.size') }}:</dt>
            <dd>{{ formatFileSize(localFile.file.size) }}</dd>
            <dt class="font-semibold">{{ $t('labels.assets.lastModified') }}:</dt>
            <dd>{{ formatDateTime(localFile.file.lastModified) }}</dd>
          </dl>

          <div
            v-if="effectiveFields.length && languageTabs.length > 1"
            class="space-y-3"
          >
            <Tabs
              :model-value="selectedLanguage"
              @update:model-value="selectedLanguage = String($event)"
            >
              <TabsList class="w-full">
                <TabsTrigger
                  v-for="language in languageTabs"
                  :key="language.code"
                  :value="language.code"
                >
                  {{ language.name }}
                </TabsTrigger>
              </TabsList>
            </Tabs>

            <InputField
              v-for="field in effectiveFields"
              :key="`${selectedLanguage}-${field.key}`"
              :model-value="getFieldValue(localFile, field.key, selectedLanguage)"
              :label="field.label"
              :name="`${field.key}-${selectedLanguage}`"
              :required="isFieldRequiredForLanguage(field, selectedLanguage)"
              @update:model-value="
                (value: string | number) =>
                  setFieldValue(localFile, field.key, selectedLanguage, String(value))
              "
            />
          </div>

          <div
            v-else-if="effectiveFields.length"
            class="space-y-3"
          >
            <InputField
              v-for="field in effectiveFields"
              :key="field.key"
              :model-value="getFieldValue(localFile, field.key, '_default')"
              :label="field.label"
              :name="field.key"
              :required="isFieldRequiredForLanguage(field, '_default')"
              @update:model-value="
                (value: string | number) =>
                  setFieldValue(localFile, field.key, '_default', String(value))
              "
            />
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="onOpenChange(false)"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          variant="outline"
          @click="onReplace"
        >
          {{ $t('labels.assets.replaceMedia') }}
        </Button>
        <Button @click="handleFinish">{{ $t('labels.assets.finish') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
