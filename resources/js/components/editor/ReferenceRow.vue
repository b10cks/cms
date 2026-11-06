<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** One referenced entry: its block icon, name, and site path in muted. */
defineProps<{ entry?: FlatContentMenuItem; path?: string }>()
</script>

<template>
  <div class="flex min-w-0 grow items-center gap-3">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-chip">
      <Icon
        :name="`lucide:${entry?.icon || 'file'}`"
        :style="entry?.color ? { color: entry.color } : undefined"
        :class="entry?.color ? '' : 'text-input-muted'"
      />
    </span>
    <div class="min-w-0 grow">
      <p
        :class="['truncate font-medium', entry ? 'text-primary' : 'text-input-muted']"
        :title="entry?.name"
      >
        {{ entry?.name || $t('labels.references.unknownContent') }}
      </p>
      <p
        v-if="path"
        class="truncate text-sm text-input-muted"
      >
        {{ path }}
      </p>
    </div>
  </div>
</template>
