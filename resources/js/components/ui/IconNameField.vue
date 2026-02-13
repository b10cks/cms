<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import ColorSelect from '~/components/ui/ColorSelect.vue'
import { FormField } from '~/components/ui/form'
import IconGrid from '~/components/ui/IconGrid.vue'
import { Input } from '~/components/ui/input'

const props = defineProps<{
  modelValue: {
    icon?: string
    color?: string
    name?: string
  }
  disabled?: boolean
  placeholder?: string | CleanTranslation
  label?: string | CleanTranslation
  name?: string | CleanTranslation
}>()

const emit = defineEmits<{
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
    <div class="flex gap-2">
      <IconGrid
        :model-value="localValue.icon"
        :disabled="disabled"
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
        @update:model-value="update('name', $event)"
      />
    </div>
  </FormField>
</template>
