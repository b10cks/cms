<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { resolveDiffComponent, type DiffChange } from '~/components/content/diff/field-diff'
import KeyValueDiff from '~/components/content/diff/KeyValueDiff.vue'
import Icon from '~/components/Icon.vue'
import { diffTextSegments, toDisplayText, type DiffSegment } from '~/utils/text-diff'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
  children?: DiffChange[]
}>()

const itemSlug = (value: unknown): string => {
  return typeof value === 'object' && value !== null
    ? String((value as Record<string, unknown>).block ?? '')
    : ''
}

// Shows the slug itself as a diff, so block-type swaps read as old → new.
const slugSegments = computed((): DiffSegment[] =>
  diffTextSegments(
    itemSlug(props.oldValue ?? props.newValue),
    itemSlug(props.newValue ?? props.oldValue)
  )
)

const rows = computed(() =>
  (props.children ?? []).map((child) => ({
    child,
    component: resolveDiffComponent(child),
    childProps:
      child.fieldType === 'block'
        ? { oldValue: child.oldValue, newValue: child.newValue, children: child.children }
        : { oldValue: child.oldValue, newValue: child.newValue },
  }))
)

const formatPath = (path: string): string => path.replace(/\./g, ' → ')

const fallbackSegments = (child: DiffChange): DiffSegment[] =>
  diffTextSegments(toDisplayText(child.oldValue), toDisplayText(child.newValue))
</script>

<template>
  <div class="space-y-1.5 text-sm">
    <div class="flex items-center gap-1.5">
      <Icon
        name="lucide:box"
        class="text-muted-foreground h-3.5 w-3.5 shrink-0"
      />
      <span class="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">
        <DiffSegments :segments="slugSegments" />
      </span>
    </div>
    <div
      v-for="row in rows"
      :key="`${row.child.type}:${row.child.path}`"
      class="flex items-baseline gap-2"
    >
      <span class="text-muted-foreground w-24 shrink-0 truncate font-mono text-xs">{{
        formatPath(row.child.path)
      }}</span>
      <div class="min-w-0 flex-1">
        <component
          :is="row.component"
          v-if="row.component"
          v-bind="row.childProps"
        />
        <DiffSegments
          v-else
          :segments="fallbackSegments(row.child)"
        />
      </div>
    </div>
    <KeyValueDiff
      v-if="!rows.length"
      :old-value="oldValue"
      :new-value="newValue"
    />
  </div>
</template>
