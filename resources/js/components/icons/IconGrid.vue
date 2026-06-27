<script setup lang="ts">
import type { IconsQueryParams } from '~/api/resources/icons'
import Icon from '~/components/Icon.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
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

watch([search, selectedTag], () => {
  page.value = 1
})

const queryParams = computed<IconsQueryParams>(() => ({
  q: search.value || undefined,
  tags: selectedTag.value ? [selectedTag.value] : undefined,
  page: page.value,
  per_page: 60,
}))

const { data, isLoading, isError } = useIconsQuery(queryParams)
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
      <div class="relative w-full">
        <input
          v-model="search"
          type="text"
          name="icon-search"
          :placeholder="t('labels.icons.searchPlaceholder')"
          class="w-full h-9 flex items-center gap-2 rounded-md bg-input py-1 pr-6 pl-2 text-primary shadow-sm transition-colors placeholder:text-muted focus:ring-1 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 border-none"
        />
        <button
          v-if="search"
          type="button"
          class="absolute top-2 right-1 h-5 w-5 rounded-full p-0.5 hover:bg-elevated focus:ring-2 focus:ring-ring focus:outline-none flex items-center justify-center"
          :aria-label="t('labels.clear')"
          @click="search = ''"
        >
          <Icon
            name="lucide:x"
            class="text-muted"
            size="16"
          />
        </button>
      </div>

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
        class="flex h-40 items-center justify-center text-muted"
      >
        <Icon
          name="lucide:loader-circle"
          class="animate-spin"
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
        class="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2"
      >
        <button
          v-for="icon in icons"
          :key="icon.id"
          type="button"
          :title="`${icon.name} (${icon.key}) — ${icon.width}×${icon.height}`"
          class="group flex flex-col items-center justify-center gap-1.5 rounded-lg border border-input bg-surface p-2 pb-2.5 text-primary transition-colors hover:border-primary hover:bg-surface/80"
          style="min-height: 96px"
          @click="handleClick(icon)"
        >
          <IconPreview
            :body="icon.body"
            :width="icon.width"
            :height="icon.height"
            size="28"
          />
          <span class="w-full truncate text-center text-xs text-muted">{{ icon.key }}</span>
          <span class="text-[10px] leading-none text-muted/60">{{ icon.width }}×{{ icon.height }}</span>
        </button>
      </div>
    </div>

    <div
      v-if="meta && meta.last_page > 1"
      class="flex shrink-0 items-center justify-between border-t border-input pt-3 text-sm text-muted"
    >
      <span>{{ t('labels.icons.totalCount', { count: meta.total }) }}</span>
      <div class="flex items-center gap-2">
        <Button
          size="sm"
          variant="outline"
          :disabled="meta.current_page <= 1"
          @click="page = Math.max(1, page - 1)"
        >
          {{ t('actions.previous') }}
        </Button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <Button
          size="sm"
          variant="outline"
          :disabled="meta.current_page >= meta.last_page"
          @click="page = Math.min(meta.last_page, page + 1)"
        >
          {{ t('actions.next') }}
        </Button>
      </div>
    </div>
  </div>
</template>
