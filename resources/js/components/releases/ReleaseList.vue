<script setup lang="ts">
import ReleasesIcon from '~/assets/images/releases.svg?component'
import ReleaseCard from '~/components/releases/ReleaseCard.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import type { Release } from '~/types/releases'

const { $t } = useI18n()

const spaceId = inject<string>('spaceId') || ''
const { useReleasesQuery } = useReleases(spaceId)

const props = defineProps<{
  isLoading?: boolean
  onEdit?: (release: Release) => void
  onDelete?: (release: Release) => void
  onCommit?: (release: Release) => void
  onCancel?: (release: Release) => void
}>()

const emit = defineEmits<{
  edit: [release: Release]
  delete: [release: Release]
  commit: [release: Release]
  cancel: [release: Release]
}>()

const releaseStates = computed(() => [
  { value: 'draft', label: $t('labels.releases.states.draft') },
  { value: 'scheduled', label: $t('labels.releases.states.scheduled') },
  { value: 'published', label: $t('labels.releases.states.published') },
])

const filters = ref<Record<string, unknown>>({})
const releaseFilters = computed(() => [
  {
    id: 'name',
    label: $t('labels.releases.fields.name'),
    operators: [
      { value: 'like', label: $t('labels.filters.operators.like') },
      { value: '^like', label: $t('labels.filters.operators.^like') },
      { value: 'like$', label: $t('labels.filters.operators.like$') },
      { value: 'eq', label: $t('labels.filters.operators.eq') },
      { value: 'ne', label: $t('labels.filters.operators.ne') },
    ],
  },
  {
    id: 'scheduled',
    label: $t('labels.releases.fields.scheduled'),
    items: [
      { value: 'true', label: $t('labels.yes') },
      { value: 'false', label: $t('labels.no') },
    ],
  },
  {
    id: 'state',
    label: $t('labels.releases.fields.state'),
    items: releaseStates.value,
  },
])

const queryParams = computed(() => ({
  ...filters.value,
  per_page: 1000, // Fetch all releases for local filtering
  sort: '-publish_at',
}))

const { data: queryData, isLoading: isLoadingReleases } = useReleasesQuery(queryParams)

const allReleases = computed(() => queryData.value?.data || [])
const isLoading = computed(() => isLoadingReleases.value || props.isLoading)

// Group releases by month or into Unscheduled
const groupedReleases = computed(() => {
  const unscheduled: Release[] = []
  const byMonth: Record<string, Release[]> = {}

  allReleases.value.forEach((release) => {
    if (!release.publish_at) {
      unscheduled.push(release)
    } else {
      const date = new Date(release.publish_at)
      const monthKey = date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
      })

      if (!byMonth[monthKey]) {
        byMonth[monthKey] = []
      }
      byMonth[monthKey].push(release)
    }
  })

  // Sort releases within each month by publish_at (newest first)
  Object.keys(byMonth).forEach((key) => {
    byMonth[key].sort((a, b) => {
      const dateA = new Date(a.publish_at || 0).getTime()
      const dateB = new Date(b.publish_at || 0).getTime()
      return dateA - dateB
    })
  })

  // Sort months in descending order (newest first)
  const sortedMonths = Object.keys(byMonth).sort((a, b) => {
    const dateA = new Date(a).getTime()
    const dateB = new Date(b).getTime()
    return dateA - dateB
  })

  // Sort unscheduled by updated_at (newest first)
  unscheduled.sort((a, b) => {
    const dateA = new Date(a.updated_at).getTime()
    const dateB = new Date(b.updated_at).getTime()
    return dateA - dateB
  })

  return {
    unscheduled,
    byMonth: Object.fromEntries(sortedMonths.map((key) => [key, byMonth[key]])),
  }
})

const hasReleases = computed(() => allReleases.value.length > 0)
const hasSearchResults = computed(() => {
  const groups = groupedReleases.value
  const unscheduledCount = groups.unscheduled.length
  const monthCount = Object.values(groups.byMonth).reduce(
    (sum, releases) => sum + releases.length,
    0
  )
  return unscheduledCount + monthCount > 0
})

const handleEditClick = (release: Release) => {
  emit('edit', release)
  props.onEdit?.(release)
}

const handleDelete = (release: Release) => {
  emit('delete', release)
  props.onDelete?.(release)
}

const handleCommit = (release: Release) => {
  emit('commit', release)
  props.onCommit?.(release)
}

const handleCancel = (release: Release) => {
  emit('cancel', release)
  props.onCancel?.(release)
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div class="flex flex-1 flex-col gap-2 md:flex-row md:items-center">
        <SearchFilter
          v-model="filters"
          :filterable-fields="releaseFilters"
          class="flex-1"
          @search="
            (query) => {
              searchQuery = query
              currentPage = 1
            }
          "
          @reset="
            () => {
              searchQuery = ''
              currentPage = 1
            }
          "
        />
      </div>
    </div>
    <div
      v-if="isLoading"
      class="space-y-4"
    >
      <div
        v-for="i in 3"
        :key="i"
        class="h-20 animate-pulse rounded-lg border border-border bg-muted/20"
      />
    </div>
    <template v-else-if="!hasReleases">
      <div
        class="flex flex-col items-center justify-center rounded-lg border border-dashed border-border py-12 text-center"
      >
        <ReleasesIcon class="mb-4 w-32" />
        <p class="text-muted-foreground text-sm font-medium">
          {{ $t('labels.releases.noReleases') }}
        </p>
      </div>
    </template>
    <template v-else-if="!hasSearchResults">
      <div
        class="flex flex-col items-center justify-center rounded-lg border border-dashed border-border py-12 text-center"
      >
        <Icon
          name="lucide:search-x"
          class="text-muted-foreground/50 mb-4 h-12 w-12"
        />
        <p class="text-muted-foreground text-sm font-medium">
          No releases found matching your search
        </p>
      </div>
    </template>

    <div
      v-else
      class="space-y-8"
    >
      <section v-if="groupedReleases.unscheduled.length > 0">
        <div class="mb-4 flex items-center gap-2">
          <h2 class="text-xl font-semibold text-primary">
            {{ $t('labels.releases.unscheduledReleases') }}
          </h2>
          <Badge size="sm">{{ groupedReleases.unscheduled.length }}</Badge>
        </div>
        <div class="space-y-6">
          <ReleaseCard
            v-for="release in groupedReleases.unscheduled"
            :key="release.id"
            :release="release"
            @edit="handleEditClick"
            @delete="handleDelete"
            @commit="handleCommit"
            @cancel="handleCancel"
          />
        </div>
      </section>
      <section
        v-for="(releases, month) in groupedReleases.byMonth"
        :key="month"
      >
        <div class="mb-4 flex items-center gap-2">
          <h2 class="text-xl font-semibold text-primary">{{ month }}</h2>
          <Badge size="sm">{{ releases.length }}</Badge>
        </div>
        <div class="space-y-6">
          <ReleaseCard
            v-for="release in releases"
            :key="release.id"
            :release="release"
            @edit="handleEditClick"
            @delete="handleDelete"
            @commit="handleCommit"
            @cancel="handleCancel"
          />
        </div>
      </section>
    </div>
  </div>
</template>
