<script setup lang="ts">
import type { IconsQueryParams } from '~/api/resources/icons'
import Icon from '~/components/Icon.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { Skeleton } from '~/components/ui/skeleton'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { IconResource } from '~/types/icons'

const props = withDefaults(
  defineProps<{
    spaceId: string
    mode?: 'manage' | 'select'
  }>(),
  { mode: 'manage' }
)

const emit = defineEmits<{
  'icon-select': [icon: IconResource]
  'icon-edit': [icon: IconResource]
}>()

const { t } = useI18n()
const { useIconsQuery, useIconTagsQuery } = useIcons(props.spaceId)

const search = ref('')
const selectedTag = ref<string | null>(null)
const page = ref(1)
const perPage = ref(60)

watch([search, selectedTag, perPage], () => {
  page.value = 1
})

const queryParams = computed<IconsQueryParams>(() => ({
  q: search.value || undefined,
  tags: selectedTag.value ? [selectedTag.value] : undefined,
  page: page.value,
  per_page: perPage.value,
}))

const { data, isLoading, isError, isFetching } = useIconsQuery(queryParams)
const { data: tags } = useIconTagsQuery()

const icons = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta ?? null)

const handleClick = (icon: IconResource) => {
  if (props.mode === 'select') {
    emit('icon-select', icon)
  } else {
    emit('icon-edit', icon)
  }
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4">
    <div class="flex shrink-0 flex-col gap-3">
      <InputField
        v-model="search"
        name="icon-search"
        :placeholder="t('labels.icons.searchPlaceholder')"
        :actions="['clear']"
      />

      <div
        v-if="tags && tags.length"
        class="flex flex-wrap gap-1.5"
      >
        <Button
          size="sm"
          :variant="selectedTag === null ? 'primary' : 'outline'"
          @click="selectedTag = null"
        >
          {{ t('labels.icons.allTags') }}
        </Button>
        <Button
          v-for="tag in tags"
          :key="tag"
          size="sm"
          :variant="selectedTag === tag ? 'primary' : 'outline'"
          @click="selectedTag = tag"
        >
          {{ tag }}
        </Button>
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto pr-1">
      <div
        v-if="isLoading"
        aria-hidden="true"
        class="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2"
      >
        <Skeleton
          v-for="index in 18"
          :key="index"
          class="min-h-24 rounded-lg"
        />
      </div>

      <div
        v-else-if="isError"
        class="flex h-40 items-center justify-center text-destructive"
      >
        {{ t('labels.icons.loadError') }}
      </div>

      <div
        v-else-if="!icons.length"
        class="flex h-40 flex-col items-center justify-center gap-2 text-muted"
      >
        <Icon
          name="lucide:shapes"
          size="32"
        />
        <p class="text-sm">{{ t('labels.icons.empty') }}</p>
      </div>

      <div
        v-else
        class="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2 transition-opacity duration-200"
        :class="{ 'opacity-50': isFetching && !isLoading }"
      >
        <button
          v-for="icon in icons"
          :key="icon.id"
          type="button"
          :title="`${icon.name} (${icon.key}) — ${icon.width}×${icon.height}`"
          class="group flex flex-col items-center justify-center gap-2 cursor-pointer rounded-lg border border-input bg-surface p-2 pb-2.5 text-primary transition-colors hover:border-primary hover:bg-surface/80"
          style="min-height: 96px"
          @click="handleClick(icon)"
        >
          <IconPreview
            :body="icon.body"
            :width="icon.width"
            :height="icon.height"
            size="28"
          />
          <div class="flex flex-col items-center gap-0.5 w-full">
            <span class="w-full truncate text-center text-sm">{{ icon.key }}</span>
            <span class="text-xs leading-none text-muted">{{ icon.width }}×{{ icon.height }}</span>
          </div>
        </button>
      </div>
    </div>

    <TablePaginationFooter
      v-if="meta"
      class="shrink-0"
      :meta="meta"
      :current-page="page"
      :per-page="perPage"
      :page-size-options="[30, 60, 120, 200]"
      @update:current-page="(value) => (page = value)"
      @update:per-page="(value) => (perPage = value)"
    />
  </div>
</template>
