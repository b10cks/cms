<script setup lang="ts">
import ColorSelect from '~/components/ui/ColorSelect.vue'
import { FormField } from '~/components/ui/form'
import IconGrid from '~/components/ui/IconGrid.vue'
import { Input } from '~/components/ui/input'

interface IconNameValue {
  icon?: string | null
  color?: string | null
  name?: string | null
}

const props = defineProps<{
  modelValue: IconNameValue
  disabled?: boolean
  placeholder?: string
  label?: string
  name?: string
}>()

const emit = defineEmits<{
  submit: []
  cancel: []
  'update:modelValue': [value: IconNameValue]
  'update:name': [value: string | null]
  'update:color': [value: string | null]
  'update:icon': [value: string | null]
}>()

const localValue = ref({ ...props.modelValue })

watch(
  () => props.modelValue,
  (val) => {
    localValue.value = { ...val }
  },
  { deep: true }
)

const update = (key: keyof IconNameValue, value: string | number | null | undefined) => {
  const next = value == null ? null : String(value)
  localValue.value[key] = next
  emit('update:modelValue', { ...localValue.value })
  if (key === 'icon') emit('update:icon', next)
  else if (key === 'color') emit('update:color', next)
  else emit('update:name', next)
}
</script>

<template>
  <FormField
    :label="label"
    :name="name ?? 'icon-name'"
    v-slot="{ id }"
  >
    <div class="flex gap-1">
      <IconGrid
        :model-value="localValue.icon"
        :disabled="disabled"
        :color="localValue.color"
        @update:model-value="update('icon', $event)"
      />
      <ColorSelect
        :model-value="localValue.color"
        :disabled="disabled"
        @update:model-value="update('color', $event)"
      />
      <Input
        :id="id"
        :model-value="localValue.name"
        :disabled="disabled"
        :placeholder="placeholder"
        autofocus
        @update:model-value="update('name', $event)"
        @keydown.enter="$emit('submit')"
        @keydown.esc="$emit('cancel')"
      />
    </div>
  </FormField>
</template>
