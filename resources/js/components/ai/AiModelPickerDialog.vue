<script setup lang="ts">
import type { AcceptableValue } from 'reka-ui'
import { ListboxContent, ListboxFilter, ListboxGroup, ListboxGroupLabel, ListboxItem, ListboxRoot } from 'reka-ui'

import AiCapabilityIcons from '~/components/ai/AiCapabilityIcons.vue'
import Icon from '~/components/Icon.vue'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '~/components/ui/dialog'
import { Skeleton } from '~/components/ui/skeleton'
import type { AiModel } from '~/composables/useAiModels'
import { useAiModels, useAiSettings } from '~/composables/useAiModels'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import { modelDisplayName, modelIcon, modelVendor, vendorIcon } from '~/lib/modelIcons'

/**
 * Picks a model from the space's catalogue: providers with counts on the left,
 * fuzzy search and capability filters on the right. Searching looks across all
 * providers. The star favourites a model for the whole space.
 */
const props = defineProps<{
  spaceId: string
  /** `driver:model` of the current pick */
  selected: string | null
}>()

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ select: [model: AiModel] }>()

const { t } = useI18n()
const { useModelsQuery } = useAiModels(() => props.spaceId)
const { toggleFavourite } = useAiSettings(() => props.spaceId)
const { data: groupedModels, isLoading, error, refetch } = useModelsQuery()

type Capability = 'supports_reasoning' | 'supports_vision' | 'supports_tools'
const CAPABILITIES: Array<{ key: Capability; icon: string; label: string }> = [
  { key: 'supports_reasoning', icon: 'lucide:brain', label: 'reasoning' },
  { key: 'supports_vision', icon: 'lucide:eye', label: 'vision' },
  { key: 'supports_tools', icon: 'lucide:wrench', label: 'tools' },
]

interface Entry {
  model: AiModel
  label: string
  vendor: { key: string; label: string }
  name: ReturnType<typeof prepareFuzzyTarget>
  id: ReturnType<typeof prepareFuzzyTarget>
}

const entries = computed<Entry[]>(() =>
  Object.values(groupedModels.value ?? {})
    .flat()
    .map((model) => ({
      model,
      label: modelDisplayName(model.name),
      vendor: modelVendor(model.driver, model.id),
      name: prepareFuzzyTarget(modelDisplayName(model.name)),
      id: prepareFuzzyTarget(model.id),
    }))
)

const vendors = computed(() => {
  const counts = new Map<string, { key: string; label: string; count: number }>()
  for (const { vendor } of entries.value) {
    const entry = counts.get(vendor.key) ?? { ...vendor, count: 0 }
    entry.count++
    counts.set(vendor.key, entry)
  }
  return [...counts.values()].sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
})

const favouriteCount = computed(() => entries.value.filter((entry) => entry.model.is_favourite).length)

/** `favourites`, `all`, or a vendor key */
const pane = ref('all')
const search = ref('')
const filters = ref(new Set<Capability>())

watch(open, (isOpen) => {
  if (!isOpen) return
  search.value = ''
  pane.value = favouriteCount.value > 0 ? 'favourites' : 'all'
})

const toggleFilter = (key: Capability) => {
  const next = new Set(filters.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  filters.value = next
}

interface Row {
  entry: Entry
  /** Matched characters of the name, for highlighting */
  indices: number[]
}

const rows = computed<Row[]>(() => {
  const query = prepareFuzzyQuery(search.value)
  const capable = entries.value.filter((entry) => [...filters.value].every((key) => entry.model[key]))

  if (query) {
    return capable
      .flatMap((entry) => {
        const byName = fuzzyMatch(query, entry.name)
        const byId = fuzzyMatch(query, entry.id)
        const score = Math.max(byName?.score ?? -Infinity, byId?.score ?? -Infinity)
        return score === -Infinity ? [] : [{ entry, indices: byName?.indices ?? [], score }]
      })
      .sort((a, b) => b.score - a.score)
  }

  return capable
    .filter((entry) =>
      pane.value === 'all' ? true : pane.value === 'favourites' ? entry.model.is_favourite : entry.vendor.key === pane.value
    )
    .map((entry) => ({ entry, indices: [] }))
})

/** Grouped by provider when browsing everything; ranked and flat while searching. */
const groups = computed(() => {
  if (search.value.trim() || (pane.value !== 'all' && pane.value !== 'favourites')) {
    return [{ key: 'results', label: null, rows: rows.value }]
  }
  const byVendor = new Map<string, Row[]>()
  for (const row of rows.value) {
    byVendor.set(row.entry.vendor.key, [...(byVendor.get(row.entry.vendor.key) ?? []), row])
  }
  // Same order as the provider list on the left.
  return vendors.value.flatMap((vendor) => {
    const vendorRows = byVendor.get(vendor.key)
    return vendorRows ? [{ key: vendor.key, label: vendor.label as string | null, rows: vendorRows }] : []
  })
})

const highlight = (name: string, indices: number[]) => {
  const marked = new Set(indices)
  return [...name].map((char, index) => ({ char, marked: marked.has(index) }))
}

const pick = (value: AcceptableValue) => {
  const model = entries.value.find((entry) => entry.model.full_id === value)?.model
  if (!model) return
  emit('select', model)
  open.value = false
}

const paneClass = (key: string) => [
  'flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors',
  pane.value === key && !search.value.trim()
    ? 'bg-tree-selected text-tree-selected-foreground'
    : 'text-muted hover:bg-tree-hover hover:text-foreground',
]
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent
      class="h-[min(36rem,calc(100dvh-2rem))] max-w-3xl! gap-0 overflow-hidden p-0!"
      :scroll-body="false"
    >
      <DialogTitle class="sr-only">{{ $t('components.aiModelSelector.selectModel') }}</DialogTitle>
      <DialogDescription class="sr-only">{{ $t('components.aiModelSelector.dialogDescription') }}</DialogDescription>
      <div class="flex min-h-0 flex-1">
        <nav
          class="flex w-52 shrink-0 flex-col gap-0.5 overflow-y-auto border-r border-border bg-sidebar p-2"
          :aria-label="$t('components.aiModelSelector.providers')"
        >
          <button
            v-if="favouriteCount > 0"
            type="button"
            :class="paneClass('favourites')"
            @click="(pane = 'favourites'), (search = '')"
          >
            <Icon
              name="lucide:star"
              class="shrink-0 text-ai-tint-foreground"
            />
            <span class="flex-1 truncate">{{ $t('components.aiModelSelector.favourites') }}</span>
            <span class="text-sm text-muted tabular-nums">{{ favouriteCount }}</span>
          </button>
          <button
            type="button"
            :class="paneClass('all')"
            @click="(pane = 'all'), (search = '')"
          >
            <Icon
              name="lucide:layers"
              class="shrink-0"
            />
            <span class="flex-1 truncate">{{ $t('components.aiModelSelector.all') }}</span>
            <span class="text-sm text-muted tabular-nums">{{ entries.length }}</span>
          </button>
          <button
            v-for="vendor in vendors"
            :key="vendor.key"
            type="button"
            :class="paneClass(vendor.key)"
            @click="(pane = vendor.key), (search = '')"
          >
            <Icon
              :name="vendorIcon(vendor.key, 'color')"
              class="shrink-0"
            />
            <span class="flex-1 truncate">{{ vendor.label }}</span>
            <span class="text-sm text-muted tabular-nums">{{ vendor.count }}</span>
          </button>
        </nav>

        <ListboxRoot
          class="flex min-w-0 flex-1 flex-col"
          :model-value="selected ?? undefined"
          highlight-on-hover
          @update:model-value="pick"
        >
          <div class="flex items-center gap-2 border-b border-border px-4 pr-12">
            <Icon
              name="lucide:search"
              class="shrink-0 text-muted"
            />
            <ListboxFilter
              v-model="search"
              auto-focus
              class="h-12 w-full bg-transparent text-base outline-none placeholder:text-muted"
              :placeholder="$t('components.aiModelSelector.search')"
            />
          </div>
          <div class="flex items-center gap-1.5 px-4 py-2">
            <button
              v-for="capability in CAPABILITIES"
              :key="capability.key"
              type="button"
              :aria-pressed="filters.has(capability.key)"
              class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm transition-colors"
              :class="
                filters.has(capability.key)
                  ? 'bg-ai-tint text-ai-tint-foreground hover:bg-ai-tint-hover'
                  : 'bg-secondary text-muted hover:text-foreground'
              "
              @click="toggleFilter(capability.key)"
            >
              <Icon :name="capability.icon" />
              {{ $t(`components.aiModelSelector.capabilities.${capability.label}`) }}
            </button>
            <span class="ml-auto text-sm text-muted tabular-nums">
              {{ $t('components.aiModelSelector.count', { count: rows.length }, rows.length) }}
            </span>
          </div>

          <div
            v-if="isLoading"
            class="space-y-2 p-4"
          >
            <Skeleton
              v-for="index in 6"
              :key="index"
              class="h-10 w-full"
            />
          </div>
          <div
            v-else-if="error"
            class="p-6 text-center text-sm text-destructive"
          >
            {{ $t('components.aiModelSelector.loadError') }}
            <button
              type="button"
              class="mt-2 block w-full cursor-pointer text-primary underline"
              @click="refetch()"
            >
              {{ $t('actions.retry') }}
            </button>
          </div>
          <ListboxContent
            v-else
            class="min-h-0 flex-1 overflow-y-auto px-2 pb-2"
          >
            <p
              v-if="rows.length === 0"
              class="px-2 py-8 text-center text-sm text-muted"
            >
              {{ $t('components.aiModelSelector.noMatches') }}
            </p>
            <ListboxGroup
              v-for="group in groups"
              :key="group.key"
            >
              <ListboxGroupLabel
                v-if="group.label"
                class="px-2 pt-3 pb-1 text-sm font-medium text-muted"
              >
                {{ group.label }}
              </ListboxGroupLabel>
              <div
                v-for="row in group.rows"
                :key="row.entry.model.full_id"
                class="group/row relative"
              >
                <ListboxItem
                  :value="row.entry.model.full_id"
                  class="flex cursor-pointer items-center gap-3 rounded-md py-1.5 pr-10 pl-2 outline-none data-highlighted:bg-tree-hover data-[state=checked]:bg-tree-selected data-[state=checked]:text-tree-selected-foreground"
                >
                  <Icon
                    :name="modelIcon(row.entry.model.driver, row.entry.model.id, 'color')"
                    size="1.125rem"
                    class="shrink-0"
                  />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-foreground">
                      <template
                        v-for="(part, index) in highlight(row.entry.label, row.indices)"
                        :key="index"
                        ><mark
                          v-if="part.marked"
                          class="bg-transparent text-ai-tint-foreground"
                          >{{ part.char }}</mark
                        ><template v-else>{{ part.char }}</template></template
                      >
                    </span>
                    <span class="block truncate text-sm text-muted">{{ row.entry.model.id }}</span>
                  </span>
                  <AiCapabilityIcons :capabilities="row.entry.model" />
                </ListboxItem>
                <button
                  type="button"
                  class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md transition-colors hover:bg-secondary"
                  :class="row.entry.model.is_favourite ? 'text-ai-tint-foreground' : 'text-muted opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100'"
                  :aria-pressed="row.entry.model.is_favourite"
                  :aria-label="t('components.aiModelSelector.toggleFavourite', { name: row.entry.label })"
                  @click="toggleFavourite(row.entry.model.full_id)"
                >
                  <Icon
                    name="lucide:star"
                    :class="row.entry.model.is_favourite && '[&_path]:fill-current'"
                  />
                </button>
              </div>
            </ListboxGroup>
          </ListboxContent>
        </ListboxRoot>
      </div>
    </DialogContent>
  </Dialog>
</template>
