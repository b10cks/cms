<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import ValueRenderer from '~/components/content/ValueRenderer.vue'

type ChangeType = 'added' | 'removed' | 'changed'

interface Change {
  type: ChangeType
  path: string
  oldValue?: unknown
  newValue?: unknown
}

interface ChangeStats {
  added: number
  removed: number
  changed: number
}

type FilterType = ChangeType | 'all'

const props = defineProps<{
  changes: Change[]
}>()

const activeFilter = ref<FilterType>('all')

const stats = computed((): ChangeStats => {
  return props.changes.reduce(
    (acc, change) => {
      acc[change.type] = (acc[change.type] || 0) + 1
      return acc
    },
    { added: 0, removed: 0, changed: 0 }
  )
})

const filteredChanges = computed((): Change[] => {
  if (activeFilter.value === 'all') return props.changes
  return props.changes.filter((change) => change.type === activeFilter.value)
})

const formatPath = (path: string): string => {
  return path.replace(/\./g, ' → ')
}

const getChangeClasses = (type: ChangeType): string => {
  const baseClasses = 'border-l-4'

  switch (type) {
    case 'added':
      return `${baseClasses} bg-success/10 border-success`
    case 'removed':
      return `${baseClasses} bg-destructive/10 border-destructive`
    case 'changed':
      return `${baseClasses} bg-info/10 border-info`
    default:
      return `${baseClasses} bg-gray/10 border-gray`
  }
}

const getChangeIcon = (type: ChangeType): string => {
  switch (type) {
    case 'added':
      return 'lucide:plus'
    case 'removed':
      return 'lucide:minus'
    default:
      return 'lucide:edit'
  }
}

const getBadgeClasses = (type: ChangeType): string => {
  switch (type) {
    case 'added':
      return 'bg-success/20 text-success'
    case 'removed':
      return 'bg-destructive/20 text-destructive'
    case 'changed':
      return 'bg-info/20 text-info'
    default:
      return 'bg-gray/20 text-gray-700'
  }
}

const toggleFilter = (filter: ChangeType): void => {
  if (activeFilter.value === filter) {
    activeFilter.value = 'all'
  } else {
    activeFilter.value = filter
  }
}

const getFilterButtonClasses = (filter: ChangeType): string => {
  return activeFilter.value === filter || activeFilter.value === 'all'
    ? ''
    : 'opacity-50 hover:opacity-100'
}
</script>

<template>
  <div class="min-h-0 w-full">
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h2 class="text-secondary-foreground text-xl font-semibold">Changes Overview</h2>
      </div>
      <div class="flex gap-4">
        <button
          v-if="stats.added > 0"
          class="flex cursor-pointer items-center gap-2"
          :class="getFilterButtonClasses('added')"
          @click="toggleFilter('added')"
        >
          <span class="h-3 w-3 rounded-full bg-success" />
          <span class="text-sm font-semibold text-success">{{ stats.added }} added</span>
        </button>
        <button
          v-if="stats.changed > 0"
          class="flex cursor-pointer items-center gap-2"
          :class="getFilterButtonClasses('changed')"
          @click="toggleFilter('changed')"
        >
          <span class="h-3 w-3 rounded-full bg-info" />
          <span class="text-sm font-semibold text-info">{{ stats.changed }} changed</span>
        </button>
        <button
          v-if="stats.removed > 0"
          class="flex cursor-pointer items-center gap-2"
          :class="getFilterButtonClasses('removed')"
          @click="toggleFilter('removed')"
        >
          <span class="h-3 w-3 rounded-full bg-destructive" />
          <span class="text-sm font-semibold text-destructive">{{ stats.removed }} removed</span>
        </button>
      </div>
    </div>

    <div class="space-y-3">
      <div
        v-for="change in filteredChanges"
        :key="change.path"
        :class="getChangeClasses(change.type)"
        class="rounded-lg border-l-4 px-4 py-3 transition-all duration-200 hover:shadow-sm"
      >
        <div class="mb-2 flex items-start justify-between">
          <div class="flex items-center gap-2">
            <Icon
              :name="getChangeIcon(change.type)"
              class="h-4 w-4 shrink-0"
            />
            <div class="font-mono text-sm">
              {{ formatPath(change.path) }}
            </div>
          </div>
          <span
            :class="getBadgeClasses(change.type)"
            class="rounded-full px-2 py-1 text-xs font-semibold"
          >
            {{ change.type.toUpperCase() }}
          </span>
        </div>
        <div class="ml-6">
          <div
            v-if="change.type === 'added'"
            class="space-y-1"
          >
            <div class="text-xs font-semibold tracking-wide uppercase">New Value</div>
            <div class="rounded border border-success/20 bg-success/5 px-3 py-2 font-mono text-sm">
              <ValueRenderer :value="change.newValue" />
            </div>
          </div>
          <div
            v-else-if="change.type === 'removed'"
            class="space-y-1"
          >
            <div class="text-xs font-semibold tracking-wide uppercase">Removed Value</div>
            <div
              class="rounded border border-destructive/20 bg-destructive/5 px-3 py-2 font-mono text-sm"
            >
              <ValueRenderer :value="change.oldValue" />
            </div>
          </div>
          <div
            v-else-if="change.type === 'changed'"
            class="grid grid-cols-2 space-x-3"
          >
            <div class="space-y-1">
              <div class="text-xs font-semibold tracking-wide uppercase">Previous Value</div>
              <div
                class="rounded border border-destructive/20 bg-destructive/5 px-3 py-2 font-mono text-sm"
              >
                <ValueRenderer :value="change.oldValue" />
              </div>
            </div>
            <div class="space-y-1">
              <div class="text-xs font-semibold tracking-wide uppercase">New Value</div>
              <div
                class="rounded border border-success/20 bg-success/5 px-3 py-2 font-mono text-sm"
              >
                <ValueRenderer :value="change.newValue" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
