<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import type { AiThread } from '~/lib/aiThreads'
import { formatElapsed, THREAD_STATUS_STYLES } from '~/lib/aiThreads'
import { aiSkillLabel } from '~/lib/aiSkills'

/**
 * One thread as a T3 Code inbox card: the context with the status on the
 * right, the title, and a meta line when there is something to say. Hovering
 * swaps the status for Settle, or Unsettle on a settled thread.
 */
const props = defineProps<{
  thread: AiThread
  active: boolean
  settled: boolean
  canSettle: boolean
  /** Ticks the elapsed time of a running thread */
  now: number
}>()

const emit = defineEmits<{
  select: []
  settle: []
}>()

const { t } = useI18n()
const { formatRelativeTime } = useFormat()

const context = computed(() => {
  const { thread } = props
  if (thread.kind === 'schedule') return t('components.aiThreads.context.schedule')
  if (thread.kind === 'suggestions') return t('components.aiThreads.context.suggestions')
  const screen = thread.context?.find((chip) => chip.type === 'content' || chip.type === 'data_source')
  return screen?.label ?? (thread.skill ? aiSkillLabel(thread.skill, t) : '')
})

const status = computed(() => (props.settled ? null : THREAD_STATUS_STYLES[props.thread.status]))

const elapsed = computed(() =>
  props.thread.status === 'running' && props.thread.last_activity_at
    ? formatElapsed(props.now - new Date(props.thread.last_activity_at).getTime())
    : null
)

const meta = computed(() =>
  [
    props.thread.entry_count > 0 ? t('components.aiThreads.toReview', { count: props.thread.entry_count }) : null,
    !props.thread.is_owner && props.thread.owner
      ? t('components.aiThreads.sharedBy', { name: props.thread.owner.name })
      : null,
    props.thread.is_owner && props.thread.shared ? t('components.aiThreads.sharedWithSpace') : null,
  ]
    .filter(Boolean)
    .join(', ')
)

const onKeydown = (event: KeyboardEvent) => {
  if (event.target !== event.currentTarget || (event.key !== 'Enter' && event.key !== ' ')) return
  event.preventDefault()
  emit('select')
}
</script>

<template>
  <li class="py-0.5">
    <div
      role="button"
      tabindex="0"
      class="group/row relative w-full cursor-pointer rounded-lg px-3 py-2 text-left outline-none select-none focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-inset"
      :class="active ? 'bg-tree-selected' : 'hover:bg-tree-hover'"
      :aria-current="active ? 'true' : undefined"
      :data-thread="thread.id"
      @click="emit('select')"
      @keydown="onKeydown"
    >
      <div class="flex h-5 min-w-0 items-center gap-1.5 text-sm">
        <span class="min-w-0 flex-1 truncate text-muted">{{ context }}</span>
        <span class="relative flex h-5 min-w-8 shrink-0 items-stretch justify-end">
          <span
            class="flex items-center self-center text-muted tabular-nums"
            :class="
              canSettle &&
              'group-hover/row:absolute group-hover/row:right-0 group-hover/row:opacity-0 group-has-[button:focus-visible]/row:absolute group-has-[button:focus-visible]/row:right-0 group-has-[button:focus-visible]/row:opacity-0'
            "
            data-status
          >
            <span
              v-if="status"
              class="inline-flex items-center gap-1 font-medium"
              :class="status.class"
            >
              <Icon
                :name="status.icon"
                class="size-4 shrink-0"
                :class="thread.status === 'running' && 'animate-spin [animation-duration:3s]'"
              />
              {{ $t(`components.aiThreads.status.${thread.status}`) }}
              <span
                v-if="elapsed"
                aria-hidden="true"
              >
                {{ elapsed }}
              </span>
            </span>
            <template v-else-if="thread.last_activity_at">
              {{ formatRelativeTime(thread.last_activity_at) }}
            </template>
          </span>
          <button
            v-if="canSettle"
            type="button"
            class="pointer-events-none absolute inset-y-0 right-0 -mr-1 inline-flex cursor-pointer items-center gap-1 rounded-md px-1.5 text-muted opacity-0 outline-none hover:text-foreground focus-visible:pointer-events-auto focus-visible:static focus-visible:opacity-100 focus-visible:ring-1 focus-visible:ring-ring group-hover/row:pointer-events-auto group-hover/row:static group-hover/row:opacity-100"
            :aria-label="settled ? $t('components.aiThreads.unsettleThread', { title: thread.title }) : $t('components.aiThreads.settleThread', { title: thread.title })"
            data-settle
            @click.stop="emit('settle')"
          >
            <Icon
              :name="settled ? 'lucide:undo-2' : 'lucide:check'"
              class="size-3.5"
            />
            {{ settled ? $t('components.aiThreads.unsettle') : $t('components.aiThreads.settle') }}
          </button>
        </span>
      </div>
      <div
        class="mt-1 truncate text-base"
        :class="
          settled
            ? 'font-normal text-foreground/75 group-hover/row:text-foreground'
            : active
              ? 'font-medium text-primary'
              : 'font-medium text-foreground'
        "
      >
        {{ thread.title }}
      </div>
      <div
        v-if="meta"
        class="mt-0.5 truncate text-sm text-muted"
      >
        {{ meta }}
      </div>
    </div>
  </li>
</template>
