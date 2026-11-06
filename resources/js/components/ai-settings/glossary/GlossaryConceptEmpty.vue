<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

/** A glossary with no concepts yet: what to do next, or a plain note for a reader. */
defineProps<{
  canEdit: boolean
  templateUrl: (format: 'csv' | 'xlsx') => string
}>()

const emit = defineEmits<{
  create: []
  importSheet: []
}>()
</script>

<template>
  <div class="flex flex-col items-center gap-4 border-t border-border px-5 py-14 text-center">
    <div class="grid max-w-md gap-1">
      <p class="font-semibold text-primary">{{ $t('glossaryImport.editor.empty.title') }}</p>
      <p class="text-sm text-muted">
        {{ canEdit ? $t('glossaryImport.editor.empty.body') : $t('glossaryImport.editor.empty.readOnly') }}
      </p>
    </div>
    <template v-if="canEdit">
      <div class="flex flex-wrap justify-center gap-2">
        <Button
          variant="primary"
          @click="emit('create')"
        >
          <Icon name="lucide:plus" />
          {{ $t('glossaryImport.editor.add') }}
        </Button>
        <Button
          variant="outline"
          @click="emit('importSheet')"
        >
          <Icon name="lucide:upload" />
          {{ $t('glossaryImport.editor.empty.import') }}
        </Button>
      </div>
      <p class="text-sm text-muted">
        {{ $t('glossaryImport.sheet.template') }}
        <a
          :href="templateUrl('xlsx')"
          class="rounded-sm text-primary underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          download
        >XLSX</a>,
        <a
          :href="templateUrl('csv')"
          class="rounded-sm text-primary underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          download
        >CSV</a>
      </p>
    </template>
  </div>
</template>
