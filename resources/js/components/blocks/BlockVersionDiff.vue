<script setup lang="ts">
import BlockType from '~/components/ui/BlockType.vue'
import { Badge } from '~/components/ui/badge'

const props = defineProps<{
  current: BlockResource
  versionData: BlockVersionData
}>()

interface AttributeChange {
  key: string
  from: unknown
  to: unknown
}

interface FieldChange {
  key: string
  kind: 'added' | 'removed' | 'modified'
  name: string
  type: string
  changes: AttributeChange[]
}

const { $t } = useI18n()

const settingKeys = [
  'name',
  'slug',
  'type',
  'description',
  'preview_template',
  'icon',
  'color',
  'folder_id',
  'tags',
] as const

const settingLabels: Record<(typeof settingKeys)[number], string> = {
  name: 'labels.blocks.fields.name',
  slug: 'labels.blocks.fields.slug',
  type: 'labels.blocks.fields.type',
  description: 'labels.blocks.fields.description',
  preview_template: 'labels.blocks.fields.previewTemplate',
  icon: 'labels.blockVersions.diff.icon',
  color: 'labels.blocks.fields.color',
  folder_id: 'labels.blocks.fields.folder',
  tags: 'labels.blocks.fields.tags',
}

const normalize = (value: unknown): unknown => {
  if (value === undefined || value === null || value === '') return null
  return value
}

const isEqual = (a: unknown, b: unknown) =>
  JSON.stringify(normalize(a) ?? null) === JSON.stringify(normalize(b) ?? null)

const formatValue = (value: unknown): string => {
  const normalized = normalize(value)
  if (normalized === null) return '—'
  if (Array.isArray(normalized)) {
    return normalized.every((item) => ['string', 'number'].includes(typeof item))
      ? normalized.join(', ') || '—'
      : truncate(JSON.stringify(normalized))
  }
  if (typeof normalized === 'object') return truncate(JSON.stringify(normalized))
  return String(normalized)
}

const truncate = (value: string, length = 80) =>
  value.length > length ? `${value.slice(0, length)}…` : value

const settingChanges = computed<AttributeChange[]>(() =>
  settingKeys
    .filter(
      (key) =>
        !isEqual(
          props.versionData[key as keyof BlockVersionData],
          props.current[key as keyof BlockResource]
        )
    )
    .map((key) => ({
      key,
      from: props.versionData[key as keyof BlockVersionData],
      to: props.current[key as keyof BlockResource],
    }))
)

const fieldChanges = computed<FieldChange[]>(() => {
  const versionSchema = props.versionData.schema ?? {}
  const currentSchema = props.current.schema ?? {}
  const keys = [...new Set([...Object.keys(versionSchema), ...Object.keys(currentSchema)])]

  return keys.flatMap((key): FieldChange[] => {
    const from = versionSchema[key] as unknown as Record<string, unknown> | undefined
    const to = currentSchema[key] as unknown as Record<string, unknown> | undefined

    const nameOf = (field: Record<string, unknown>) => String(field.name || key)
    const typeOf = (field: Record<string, unknown>) => String(field.type || '')

    if (!from && to) {
      return [{ key, kind: 'added', name: nameOf(to), type: typeOf(to), changes: [] }]
    }

    if (from && !to) {
      return [{ key, kind: 'removed', name: nameOf(from), type: typeOf(from), changes: [] }]
    }

    if (!from || !to || isEqual(from, to)) {
      return []
    }

    const attributeKeys = [...new Set([...Object.keys(from), ...Object.keys(to)])].filter(
      (attribute) => !isEqual(from[attribute], to[attribute])
    )

    return [
      {
        key,
        kind: 'modified',
        name: nameOf(to),
        type: typeOf(to),
        changes: attributeKeys.map((attribute) => ({
          key: attribute,
          from: from[attribute],
          to: to[attribute],
        })),
      },
    ]
  })
})

const layoutChanged = computed(() => {
  const changedFieldKeys = new Set(
    fieldChanges.value.filter((change) => change.kind !== 'modified').map((change) => change.key)
  )

  const pagesOf = (pages: EditorPage[] | null | undefined) =>
    (pages ?? []).map((page) => ({
      header: page.header,
      items: page.items.filter((item) => !changedFieldKeys.has(item)),
    }))

  return !isEqual(pagesOf(props.versionData.editor), pagesOf(props.current.editor))
})

const hasChanges = computed(
  () => settingChanges.value.length > 0 || fieldChanges.value.length > 0 || layoutChanged.value
)

const kindBadge: Record<FieldChange['kind'], { label: string; class: string }> = {
  added: {
    label: 'labels.blockVersions.diff.added',
    class: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
  },
  removed: {
    label: 'labels.blockVersions.diff.removed',
    class: 'bg-red-500/15 text-red-600 dark:text-red-400',
  },
  modified: {
    label: 'labels.blockVersions.diff.modified',
    class: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
  },
}
</script>

<template>
  <div class="flex flex-col gap-4 p-1">
    <p class="text-sm text-muted-foreground">
      {{ $t('labels.blockVersions.diff.hint') }}
    </p>

    <p
      v-if="!hasChanges"
      class="rounded-md bg-surface p-4 text-center text-sm text-muted"
    >
      {{ $t('labels.blockVersions.diff.noChanges') }}
    </p>

    <template v-else>
      <section
        v-if="settingChanges.length"
        class="flex flex-col gap-2"
      >
        <h4 class="text-xs font-medium tracking-wider text-muted uppercase">
          {{ $t('labels.blockVersions.diff.settings') }}
        </h4>
        <div class="flex flex-col gap-1 rounded-md bg-surface p-3">
          <div
            v-for="change in settingChanges"
            :key="change.key"
            class="grid grid-cols-[10rem_1fr] items-baseline gap-2 text-sm"
          >
            <span class="font-medium">{{ $t(settingLabels[change.key as keyof typeof settingLabels]) }}</span>
            <span class="break-all">
              <span class="text-muted line-through">{{ formatValue(change.from) }}</span>
              <span class="mx-2 text-muted">→</span>
              <span>{{ formatValue(change.to) }}</span>
            </span>
          </div>
        </div>
      </section>

      <section
        v-if="fieldChanges.length"
        class="flex flex-col gap-2"
      >
        <h4 class="text-xs font-medium tracking-wider text-muted uppercase">
          {{ $t('labels.blockVersions.diff.fields') }}
        </h4>
        <div
          v-for="change in fieldChanges"
          :key="change.key"
          class="flex flex-col gap-2 rounded-md bg-surface p-3"
        >
          <div class="flex items-center gap-2">
            <Badge
              :class="kindBadge[change.kind].class"
              size="sm"
            >
              {{ $t(kindBadge[change.kind].label) }}
            </Badge>
            <BlockType :type="change.type" />
            <span class="font-medium">{{ change.name }}</span>
            <span class="text-xs text-muted">{{ change.key }}</span>
          </div>
          <div
            v-if="change.changes.length"
            class="flex flex-col gap-1 border-l-2 border-border pl-3"
          >
            <div
              v-for="attribute in change.changes"
              :key="attribute.key"
              class="grid grid-cols-[10rem_1fr] items-baseline gap-2 text-sm"
            >
              <span class="font-mono text-xs">{{ attribute.key }}</span>
              <span class="break-all">
                <span class="text-muted line-through">{{ formatValue(attribute.from) }}</span>
                <span class="mx-2 text-muted">→</span>
                <span>{{ formatValue(attribute.to) }}</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      <section
        v-if="layoutChanged"
        class="flex flex-col gap-2"
      >
        <h4 class="text-xs font-medium tracking-wider text-muted uppercase">
          {{ $t('labels.blockVersions.diff.layout') }}
        </h4>
        <p class="rounded-md bg-surface p-3 text-sm text-muted-foreground">
          {{ $t('labels.blockVersions.diff.layoutChanged') }}
        </p>
      </section>
    </template>
  </div>
</template>
