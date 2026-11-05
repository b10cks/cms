<script setup lang="ts">
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from '~/components/ui/tags-input'

import GlossaryField from './GlossaryField.vue'

/**
 * A list of words as chips. Enter, `;` and leaving the field all add what was typed,
 * so a word never gets lost on the way to Save.
 */
defineProps<{
  id: string
  label: string
  hint: string
  max: number
}>()

const model = defineModel<string[]>({ required: true })
</script>

<template>
  <GlossaryField
    :id="id"
    :label="label"
    :hint="hint"
  >
    <template #default="{ describedBy }">
      <TagsInput
        v-model="model"
        add-on-blur
        add-on-paste
        :delimiter="/[;\n]/"
        :max="max"
        class="min-w-0"
      >
        <TagsInputItem
          v-for="value in model"
          :key="value"
          :value="value"
          class="max-w-full"
        >
          <TagsInputItemText class="truncate" />
          <TagsInputItemDelete class="mr-0 size-6 shrink-0 items-center justify-center" />
        </TagsInputItem>
        <TagsInputInput
          :id="id"
          :aria-describedby="describedBy"
          class="min-w-24"
        />
      </TagsInput>
    </template>
  </GlossaryField>
</template>
