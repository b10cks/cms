<script setup lang="ts">
import { computed } from 'vue'

import { Checkbox } from '~/components/ui/checkbox'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Switch } from '~/components/ui/switch'
import { Textarea } from '~/components/ui/textarea'
import type { DataEntryValue, DataSourceShapeField } from '~/types/data-sources'

const props = defineProps<{
  shape: DataSourceShapeField[]
  modelValue: DataEntryValue | undefined
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, unknown>): void
}>()

const value = computed<Record<string, unknown>>(() =>
  typeof props.modelValue === 'object' && props.modelValue !== null ? props.modelValue : {}
)

const legacyValue = computed(() =>
  typeof props.modelValue === 'string' && props.modelValue !== '' ? props.modelValue : null
)

const setField = (key: string, fieldValue: unknown) => {
  emit('update:modelValue', { ...value.value, [key]: fieldValue })
}

const selectedOptions = (key: string): string[] => {
  const current = value.value[key]
  return Array.isArray(current) ? (current as string[]) : []
}

const toggleOption = (key: string, option: string, checked: boolean) => {
  const current = selectedOptions(key).filter((item) => item !== option)
  setField(key, checked ? [...current, option] : current)
}
</script>

<template>
  <div class="space-y-2">
    <p
      v-if="legacyValue"
      class="text-xs text-muted"
      :title="legacyValue"
    >
      {{ $t('labels.datasets.shape.legacyValue', { value: legacyValue }) }}
    </p>
    <div
      v-for="field in shape"
      :key="field.key"
      class="space-y-1"
    >
      <label class="text-xs font-medium text-muted">
        {{ field.name || field.key }}
        <span
          v-if="field.required"
          class="text-destructive"
          >*</span
        >
      </label>

      <Input
        v-if="field.type === 'text'"
        :model-value="String(value[field.key] ?? '')"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v)"
      />

      <Textarea
        v-else-if="field.type === 'textarea'"
        :model-value="String(value[field.key] ?? '')"
        :rows="2"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v)"
      />

      <Input
        v-else-if="field.type === 'number'"
        :model-value="value[field.key] == null ? '' : String(value[field.key])"
        type="number"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v === '' ? null : Number(v))"
      />

      <Switch
        v-else-if="field.type === 'boolean'"
        :model-value="Boolean(value[field.key])"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v)"
      />

      <Input
        v-else-if="field.type === 'date'"
        :model-value="String(value[field.key] ?? '')"
        type="date"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v || null)"
      />

      <Select
        v-else-if="field.type === 'option'"
        :model-value="String(value[field.key] ?? '')"
        :disabled="disabled"
        @update:model-value="(v) => setField(field.key, v)"
      >
        <SelectTrigger>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in field.options"
            :key="option.value"
            :value="option.value"
          >
            {{ option.name }}
          </SelectItem>
        </SelectContent>
      </Select>

      <div
        v-else-if="field.type === 'options'"
        class="flex flex-wrap gap-x-4 gap-y-1"
      >
        <label
          v-for="option in field.options"
          :key="option.value"
          class="flex items-center gap-2 text-sm"
        >
          <Checkbox
            :model-value="selectedOptions(field.key).includes(option.value)"
            :disabled="disabled"
            @update:model-value="(v) => toggleOption(field.key, option.value, v === true)"
          />
          {{ option.name }}
        </label>
      </div>

      <p
        v-if="field.description"
        class="text-xs text-muted"
      >
        {{ field.description }}
      </p>
    </div>
  </div>
</template>
