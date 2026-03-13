<script setup lang="ts">
withDefaults(
  defineProps<{
    header: string
    description?: string
  }>(),
  {
    description: null,
  }
)
</script>

<template>
  <header class="grid gap-2 pt-8">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <slot name="before-header" />
        <div>
          <h1 class="text-2xl font-bold text-primary">{{ header }}</h1>
          <slot>
            <p v-if="description">
              {{ description }}
            </p>
          </slot>
        </div>
      </div>
      <slot
        name="actions"
        class="ml-auto flex items-center"
      />
    </div>
  </header>
</template>
