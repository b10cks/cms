<script setup lang="ts">
import { Skeleton } from '~/components/ui/skeleton'
</script>

<template>
  <div
    class="space-y-8"
    aria-hidden="true"
  >
    <section
      v-for="section in 2"
      :key="section"
      class="space-y-4"
    >
      <div class="space-y-2">
        <Skeleton class="h-5 w-40" />
        <Skeleton class="h-4 w-80 max-w-full" />
      </div>
      <div class="grid gap-4 lg:grid-cols-2">
        <div
          v-for="field in 4"
          :key="field"
          class="space-y-2"
        >
          <Skeleton class="h-4 w-28" />
          <Skeleton class="h-9 w-full" />
        </div>
      </div>
    </section>
  </div>
</template>
