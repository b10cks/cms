<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import { Alert } from '~/components/ui/alert'
import { openDock } from '~/lib/aiDock'
import type { AiContentFlag, AiContentFlagEntry } from '~/lib/aiContentFlags'
import { flagFieldLink } from '~/lib/aiContentFlags'

/**
 * Names what AI left on the entries about to be published: the wording, the field
 * and a link to it. It informs and never blocks.
 */
const props = defineProps<{
  spaceId: string
  contentIds: string[]
}>()

const emit = defineEmits<{
  /** A link was followed; whoever shows this notice can get out of the way. */
  (e: 'leave'): void
}>()

const SHOWN = 6
/** The most entries the details endpoint names in one request. */
const REQUEST_LIMIT = 50

const { flags, totals } = useContentFlags(() => props.spaceId)
const flaggedIds = computed(() => props.contentIds.filter((id) => flags.value?.[id]).slice(0, REQUEST_LIMIT))

const { data: entries } = useQuery({
  queryKey: computed(() => [...queryKeys.ai(props.spaceId).contentFlags(), 'details', flaggedIds.value]),
  queryFn: async () => (await api.forSpace(props.spaceId).ai.contentFlagDetails(flaggedIds.value)).data,
  enabled: computed(() => flaggedIds.value.length > 0),
  staleTime: 0,
})

const rows = computed(() =>
  (entries.value ?? []).flatMap((entry: AiContentFlagEntry) => entry.flags.map((flag) => ({ entry, flag })))
)
const shown = computed(() => rows.value.slice(0, SHOWN))
const overall = computed(() => totals(props.contentIds))
const hidden = computed(() => overall.value.conflicts + overall.value.flagged - shown.value.length)

const linkTo = (entry: AiContentFlagEntry, flag: AiContentFlag) => flagFieldLink(props.spaceId, entry.content_id, flag.path)

const openConflict = (threadId: string) => {
  openDock(threadId)
  emit('leave')
}
</script>

<template>
  <Alert
    v-if="shown.length > 0"
    color="warning"
    icon="lucide:triangle-alert"
    class="text-sm"
    data-content-flags
  >
    <div class="grid gap-1 text-warning-ink">
      <p class="font-medium">{{ $t('components.contentFlags.title') }}</p>
      <ul class="grid gap-1">
        <li
          v-for="{ entry, flag } in shown"
          :key="`${entry.content_id}:${flag.kind}:${flag.path}:${flag.wording ?? ''}`"
          class="flex flex-wrap items-baseline gap-x-2"
          data-content-flag-row
        >
          <span v-if="contentIds.length > 1" class="font-medium">{{ entry.name }}</span>
          <template v-if="flag.kind === 'flagged'">
            <span>
              {{
                flag.field
                  ? $t('components.contentFlags.flaggedRow', { wording: flag.wording, field: flag.field })
                  : $t('components.contentFlags.flaggedRowNoField', { wording: flag.wording })
              }}
            </span>
            <span v-if="flag.replacement">{{ $t('components.contentFlags.replacement', { replacement: flag.replacement }) }}</span>
          </template>
          <span v-else>
            {{
              flag.field
                ? $t('components.contentFlags.conflictRow', { field: flag.field })
                : $t('components.contentFlags.conflictRowNoField')
            }}
          </span>
          <button
            v-if="flag.kind === 'conflict' && flag.thread_id"
            type="button"
            class="underline underline-offset-2"
            @click="openConflict(flag.thread_id)"
          >
            {{ $t('components.contentFlags.open') }}
          </button>
          <RouterLink
            v-else
            :to="linkTo(entry, flag)"
            class="underline underline-offset-2"
            @click="emit('leave')"
          >
            {{ $t('components.contentFlags.open') }}
          </RouterLink>
        </li>
      </ul>
      <p v-if="hidden > 0">{{ $t('components.contentFlags.more', { count: hidden }) }}</p>
      <p>{{ $t('components.contentFlags.proceed') }}</p>
    </div>
  </Alert>
</template>
