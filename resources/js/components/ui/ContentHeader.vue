<script setup lang="ts">
import { TooltipIcon } from '~/components/ui/tooltip'

/**
 * A page title with its actions. `description` explains the page behind an info icon by the
 * title, so the page starts with its content; the default slot renders a visible line under the
 * title, for pages whose subtitle is part of the content (onboarding, a status badge).
 */
withDefaults(
  defineProps<{
    header: string
    description?: string
  }>(),
  {
    description: undefined,
  }
)
</script>

<template>
  <header class="grid pt-8">
    <slot name="start" />
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex min-w-0 flex-[1_1_16rem] items-center gap-2">
        <slot name="before-header" />
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="truncate text-2xl font-bold text-primary">{{ header }}</h1>
            <TooltipIcon v-if="description">
              <span class="block max-w-sm">{{ description }}</span>
            </TooltipIcon>
          </div>
          <slot />
        </div>
      </div>
      <slot
        name="actions"
        class="ml-auto flex items-center"
      />
    </div>
  </header>
</template>
