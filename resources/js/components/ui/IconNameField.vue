<script setup lang="ts">
import ColorSelect from '~/components/ui/ColorSelect.vue'
import { FormField } from '~/components/ui/form'
import IconGrid from '~/components/ui/IconGrid.vue'
import { Input } from '~/components/ui/input'

const props = defineProps<{
  modelValue: {
    icon?: string | null
    color?: string | null
    name?: string | null
  }
  disabled?: boolean
  placeholder?: string
  label?: string
  name?: string
}>()

const emit = defineEmits<{
  submit: []
  cancel: []
  'update:modelValue': [unknown]
  'update:name': [unknown]
  'update:color': [unknown]
  'update:icon': [unknown]
}>()

const localValue = ref({ ...props.modelValue })

watch(
  () => props.modelValue,
  (val) => {
    localValue.value = { ...val }
  },
  { deep: true }
)

const update = (key: keyof typeof localValue.value, value: unknown) => {
  localValue.value[key] = value
  emit('update:modelValue', { ...localValue.value })
  emit(`update:${key}`, value)
}
</script>

<template>
  <FormField
    :label="label"
    :name="name"
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
