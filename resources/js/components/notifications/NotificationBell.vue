<script setup lang="ts">
import { computed, ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { BellIcon } from '~/components/icons'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { VerticalScrollArea } from '~/components/ui/scroll-area'
import { Skeleton } from '~/components/ui/skeleton'
import {
  type NotificationTone,
  useNotificationPresentation,
} from '~/composables/useNotificationPresentation'

const router = useRouter()
const { formatRelativeTime } = useFormat()
const { iconFor, toneFor, titleFor, bodyFor, routeFor } = useNotificationPresentation()

const {
  useNotificationsQuery,
  useUnreadCountQuery,
  useMarkAsReadMutation,
  useMarkAllAsReadMutation,
} = useNotifications()

const { data: list, isLoading } = useNotificationsQuery({ per_page: 15 })
const { data: unreadCount } = useUnreadCountQuery()
const markAsRead = useMarkAsReadMutation()
const markAllAsRead = useMarkAllAsReadMutation()

const open = ref(false)

const notifications = computed<NotificationResource[]>(() => list.value?.data ?? [])
const hasUnread = computed(() => (unreadCount.value ?? 0) > 0)
const badgeLabel = computed(() => {
  const count = unreadCount.value ?? 0
  return count > 99 ? '99+' : String(count)
})

const TONE_CLASSES: Record<NotificationTone, string> = {
  neutral: 'bg-secondary text-muted',
  info: 'bg-info-background/15 text-info-ink',
  success: 'bg-success-background/15 text-success-ink',
  warning: 'bg-warning-background/15 text-warning-ink',
  destructive: 'bg-destructive-background/15 text-destructive',
}

const onSelect = async (n: NotificationResource) => {
  if (!n.read_at) {
    markAsRead.mutate(n.id)
  }

  const target = routeFor(n)
  open.value = false

  if (target) {
    await router.push(target)
  }
}

const onMarkAllRead = () => {
  if (hasUnread.value) {
    markAllAsRead.mutate()
  }
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      class="icon-anim relative flex size-8 cursor-pointer items-center justify-center rounded-lg text-sidebar-muted transition-colors duration-100 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
      :aria-label="$t('notifications.tooltip')"
    >
      <BellIcon :size="16" />
      <span
        v-if="hasUnread"
        class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-accent-fill px-1 text-[10px] leading-4 font-semibold text-white"
      >
        {{ badgeLabel }}
      </span>
    </PopoverTrigger>
    <PopoverContent
      side="right"
      align="end"
      :side-offset="8"
      :collision-padding="16"
      class="w-104 max-h-none! overflow-hidden border-popover-border! p-0!"
    >
      <div class="flex h-11 items-center justify-between border-b border-popover-border pr-2 pl-3">
        <div class="flex items-center gap-2">
          <span class="text-sm font-semibold text-primary">{{ $t('notifications.title') }}</span>
          <span
            v-if="hasUnread"
            class="rounded-full bg-state-selected px-1.5 text-xs leading-5 font-semibold text-primary tabular-nums"
            :aria-label="$t('notifications.unreadCount', { count: unreadCount })"
          >
            {{ badgeLabel }}
          </span>
        </div>
        <button
          v-if="hasUnread"
          type="button"
          class="h-7 cursor-pointer rounded-md px-2 text-sm font-medium text-muted transition-colors duration-100 hover:bg-state-hover hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
          @click="onMarkAllRead"
        >
          {{ $t('notifications.markAllRead') }}
        </button>
      </div>

      <VerticalScrollArea class="max-h-[min(26rem,calc(100dvh-8rem))]">
        <div
          v-if="isLoading"
          class="p-1"
        >
          <div
            v-for="i in 4"
            :key="i"
            class="flex items-center gap-3 px-2 py-2"
          >
            <Skeleton class="size-7 shrink-0 rounded-full" />
            <div class="flex flex-1 flex-col gap-1.5">
              <Skeleton class="h-3 w-3/4" />
              <Skeleton class="h-2.5 w-1/2" />
            </div>
          </div>
        </div>
        <div
          v-else-if="notifications.length === 0"
          class="flex flex-col items-center gap-2 px-3 py-10 text-center"
        >
          <span class="flex size-9 items-center justify-center rounded-full bg-secondary text-muted">
            <Icon
              name="lucide:bell-off"
              class="size-4"
            />
          </span>
          <span class="text-sm text-muted">{{ $t('notifications.empty') }}</span>
        </div>
        <ul
          v-else
          class="flex flex-col gap-px p-1"
        >
          <li
            v-for="n in notifications"
            :key="n.id"
          >
            <button
              type="button"
              class="flex w-full cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-left transition-colors duration-75 hover:bg-state-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
              @click="onSelect(n)"
            >
              <span
                class="flex size-7 shrink-0 items-center justify-center rounded-full"
                :class="TONE_CLASSES[toneFor(n)]"
              >
                <Icon
                  :name="iconFor(n)"
                  class="size-3.5"
                />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span
                  class="truncate text-sm leading-5"
                  :class="n.read_at ? 'font-medium text-foreground' : 'font-semibold text-primary'"
                >
                  {{ titleFor(n) }}
                </span>
                <span class="flex items-baseline gap-3 text-sm leading-5 text-muted">
                  <span
                    v-if="bodyFor(n)"
                    class="min-w-0 flex-1 truncate"
                  >{{ bodyFor(n) }}</span>
                  <time
                    :datetime="n.created_at"
                    class="ml-auto shrink-0 whitespace-nowrap"
                  >{{ formatRelativeTime(n.created_at) }}</time>
                </span>
              </span>
              <span
                class="size-2 shrink-0 rounded-full"
                :class="n.read_at ? 'bg-transparent' : 'bg-accent'"
                aria-hidden="true"
              />
            </button>
          </li>
        </ul>
      </VerticalScrollArea>
    </PopoverContent>
  </Popover>
</template>
