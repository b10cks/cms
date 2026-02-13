<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

withDefaults(
  defineProps<{
    header: CleanTranslation
    description?: CleanTranslation
  }>(),
  {
    description: null,
  }
)
</script>

<template>
  <header class="grid gap-2 pt-8">
    <slot name="before-header" />
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-primary">{{ header }}</h1>
        <slot>
          <p v-if="description">
            {{ description }}
          </p>
        </slot>
      </div>
      <slot
        name="actions"
        class="ml-auto flex items-center"
      />
    </div>
  </header>
</template>
