// File: components/blocks/DateBlock.vue
<script setup lang="ts">
import { computed } from 'vue'
import { CheckboxField, FormField, InputField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

defineProps<{ value: DateSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { $t } = useI18n()

const formatOptions = computed(() => [
  { value: 'date', label: $t('labels.blocks.fields.date.formats.date') },
  { value: 'time', label: $t('labels.blocks.fields.date.formats.time') },
  { value: 'datetime-local', label: $t('labels.blocks.fields.date.formats.datetime') },
])
</script>

<template>
  <div class="flex flex-col gap-6">
    <FormField
      name="format"
      :label="$t('labels.blocks.fields.date.format')"
      :description="$t('labels.blocks.fields.date.formatDescription')"
    >
      <Select
        :model-value="value.format || 'date'"
        @update:model-value="emit('update:item-value', 'format', $event)"
      >
        <SelectTrigger>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in formatOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <InputField
      :model-value="value.min"
      name="min"
      :label="$t('labels.blocks.fields.date.min')"
      :description="$t('labels.blocks.fields.date.minDescription')"
      :type="value.format || 'date'"
      @update:model-value="emit('update:item-value', 'min', $event)"
    />

    <InputField
      :model-value="value.max"
      name="max"
      :label="$t('labels.blocks.fields.date.max')"
      :description="$t('labels.blocks.fields.date.maxDescription')"
      :type="value.format || 'date'"
      @update:model-value="emit('update:item-value', 'max', $event)"
    />

    <CheckboxField
      :model-value="value.use_current_as_default"
      name="use_current_as_default"
      :label="$t('labels.blocks.fields.date.useCurrentAsDefault')"
      :description="$t('labels.blocks.fields.date.useCurrentAsDefaultDescription')"
      @update:model-value="emit('update:item-value', 'use_current_as_default', $event)"
    />
  </div>
</template>
