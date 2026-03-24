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
            class="flex cursor-pointer items-center gap-2 hover:text-gray-200 focus:text-gray-200 focus:outline-none"
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
