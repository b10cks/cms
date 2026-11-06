<script setup lang="ts">
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbSeparator,
} from '~/components/ui/breadcrumb'

defineProps<{
  breadcrumbs: Array<{
    id: string
    label: string
    block: string
  }>
}>()

// Emit events when navigating to a different item
const emit = defineEmits<{
  (e: 'navigate', itemId: string | null): void
}>()

const navigateTo = (id: string | null) => {
  emit('navigate', id)
}
</script>

<template>
  <Breadcrumb>
    <BreadcrumbList>
      <template
        v-for="crumb in breadcrumbs"
        :key="crumb.id"
      >
        <BreadcrumbItem>
          <button
            type="button"
            class="flex cursor-pointer items-center gap-2 rounded-sm transition-colors duration-100 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            @click="navigateTo(crumb.id)"
          >
            {{ crumb.label }}
          </button>
          <BreadcrumbSeparator />
        </BreadcrumbItem>
      </template>
    </BreadcrumbList>
  </Breadcrumb>
</template>
