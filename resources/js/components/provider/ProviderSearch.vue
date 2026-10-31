<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { InputGroup, InputGroupAddon, InputGroupInput } from '~/components/ui/input-group'

defineProps<{ placeholder: string }>()

const model = defineModel<string>({ required: true })
</script>

<template>
  <InputGroup class="w-72 max-w-full">
    <InputGroupAddon>
      <Icon
        name="lucide:search"
        class="text-muted"
      />
    </InputGroupAddon>
    <InputGroupInput
      v-model="model"
      type="search"
      data-shortcut-search
      :placeholder="placeholder"
      :aria-label="placeholder"
    />
  </InputGroup>
</template>
