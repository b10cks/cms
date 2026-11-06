<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbSeparator,
} from '~/components/ui/breadcrumb'

export interface ContentCrumb {
  id: string
  /** The item's title, or the entry name for the root. */
  label: string
  /** Block name, shown as the tooltip. */
  block: string
}

/**
 * Heading of an item opened through the hash route: a back button to the parent,
 * the ancestors by title, and the item's own title with its block type.
 */
const props = defineProps<{
  breadcrumbs: ContentCrumb[]
  current: { label: string; block: string }
}>()

const emit = defineEmits<{
  (e: 'navigate', itemId: string | null): void
}>()

const { $t } = useI18n()

const parent = computed(() => props.breadcrumbs[props.breadcrumbs.length - 1] ?? null)
</script>

<template>
  <div
    data-editor-heading
    class="flex min-w-0 grow items-center gap-2"
  >
    <button
      v-if="parent"
      type="button"
      class="row-action shrink-0"
      :aria-label="$t('actions.backTo', { name: parent.label })"
      :title="$t('actions.backTo', { name: parent.label })"
      @click="emit('navigate', parent.id)"
    >
      <Icon name="lucide:arrow-left" />
    </button>
    <div class="min-w-0 grow">
      <Breadcrumb>
        <BreadcrumbList class="flex-nowrap gap-1 text-sm text-muted sm:gap-1">
          <template
            v-for="crumb in breadcrumbs"
            :key="crumb.id"
          >
            <BreadcrumbItem class="min-w-0">
              <button
                type="button"
                class="cursor-pointer truncate rounded-sm transition-colors duration-100 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :title="crumb.block"
                @click="emit('navigate', crumb.id)"
              >
                {{ crumb.label }}
              </button>
            </BreadcrumbItem>
            <BreadcrumbSeparator class="shrink-0" />
          </template>
        </BreadcrumbList>
      </Breadcrumb>
      <h2
        class="flex min-w-0 items-baseline gap-2"
        aria-current="page"
      >
        <span class="truncate text-panel font-semibold text-primary">{{ current.label }}</span>
        <span class="shrink-0 text-sm text-muted">{{ current.block }}</span>
      </h2>
    </div>
  </div>
</template>
