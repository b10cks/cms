<script setup lang="ts">
import { computed, ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { BellIcon } from '~/components/icons'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { ScrollArea } from '~/components/ui/scroll-area'
import { useNotificationPresentation } from '~/composables/useNotificationPresentation'
import type { NotificationResource } from '~/types/notifications'

const router = useRouter()
const { formatRelativeTime } = useFormat()
const { iconFor, titleFor, bodyFor, routeFor } = useNotificationPresentation()

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
      class="icon-anim relative flex size-9 cursor-pointer items-center justify-center rounded-lg text-muted transition-colors duration-200 hover:bg-elevated hover:text-primary data-[state=open]:bg-elevated"
      :aria-label="$t('notifications.tooltip')"
    >
      <BellIcon :size="16" />
      <span
        v-if="hasUnread"
        class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] leading-4 font-semibold text-white"
      >
        {{ badgeLabel }}
      </span>
    </PopoverTrigger>
    <PopoverContent
      align="end"
      class="w-90 p-0"
    >
      <div class="flex items-center justify-between border-b border-border px-3 py-2">
        <span class="text-sm font-semibold">{{ $t('notifications.title') }}</span>
        <button
          v-if="hasUnread"
          type="button"
          class="cursor-pointer text-xs text-muted transition-colors hover:text-primary"
          @click="onMarkAllRead"
        >
          {{ $t('notifications.markAllRead') }}
        </button>
      </div>

      <ScrollArea class="max-h-96">
        <div
          v-if="isLoading"
          class="px-3 py-8 text-center text-sm text-muted"
        >
          {{ $t('labels.loading') }}
        </div>
        <div
          v-else-if="notifications.length === 0"
          class="flex flex-col items-center gap-2 px-3 py-10 text-center text-sm text-muted"
        >
          <Icon
            name="lucide:bell-off"
            class="size-5"
          />
          {{ $t('notifications.empty') }}
        </div>
        <ul v-else>
          <li
            v-for="n in notifications"
            :key="n.id"
          >
            <button
              type="button"
              class="flex w-full cursor-pointer items-start gap-3 px-3 py-2.5 text-left transition-colors hover:bg-elevated"
              :class="!n.read_at ? 'bg-secondary/40' : ''"
              @click="onSelect(n)"
            >
              <span
                class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary text-muted"
              >
                <Icon
                  :name="iconFor(n)"
                  class="size-3.5"
                />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-primary">{{ titleFor(n) }}</span>
                <span
                  v-if="bodyFor(n)"
                  class="block truncate text-xs text-muted"
                >{{ bodyFor(n) }}</span>
                <span class="mt-0.5 block text-[11px] text-muted">{{
                  formatRelativeTime(n.created_at)
                }}</span>
              </span>
              <span
                v-if="!n.read_at"
                class="mt-1.5 size-2 shrink-0 rounded-full bg-blue-600"
              />
            </button>
          </li>
        </ul>
      </ScrollArea>
    </PopoverContent>
  </Popover>
</template>
