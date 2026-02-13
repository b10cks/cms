<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import ReleaseBadge from '~/components/releases/ReleaseBadge.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { Release } from '~/types/releases'

const spaceId = inject<string>('spaceId') || ''
const { getReleaseState } = useReleases(spaceId)
const { formatDateTimeDynamically, formatDateTime, formatTime } = useFormat()

const props = defineProps<{
  release: Release
}>()

const emit = defineEmits<{
  edit: [release: Release]
  delete: [release: Release]
  commit: [release: Release]
  cancel: [release: Release]
}>()

const isDraft = computed(() => getReleaseState(props.release) === 'draft')
const isScheduled = computed(() => getReleaseState(props.release) === 'scheduled')
const isPublished = computed(() => getReleaseState(props.release) === 'published')

const formatCalendarDate = (dateStr: string | null) => {
  if (!dateStr) return null
  const date = new Date(dateStr)
  return {
    day: date.getDate(),
    dayName: date.toLocaleDateString('en-US', { weekday: 'short' }).toUpperCase(),
    date: date,
  }
}

const handleEditClick = () => {
  emit('edit', props.release)
}

const handleDelete = () => {
  emit('delete', props.release)
}

const handleCommit = () => {
  emit('commit', props.release)
}

const handleCancel = () => {
  emit('cancel', props.release)
}
</script>

<template>
  <div class="flex items-center gap-4">
    <div
      v-if="props.release.publish_at"
      class="flex w-15 flex-col items-center overflow-clip rounded border border-border"
    >
      <div
        class="h-5 w-full bg-secondary py-1 text-center text-xs font-bold tracking-widest text-primary uppercase"
      >
        {{ formatDateTime(props.release.publish_at, 'ddd') }}
      </div>
      <div class="w-full flex-1 py-2 text-center text-xl font-bold">
        {{ formatDateTime(props.release.publish_at, 'D') }}
      </div>
    </div>
    <div
      v-else
      class="size-15 rounded border border-dashed border-border"
    ></div>
    <div class="grid flex-1 grid-cols-12 gap-2">
      <div class="col-span-5 flex min-w-0 flex-col gap-1 lg:col-span-3">
        <h3 class="truncate font-semibold text-primary">{{ release.name }}</h3>
        <div
          class="flex items-center gap-2 text-sm text-muted"
          :class="{ 'opacity-50': !release.versions_count }"
        >
          <Icon name="lucide:layers" />
          <span>{{
            $t(
              'labels.releases.fields.versionsCount',
              release.versions_count,
              release.versions_count
            )
          }}</span>
        </div>
      </div>
      <div class="col-span-2 flex min-w-0 items-center gap-1">
        <ReleaseBadge :release="release" />
      </div>

      <div class="col-span-3 hidden items-center gap-1 lg:flex">
        <Icon name="lucide:calendar" />
        <span class="font-semibold">{{ $t('labels.fields.updatedAt') }}:</span>
        <SimpleTooltip :tooltip="formatDateTime(props.release.updated_at)">
          <time class="text-muted">{{
            formatDateTimeDynamically(props.release.updated_at, 14)
          }}</time>
        </SimpleTooltip>
      </div>

      <div class="col-span-4 flex items-center gap-1 lg:col-span-3">
        <template v-if="props.release.publish_at">
          <Icon name="lucide:clock" />
          <span class="font-semibold">{{ $t('labels.releases.fields.publishAt') }}:</span>
          <time class="text-muted">{{ formatDateTime(props.release.publish_at, 'LT') }}</time>
        </template>
      </div>

      <div class="flex items-center justify-end gap-1">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button
              variant="ghost"
              size="icon"
              class="shrink-0"
            >
              <Icon name="lucide:more-horizontal" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem
              v-if="isDraft"
              @click="handleEditClick"
            >
              <Icon name="lucide:pencil" />
              <span>{{ $t('actions.edit') }}</span>
            </DropdownMenuItem>

            <DropdownMenuItem
              v-if="isDraft"
              @click="handleCommit"
            >
              <Icon name="lucide:check" />
              <span>{{ $t('actions.commit') }}</span>
            </DropdownMenuItem>

            <DropdownMenuItem
              v-if="isScheduled || isPublished"
              @click="handleCancel"
            >
              <Icon name="lucide:x" />
              <span>{{ $t('actions.cancel') }}</span>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem
              @click="handleDelete"
              class="text-destructive focus:bg-destructive focus:text-destructive-foreground"
            >
              <Icon name="lucide:trash-2" />
              <span>{{ $t('actions.delete') }}</span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  </div>
</template>
