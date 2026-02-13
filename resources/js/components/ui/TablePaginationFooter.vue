<script setup lang="ts">
import LaravelPagination from '~/components/ui/pagination/LaravelPagination.vue'
import PerPageSelect from '~/components/PerPageSelect.vue'
import type { LaravelMeta } from '~/types'

const props = defineProps<{
  meta: LaravelMeta
  currentPage: number
  perPage: number
  pageSizeOptions?: number[]
}>()

const emit = defineEmits<{
  (e: 'update:currentPage' | 'update:perPage', value: number): void
}>()

const currentPageProxy = computed({
  get: () => props.currentPage,
  set: (val: number) => emit('update:currentPage', val),
})
const perPageProxy = computed({
  get: () => props.perPage,
  set: (val: number) => emit('update:perPage', val),
})
</script>

<template>
  <div class="flex items-center rounded-lg bg-surface px-2 py-2">
    <div class="pl-2 text-sm font-semibold text-muted">
      {{ meta.total ? $t('labels.showingEntries', meta) : $t('labels.nothingToShow') }}
    </div>
    <LaravelPagination
      v-model="currentPageProxy"
      class="mx-auto"
      :meta="meta"
    />
    <PerPageSelect
      v-model="perPageProxy"
      :options="pageSizeOptions"
      :label="$t('labels.datasets.perPage')"
    />
  </div>
</template>
