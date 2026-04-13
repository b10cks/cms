<script setup lang="ts">
import Label from '~/components/ui/form/Label.vue'

import LinkEditor from './LinkEditor.vue'

const value = defineModel<LinkValue | null>()

defineProps<{
  item: LinkSchema & { key: string }
  spaceId: string
}>()
</script>

<template>
  <div class="space-y-3">
    <div class="space-y-1">
      <Label
        :label="item.name || item.key"
        :required="item.required"
      />
      <p
        v-if="item.description"
        class="text-muted-foreground text-xs"
      >
        {{ item.description }}
      </p>
    </div>

    <LinkEditor
      v-model="value"
      :space-id="spaceId"
    />
  </div>
</template>
