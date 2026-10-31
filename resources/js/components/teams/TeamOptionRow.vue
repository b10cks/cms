<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import HighlightedText from '~/components/ui/HighlightedText.vue'

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
    /** Matched characters of the name, from a fuzzy search. */
    highlight?: number[]
  }>(),
  {
    icon: null,
    color: null,
    type: null,
    depth: 0,
    compact: false,
    highlight: undefined,
  }
)
</script>

<template>
  <div
    v-if="compact"
    class="flex min-w-0 items-center gap-2 pr-2"
  >
    <Icon
      :name="`lucide:${icon ?? 'users'}`"
      :style="{ color: color ?? undefined }"
      class="shrink-0"
    />
    <span class="truncate">{{ name }}</span>
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
    class="flex w-full min-w-0 items-center justify-between gap-2"
  >
    <div
      class="flex min-w-0 items-center gap-2"
      :style="{ paddingLeft: `${depth * 16}px` }"
    >
      <Icon
        :name="`lucide:${icon ?? 'users'}`"
        :style="{ color: color ?? undefined }"
        class="shrink-0"
      />
      <span class="truncate">
        <HighlightedText
          :text="name"
          :highlight="highlight"
        />
      </span>
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
