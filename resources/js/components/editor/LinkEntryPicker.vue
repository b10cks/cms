<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { Skeleton } from '~/components/ui/skeleton'
import {
  contentPickerItems,
  contentPickerPaths,
  type ContentPickerItem,
} from '~/lib/content-picker-items'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'

/**
 * The entry of an internal link: a field naming the entry (and section), and a search popover.
 * An entry's sections open from its row, so a link can point at a block inside the entry.
 */
const props = withDefaults(
  defineProps<{
    spaceId: string
    content?: string
    anchor?: string
    disabled?: boolean
    /** False for pickers that choose a whole entry, like references. */
    sections?: boolean
    /** Entries that can't be chosen again, such as the ones a list already holds. */
    exclude?: readonly string[]
  }>(),
  { sections: true, exclude: () => [] }
)

const emit = defineEmits<{
  select: [content: string, anchor?: string]
}>()

const { t } = useI18n()

/** Results past this many ask for a narrower search instead of rendering hundreds of rows. */
const MAX_RESULTS = 50

const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const open = ref(false)
const query = ref('')
const highlighted = ref(0)
const sectionsFor = ref<string | null>(null)
// The entry search, kept while the sections of one result are open.
const entryQuery = ref('')
const listRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)

const allItems = computed(() => (contentMenu.value ? contentPickerItems(contentMenu.value) : []))
const paths = computed(() => contentPickerPaths(allItems.value))

const items = computed(() => {
  if (props.exclude.length === 0) return allItems.value
  const excluded = new Set(props.exclude)
  return allItems.value.filter((item) => !excluded.has(item.id))
})

const searchTargets = computed(() =>
  items.value.map((item) => ({ item, target: prepareFuzzyTarget(item.name || '') }))
)

const entryResults = computed<{ item: ContentPickerItem; indices: number[] }[]>(() => {
  const prepared = prepareFuzzyQuery(query.value)
  if (!prepared) return items.value.map((item) => ({ item, indices: [] }))

  return searchTargets.value
    .flatMap(({ item, target }) => {
      const match = fuzzyMatch(prepared, target)
      return match ? [{ item, indices: match.indices, score: match.score }] : []
    })
    .sort((a, b) => b.score - a.score)
})

const shownEntries = computed(() => entryResults.value.slice(0, MAX_RESULTS))
const hiddenCount = computed(() => entryResults.value.length - shownEntries.value.length)

const { anchors: sections, isLoading: sectionsLoading } = useContentAnchors(
  props.spaceId,
  sectionsFor
)

const plainText = (html: string) => html.replace(/<[^>]*>/g, '').trim()

const shownSections = computed(() => {
  const needle = query.value.trim().toLowerCase()
  return needle
    ? sections.value.filter((section) => plainText(section.title).toLowerCase().includes(needle))
    : sections.value
})

const sectionsEntry = computed(() =>
  sectionsFor.value ? contentMenu.value?.[sectionsFor.value] : undefined
)

// The rows Enter and the arrow keys walk: entries, or the page and its sections.
const rowCount = computed(() =>
  sectionsFor.value ? shownSections.value.length + 1 : shownEntries.value.length
)

const selected = computed(() => (props.content ? contentMenu.value?.[props.content] : undefined))

const { findAnchor, isLoaded: anchorsLoaded } = useContentAnchors(
  props.spaceId,
  computed(() => (props.anchor ? props.content : null))
)
const selectedSection = computed(() => findAnchor(props.anchor))
// The block was deleted or never belonged to this entry, so the link would land on the page top.
const isAnchorMissing = computed(
  () => !!props.anchor && anchorsLoaded.value && !selectedSection.value
)

/** Matched characters of a result name, for bold runs. */
const segments = (text: string, indices: number[]) => {
  const hits = new Set(indices)
  const runs: { text: string; hit: boolean }[] = []
  for (let index = 0; index < text.length; index++) {
    const hit = hits.has(index)
    const last = runs[runs.length - 1]
    if (last && last.hit === hit) last.text += text[index]
    else runs.push({ text: text[index], hit })
  }
  return runs
}

const entryIcon = (item?: FlatContentMenuItem) => `lucide:${item?.icon || 'file'}`

watch(open, (isOpen) => {
  if (!isOpen) return
  query.value = ''
  entryQuery.value = ''
  sectionsFor.value = null
  highlighted.value = 0
})

watch([query, sectionsFor], () => {
  highlighted.value = 0
})

const scrollToHighlighted = () =>
  nextTick(() =>
    listRef.value
      ?.querySelector<HTMLElement>(`[data-row="${highlighted.value}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  )

const choose = (content: string, anchor?: string) => {
  emit('select', content, anchor)
  open.value = false
}

const showSections = (id: string) => {
  entryQuery.value = query.value
  query.value = ''
  sectionsFor.value = id
  inputRef.value?.focus()
}

const backToEntries = () => {
  const from = sectionsFor.value
  sectionsFor.value = null
  query.value = entryQuery.value
  nextTick(() => {
    const index = shownEntries.value.findIndex(({ item }) => item.id === from)
    highlighted.value = Math.max(index, 0)
    scrollToHighlighted()
  })
  inputRef.value?.focus()
}

const chooseHighlighted = () => {
  if (sectionsFor.value) {
    const section = shownSections.value[highlighted.value - 1]
    choose(sectionsFor.value, section?.id)
    return
  }
  const entry = shownEntries.value[highlighted.value]
  if (entry) choose(entry.item.id)
}

const onKeydown = (event: KeyboardEvent) => {
  const input = event.target as HTMLInputElement
  const atEnd = input.selectionStart === input.value.length
  const atStart = input.selectionEnd === 0

  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowUp': {
      event.preventDefault()
      const delta = event.key === 'ArrowDown' ? 1 : -1
      highlighted.value = Math.min(Math.max(highlighted.value + delta, 0), rowCount.value - 1)
      scrollToHighlighted()
      break
    }
    case 'Enter':
      event.preventDefault()
      chooseHighlighted()
      break
    case 'ArrowRight': {
      const entry = shownEntries.value[highlighted.value]
      if (props.sections && !sectionsFor.value && atEnd && entry) {
        event.preventDefault()
        showSections(entry.item.id)
      }
      break
    }
    case 'ArrowLeft':
    case 'Backspace':
      if (sectionsFor.value && atStart && (event.key === 'ArrowLeft' || !input.value)) {
        event.preventDefault()
        backToEntries()
      }
      break
  }
}
</script>

<template>
  <div class="grid gap-2">
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <slot name="trigger">
        <button
          type="button"
          data-link-entry-trigger
          class="flex h-9 w-full min-w-0 cursor-pointer items-center gap-2 rounded-lg border border-input-border bg-input px-3 text-left text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=open]:ring-2 data-[state=open]:ring-ring"
          :disabled="disabled"
        >
          <template v-if="content">
            <Icon
              :name="entryIcon(selected)"
              class="shrink-0 text-muted"
            />
            <span
              :class="[
                'max-w-[60%] shrink-0 truncate font-medium',
                selected ? 'text-primary' : 'text-input-muted',
              ]"
            >
              {{ selected?.name || t('labels.references.unknownContent') }}
            </span>
            <template v-if="anchor">
              <Icon
                name="lucide:chevron-right"
                class="shrink-0 text-input-muted"
              />
              <span
                v-if="selectedSection"
                class="min-w-0 truncate text-primary [&_img]:inline [&_img]:size-4"
                :title="selectedSection.block.name"
                v-html="selectedSection.title"
              />
              <span
                v-else
                class="min-w-0 truncate font-mono text-input-muted"
              >
                #{{ anchor }}
              </span>
            </template>
            <span
              v-else-if="selected"
              class="min-w-0 truncate text-input-muted"
            >
              {{ paths.get(selected.id) }}
            </span>
          </template>
          <span
            v-else
            class="min-w-0 truncate text-input-muted"
          >
            {{ t('labels.link.chooseEntry') }}
          </span>
          <Icon
            name="lucide:chevrons-up-down"
            class="ml-auto shrink-0 text-input-muted"
          />
        </button>
        </slot>
      </PopoverTrigger>

      <PopoverContent
        align="start"
        data-link-picker
        class="flex w-(--reka-popover-trigger-width) min-w-80 flex-col !border-popover-border p-0! [--muted:var(--input-muted)]"
      >
        <div class="flex shrink-0 items-center gap-2 border-b border-popover-border px-3">
          <button
            v-if="sectionsFor"
            type="button"
            class="row-action -mx-1.5"
            :aria-label="t('labels.link.back')"
            :title="t('labels.link.back')"
            @click="backToEntries"
          >
            <Icon name="lucide:chevron-left" />
          </button>
          <Icon
            v-else
            name="lucide:search"
            class="shrink-0 text-muted"
          />
          <input
            ref="inputRef"
            v-model="query"
            type="text"
            class="flex h-10 min-w-0 grow rounded-md bg-transparent py-3 text-sm text-primary outline-none placeholder:text-input-muted"
            :placeholder="
              sectionsFor ? t('labels.link.searchSections') : t('labels.link.searchEntries')
            "
            :aria-label="
              sectionsFor ? t('labels.link.searchSections') : t('labels.link.searchEntries')
            "
            @keydown="onKeydown"
          />
        </div>

        <div
          ref="listRef"
          role="listbox"
          class="grid max-h-72 grid-cols-[minmax(0,1fr)] overflow-y-auto p-1"
        >
          <template v-if="!sectionsFor">
            <p class="px-2 py-1.5 text-sm font-medium text-muted">{{ t('labels.link.entries') }}</p>
            <div
              v-for="({ item, indices }, index) in shownEntries"
              :key="item.id"
              role="option"
              :aria-selected="item.id === content"
              :data-row="index"
              :data-highlighted="index === highlighted ? '' : undefined"
              class="group menu-row"
              @pointermove="highlighted = index"
              @click="choose(item.id)"
            >
              <Icon
                :name="entryIcon(item)"
                :style="item.color ? { color: item.color } : undefined"
              />
              <span class="max-w-[60%] shrink-0 truncate">
                <span
                  v-for="(run, runIndex) in segments(item.name || '', indices)"
                  :key="runIndex"
                  :class="run.hit ? 'font-bold text-primary' : ''"
                  >{{ run.text }}</span
                >
              </span>
              <span class="min-w-0 truncate font-normal text-muted">{{ paths.get(item.id) }}</span>
              <Icon
                v-if="item.id === content && !anchor"
                name="lucide:check"
                class="ml-auto text-primary"
              />
              <button
                v-if="sections"
                type="button"
                :data-sections-for="item.id"
                :class="[
                  'flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-sm text-muted opacity-0 group-hover:opacity-100 group-data-[highlighted]:opacity-100 hover:bg-state-hover hover:text-primary',
                  item.id === content && !anchor ? '' : 'ml-auto',
                ]"
                :aria-label="t('labels.link.showSections', { name: item.name })"
                :title="t('labels.link.showSections', { name: item.name })"
                tabindex="-1"
                @click.stop="showSections(item.id)"
              >
                <Icon name="lucide:chevron-right" />
              </button>
            </div>
            <p
              v-if="shownEntries.length === 0"
              class="px-2 py-1.5 text-sm text-muted"
            >
              {{ t('labels.link.noEntries') }}
            </p>
            <p
              v-else-if="hiddenCount > 0"
              class="px-2 py-1.5 text-sm text-muted"
            >
              {{ t('labels.link.moreEntries', { count: hiddenCount }) }}
            </p>
          </template>

          <template v-else>
            <div
              role="option"
              :aria-selected="sectionsFor === content && !anchor"
              data-row="0"
              :data-highlighted="highlighted === 0 ? '' : undefined"
              class="menu-row"
              @pointermove="highlighted = 0"
              @click="choose(sectionsFor)"
            >
              <Icon name="lucide:file-symlink" />
              <span class="shrink-0">{{ t('labels.link.linkToPage') }}</span>
              <span class="min-w-0 truncate font-normal text-muted">{{ sectionsEntry?.name }}</span>
              <Icon
                v-if="sectionsFor === content && !anchor"
                name="lucide:check"
                class="ml-auto text-primary"
              />
            </div>
            <div class="-mx-1 my-1 h-px bg-popover-border" />
            <p class="px-2 py-1.5 text-sm font-medium text-muted">{{ t('labels.link.sections') }}</p>
            <template v-if="sectionsLoading">
              <Skeleton
                v-for="i in 3"
                :key="i"
                class="mx-2 my-1.5 h-5"
              />
            </template>
            <p
              v-else-if="shownSections.length === 0"
              class="px-2 py-1.5 text-sm text-muted"
            >
              {{ query.trim() ? t('labels.link.noSectionsMatch') : t('labels.link.noSections') }}
            </p>
            <div
              v-for="(section, index) in shownSections"
              :key="section.id"
              role="option"
              :aria-selected="sectionsFor === content && section.id === anchor"
              :data-row="index + 1"
              :data-highlighted="highlighted === index + 1 ? '' : undefined"
              class="menu-row"
              :style="{ paddingLeft: `${8 + section.depth * 16}px` }"
              @pointermove="highlighted = index + 1"
              @click="choose(sectionsFor, section.id)"
            >
              <Icon
                :name="section.block.icon ? `lucide:${section.block.icon}` : 'lucide:box'"
                :style="section.block.color ? { color: section.block.color } : undefined"
              />
              <span
                class="min-w-0 truncate [&_img]:inline [&_img]:size-4"
                v-html="section.title"
              />
              <span class="ml-auto shrink-0 font-normal text-muted">
                {{ section.block.name || section.block.slug }}
              </span>
              <Icon
                v-if="sectionsFor === content && section.id === anchor"
                name="lucide:check"
                class="text-primary"
              />
            </div>
          </template>
        </div>
      </PopoverContent>
    </Popover>

    <div
      v-if="isAnchorMissing && content"
      class="flex items-center gap-2 text-sm text-warning-ink"
    >
      <Icon
        name="lucide:triangle-alert"
        class="shrink-0"
      />
      <span class="grow">{{ t('labels.link.anchorMissing') }}</span>
      <Button
        type="button"
        variant="ghost"
        size="xs"
        :disabled="disabled"
        @click="emit('select', content)"
      >
        {{ t('labels.link.linkToPage') }}
      </Button>
    </div>
  </div>
</template>
