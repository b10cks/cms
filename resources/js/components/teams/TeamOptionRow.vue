<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import IconName from '~/components/ui/IconName.vue'

withDefaults(
  defineProps<{
    name: string
    icon?: string | null
    color?: string | null
    type?: string | null
    /** Nesting level; each one indents the row by 16px. */
    depth?: number
    /** Trigger layout: no indent, badge sits next to the name. */
    compact?: boolean
  }>(),
  {
    icon: null,
    color: null,
    type: null,
    depth: 0,
    compact: false,
  }
)
</script>

<template>
  <div
    v-if="compact"
    class="flex min-w-0 items-center gap-2 pr-2"
  >
    <IconName
      :name="name"
      :color="color"
      :icon="icon ?? 'users'"
    />
    <Badge
      v-if="type"
      variant="surface"
      size="sm"
    >
      {{ type }}
    </Badge>
  </div>

  <div
    v-else
    class="flex w-full items-center justify-between gap-2"
  >
    <div
      class="flex min-w-0 items-center gap-2"
      :style="{ paddingLeft: `${depth * 16}px` }"
    >
      <IconName
        :name="name"
        :color="color"
        :icon="icon ?? 'users'"
      />
    </div>
    <Badge
      v-if="type"
      variant="surface"
      size="sm"
    >
      {{ type }}
    </Badge>
  </div>
</template>
