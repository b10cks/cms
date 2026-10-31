<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import type { AiThread } from '~/lib/aiThreads'
import { formatElapsed, SCREEN_CHIP_TYPES, THREAD_STATUS_STYLES } from '~/lib/aiThreads'
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
  const screen = thread.context?.find((chip) => SCREEN_CHIP_TYPES.has(chip.type))
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

/** Hidden until the row is hovered or the button focused; it then sits over the status, which turns invisible. */
const settleClass =
  'invisible absolute inset-y-0 right-0 -mr-1 inline-flex cursor-pointer items-center gap-1 rounded-md pr-1.5 pl-2 text-muted outline-none hover:text-foreground focus-visible:visible focus-visible:ring-1 focus-visible:ring-ring group-hover/row:visible'

const onKeydown = (event: KeyboardEvent) => {
  if (event.target !== event.currentTarget || (event.key !== 'Enter' && event.key !== ' ')) return
  event.preventDefault()
  emit('select')
}
</script>

<template>
  <li class="py-px">
    <div
      role="button"
      tabindex="0"
      class="group/row relative w-full cursor-pointer rounded-md border border-transparent px-2.5 text-left outline-none transition-colors duration-150 select-none focus-visible:ring-1 focus-visible:ring-ring"
      :class="[
        settled ? 'flex h-8 items-center gap-2' : 'py-2',
        active ? 'bg-tree-selected text-tree-selected-foreground' : 'hover:bg-tree-hover',
      ]"
      :aria-current="active ? 'true' : undefined"
      :data-thread="thread.id"
      @click="emit('select')"
      @keydown="onKeydown"
    >
      <span
        v-if="settled"
        class="min-w-0 flex-1 truncate text-sm"
        :class="active ? 'font-semibold' : 'text-muted group-hover/row:text-foreground'"
      >
        {{ thread.title }}
      </span>
      <div
        v-else
        class="flex h-5 min-w-0 items-center gap-1.5 text-sm"
      >
        <span class="min-w-0 flex-1 truncate text-muted">{{ context }}</span>
        <span class="relative flex h-5 shrink-0 items-center justify-end">
          <span
            class="flex items-center tabular-nums"
            :class="canSettle && 'group-hover/row:invisible group-has-[button:focus-visible]/row:invisible'"
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
            <span
              v-else-if="thread.last_activity_at"
              class="text-muted"
            >
              {{ formatRelativeTime(thread.last_activity_at) }}
            </span>
          </span>
          <button
            v-if="canSettle"
            type="button"
            :class="[settleClass, active ? 'bg-tree-selected' : 'bg-tree-hover']"
            :aria-label="$t('components.aiThreads.settleThread', { title: thread.title })"
            data-settle
            @click.stop="emit('settle')"
          >
            <Icon
              name="lucide:check"
              class="size-3.5"
            />
            {{ $t('components.aiThreads.settle') }}
          </button>
        </span>
      </div>
      <span
        v-if="settled"
        class="relative flex h-5 shrink-0 items-center justify-end text-sm"
      >
        <span
          class="text-muted tabular-nums"
          :class="canSettle && 'group-hover/row:invisible group-has-[button:focus-visible]/row:invisible'"
          data-status
        >
          {{ thread.last_activity_at ? formatRelativeTime(thread.last_activity_at) : '' }}
        </span>
        <button
          v-if="canSettle"
          type="button"
          :class="[settleClass, active ? 'bg-tree-selected' : 'bg-tree-hover']"
          :aria-label="$t('components.aiThreads.unsettleThread', { title: thread.title })"
          data-settle
          @click.stop="emit('settle')"
        >
          <Icon
            name="lucide:undo-2"
            class="size-3.5"
          />
          {{ $t('components.aiThreads.unsettle') }}
        </button>
      </span>
      <template v-if="!settled">
        <div
          class="mt-1 truncate text-base font-medium"
          :class="active ? 'text-tree-selected-foreground' : 'text-foreground'"
        >
          {{ thread.title }}
        </div>
        <div
          v-if="meta"
          class="mt-0.5 truncate text-sm text-muted"
        >
          {{ meta }}
        </div>
      </template>
    </div>
  </li>
</template>
