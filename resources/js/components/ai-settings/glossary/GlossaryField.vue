<script setup lang="ts">
import { FormField } from '~/components/ui/form'

/** A sheet field with its one sentence under the label, tied to the control for screen readers. */
const props = defineProps<{
  id: string
  label: string
  hint: string
  error?: string | null
}>()

const hintId = computed(() => `${props.id}-hint`)
</script>

<template>
  <FormField
    :id="id"
    :name="id"
    :label="label"
    :error="error"
    class="min-w-0"
  >
    <template #default="{ 'aria-describedby': describedBy, 'aria-invalid': invalid }">
      <p
        :id="hintId"
        class="-mt-1 text-sm text-muted"
      >
        {{ hint }}
      </p>
      <slot
        :described-by="[hintId, describedBy].filter(Boolean).join(' ')"
        :invalid="invalid"
      />
    </template>
  </FormField>
</template>
