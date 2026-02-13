<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { computed, ref, watch } from 'vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'
import ArrayInputField from '~/components/ui/form/ArrayInputField.vue'
import { useDataSources } from '~/composables/useDataSources'
import type {
  CreateDataSourcePayload,
  DataSourceResource,
  UpdateDataSourcePayload,
} from '~/types/data-sources'

interface DimensionItem {
  label: string
  key: string
}

const props = defineProps<{
  open: boolean
  dataSource: DataSourceResource | null
}>()

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'close' | 'refresh'): void
}>()

const route = useRoute()
const spaceId = computed(() => route.params.space as string)

const { useCreateDataSourceMutation, useUpdateDataSourceMutation } = useDataSources(spaceId)
const { mutate: createDataSource, isPending: isCreating } = useCreateDataSourceMutation()
const { mutate: updateDataSource, isPending: isUpdating } = useUpdateDataSourceMutation()

const formData = ref<
  (CreateDataSourcePayload | UpdateDataSourcePayload) & { dimensions: DimensionItem[] }
>({
  name: '',
  slug: '',
  description: '',
  dimensions: [],
  settings: {
    cache_ttl: 3600,
    dimensions_translatable: false,
  },
  is_active: true,
})

const dimensionColumns = [
  {
    key: 'label',
    label: 'Display Name',
    type: 'text' as const,
    placeholder: 'e.g., Category Name',
    required: true,
    editable: true,
    creatable: true,
    validate: (value: string) => value.trim().length > 0,
  },
  {
    key: 'key',
    label: 'Key',
    type: 'text' as const,
    placeholder: 'e.g., category_name',
    required: true,
    editable: false,
    creatable: true,
    validate: (value: string) => /^[a-zA-Z0-9_-]+$/.test(value),
    transform: (value: string) => value.toLowerCase().replace(/[^a-zA-Z0-9_-]/g, ''),
  },
  {
    key: 'required',
    label: 'Required',
    type: 'checkbox' as const,
  },
]

const isEditing = computed(() => !!props.dataSource)
const isProcessing = computed(() => isCreating.value || isUpdating.value)

watch(
  () => props.dataSource,
  (newDataSource) => {
    if (newDataSource) {
      formData.value = {
        name: newDataSource.name,
        slug: newDataSource.slug,
        description: newDataSource.description,
        dimensions: newDataSource.dimensions,
        settings: {
          cache_ttl: newDataSource.settings?.cache_ttl || 3600,
          dimensions_translatable:
            (newDataSource.settings as any)?.dimensions_translatable || false,
          default_dimension_locale: (newDataSource.settings as any)?.default_dimension_locale,
        },
        is_active: newDataSource.is_active,
      }
    } else {
      formData.value = {
        name: '',
        slug: '',
        description: '',
        dimensions: [],
        settings: {
          cache_ttl: 3600,
          dimensions_translatable: false,
        },
        is_active: true,
      }
    }
  },
  { immediate: true }
)

const handleOpenChange = (value: boolean) => {
  emit('update:open', value)
  if (!value) {
    emit('close')
  }
}

const validateDimensions = (dimensions: DimensionItem[]): boolean => {
  if (!dimensions || dimensions.length === 0) return true

  const keys = dimensions.map((d) => d.key)
  const uniqueKeys = new Set(keys)

  return uniqueKeys.size === keys.length
}

const handleSubmit = async () => {
  try {
    if (isEditing.value && props.dataSource) {
      await updateDataSource({
        id: props.dataSource.id,
        payload: formData.value as UpdateDataSourcePayload,
      })
    } else {
      await createDataSource(formData.value as CreateDataSourcePayload)
    }

    handleOpenChange(false)
    emit('refresh')
  } catch (error) {
    console.error('Failed to save data source:', error)
  }
}

const handleNameChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  const name = target.value
  formData.value.name = name
  formData.value.slug = name
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
}

const handleDimensionAdd = (item: Record<string, unknown>) => {
  if (!item.key && item.label) {
    item.key = (item.label as string)
      .toLowerCase()
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '_')
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="sm:max-w-[600px]">
      <DialogHeaderCombined
        :title="
          $t(isEditing ? 'labels.datasets.editDataSources' : 'labels.datasets.createDataSource')
        "
        :description="
          $t(
            isEditing
              ? 'labels.datasets.editDataSourceDescription'
              : 'labels.datasets.createDataSourceDescription'
          )
        "
      />

      <form @submit.prevent="handleSubmit">
        <div class="grid gap-4 py-4">
          <InputField
            v-model="formData.name"
            name="name"
            :label="$t('labels.datasets.fields.name')"
            :autofocus="true"
            required
            :disabled="isProcessing"
            @input="handleNameChange"
          />

          <InputField
            v-model="formData.slug"
            name="slug"
            :label="$t('labels.datasets.fields.slug')"
            required
            :description="$t('labels.datasets.slugDescription', { slug: formData.slug })"
            pattern="^[a-z0-9]+(-[a-z0-9]+)*$"
            :disabled="isProcessing"
          />

          <TextField
            v-model="formData.description"
            :label="$t('labels.datasets.fields.description')"
            :rows="3"
            :disabled="isProcessing"
            name="description"
          />

          <ArrayInputField
            v-model="formData.dimensions"
            name="dimensions"
            :label="$t('labels.datasets.fields.dimensions')"
            :columns="dimensionColumns"
            :disabled="isProcessing"
            :empty-message="$t('labels.datasets.noDimensions')"
            :add-button-text="$t('actions.add')"
            @add="handleDimensionAdd"
          />

          <CheckboxField
            v-model="formData.settings.dimensions_translatable"
            name="dimensions_translatable"
            :label="$t('labels.datasets.fields.dimensionsTranslatable')"
            :description="$t('labels.datasets.fields.dimensionsTranslatableDescription')"
            :disabled="isProcessing"
          />

          <InputField
            v-if="formData.settings.dimensions_translatable"
            v-model="formData.settings.default_dimension_locale"
            name="slug"
            required
            :label="$t('labels.datasets.fields.defaultDimensionLocale')"
            :description="
              $t('labels.datasets.defaultDimensionLocaleDescription', { slug: formData.slug })
            "
            pattern="^[a-z0-9]+(-[a-z0-9]+)*$"
            :disabled="isProcessing"
          />

          <CheckboxField
            v-model="formData.is_active"
            name="is_active"
            :label="$t('labels.datasets.fields.active')"
            :description="$t('labels.datasets.fields.activeDescription')"
            :disabled="isProcessing"
          />

          <InputField
            v-model="formData.settings.cache_ttl"
            :label="$t('labels.datasets.fields.cacheTtl')"
            :description="$t('labels.datasets.fields.cacheTtlDescription')"
            :disabled="isProcessing"
            min="0"
            name="cache_ttl"
            type="number"
          />
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            :disabled="isProcessing"
            @click="handleOpenChange(false)"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :disabled="isProcessing || !validateDimensions(formData.dimensions)"
          >
            <Icon
              v-if="isProcessing"
              name="lucide:loader"
              class="animate-spin"
            />
            {{
              isEditing ? $t('labels.datasets.saveChanges') : $t('labels.datasets.createDataSource')
            }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
