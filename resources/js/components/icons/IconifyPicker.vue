<script setup lang="ts">
import { refDebounced } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { fetchIconifyCollection, searchIconifyIcons, splitIconName } from '~/lib/iconify'

const props = defineProps<{
  source: 'all' | 'collections'
  allowedCollections: string[]
}>()

const emit = defineEmits<{ select: [iconName: string] }>()

const { t } = useI18n()

const PAGE_SIZE = 120

const collections = computed(() =>
  props.source === 'collections' ? props.allowedCollections.filter(Boolean) : []
)

// For whitelisted sets we always browse within one collection; default to the first.
const activeCollection = ref<string | null>(collections.value[0] ?? null)
watch(collections, (list) => {
  if (props.source !== 'collections') {
    activeCollection.value = null
    return
  }
  if (!activeCollection.value || !list.includes(activeCollection.value)) {
    activeCollection.value = list[0] ?? null
  }
})

const search = ref('')
const debouncedSearch = refDebounced(search, 350)
const query = computed(() => debouncedSearch.value.trim())
const isSearching = computed(() => query.value.length >= 2)

const loading = ref(false)
const failed = ref(false)
const results = ref<string[]>([]) // fully-qualified names, e.g. "mdi:home"
const total = ref(0)
const browseLimit = ref(PAGE_SIZE)

let controller: AbortController | null = null

const load = async () => {
  controller?.abort()
  controller = new AbortController()
  const { signal } = controller

  loading.value = true
  failed.value = false

  try {
    if (isSearching.value) {
      const scope =
        props.source === 'collections'
          ? activeCollection.value
            ? [activeCollection.value]
            : collections.value
          : []
      const { icons, total: matched } = await searchIconifyIcons(
        query.value,
        scope.length === 1 ? { prefix: scope[0] } : scope.length > 1 ? { prefixes: scope } : {},
        signal
      )
      results.value = icons
      total.value = matched
    } else if (activeCollection.value) {
      const full = await fetchIconifyCollection(activeCollection.value, signal)
      results.value = full
      total.value = full.length
    } else {
      results.value = []
      total.value = 0
    }
  } catch (error) {
    if ((error as { name?: string })?.name !== 'AbortError') {
      failed.value = true
      results.value = []
      total.value = 0
    }
  } finally {
    if (!signal.aborted) loading.value = false
  }
}

watch([query, activeCollection, () => props.source], () => {
  browseLimit.value = PAGE_SIZE
  load()
}, { immediate: true })

onBeforeUnmount(() => controller?.abort())

const displayed = computed(() =>
  isSearching.value ? results.value : results.value.slice(0, browseLimit.value)
)
const canLoadMore = computed(() => !isSearching.value && browseLimit.value < results.value.length)
const showPrompt = computed(() => !isSearching.value && !activeCollection.value)

const nameOf = (full: string) => splitIconName(full).name
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-3">
    <div class="flex shrink-0 flex-col gap-2">
      <InputField
        v-model="search"
        name="iconify-search"
        :placeholder="t('labels.icons.field.iconifyPlaceholder')"
        :actions="['clear']"
      />
      <div
        v-if="collections.length > 1"
        class="flex flex-wrap gap-1.5"
      >
        <Button
          v-for="collection in collections"
          :key="collection"
          size="sm"
          :variant="activeCollection === collection ? 'primary' : 'outline'"
          @click="activeCollection = collection"
        >
          {{ collection }}
        </Button>
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto pr-1">
      <div
        v-if="loading"
        class="flex h-32 items-center justify-center text-muted"
      >
        <Icon name="lucide:loader-circle" class="animate-spin" />
      </div>

      <div
        v-else-if="failed"
        class="flex h-32 items-center justify-center text-sm text-destructive"
      >
        {{ t('labels.icons.field.iconifyError') }}
      </div>

      <div
        v-else-if="showPrompt"
        class="flex h-32 flex-col items-center justify-center gap-2 text-muted"
      >
        <Icon name="lucide:search" size="28" />
        <p class="text-sm">{{ t('labels.icons.field.searchAllHint') }}</p>
      </div>

      <div
        v-else-if="!displayed.length"
        class="flex h-32 items-center justify-center text-sm text-muted"
      >
        {{ t('labels.icons.field.iconifyEmpty') }}
      </div>

      <template v-else>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(72px,1fr))] gap-2">
          <button
            v-for="name in displayed"
            :key="name"
            type="button"
            :title="name"
            class="group flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border border-input bg-surface p-1.5 text-primary transition-colors hover:border-primary hover:bg-surface/80"
            @click="emit('select', name)"
          >
            <Icon :name="name" size="22" />
            <span class="w-full truncate text-center text-[10px] text-muted">{{ nameOf(name) }}</span>
          </button>
        </div>

        <div
          v-if="canLoadMore"
          class="mt-3 flex justify-center"
        >
          <Button
            size="sm"
            variant="outline"
            @click="browseLimit += PAGE_SIZE"
          >
            {{ t('labels.icons.field.loadMore') }}
          </Button>
        </div>
      </template>
    </div>

    <div
      v-if="total > 0 && !loading"
      class="shrink-0 text-xs text-muted"
    >
      {{ t('labels.icons.field.showingCount', { shown: displayed.length, total }) }}
    </div>
  </div>
</template>
