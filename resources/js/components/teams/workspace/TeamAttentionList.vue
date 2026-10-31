<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import type { TeamAttentionItem } from '~/types/team-workspace'

/**
 * The "Needs attention" rows. Each known item type has an icon here and copy in
 * `labels.teamWorkspace.attention.types.<type>`; a type added to
 * TeamAttentionCollector without them still renders, with its raw type name.
 */
defineProps<{
  items: TeamAttentionItem[]
}>()

const { t } = useI18n()

const ICONS: Record<string, string> = {
  join_requests: 'lucide:globe',
  expiring_access: 'lucide:clock',
  stale_invites: 'lucide:mail',
  saml_certificate_expiring: 'lucide:key-round',
  teams_without_owner: 'lucide:user-x',
}

const copy = (item: TeamAttentionItem, part: 'title' | 'action') => {
  if (item.type in ICONS) {
    return t(`labels.teamWorkspace.attention.types.${item.type}.${part}`, { count: item.count }, item.count)
  }
  return part === 'title' ? item.type : t('labels.teamWorkspace.attention.open')
}
</script>

<template>
  <p
    v-if="items.length === 0"
    class="flex items-center gap-2 py-6 text-sm text-muted"
  >
    <Icon name="lucide:circle-check" />
    {{ t('labels.teamWorkspace.attention.empty') }}
  </p>
  <ul
    v-else
    class="-mx-5 flex flex-col"
  >
    <li
      v-for="item in items"
      :key="`${item.type}-${item.target?.params?.team ?? ''}`"
      class="flex items-center gap-3 border-t border-border px-5 py-3 first:border-t-0"
    >
      <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary text-muted">
        <Icon :name="ICONS[item.type] ?? 'lucide:circle-alert'" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-primary">{{ copy(item, 'title') }}</p>
        <p
          v-if="item.details.length"
          class="truncate text-sm text-muted"
          :title="item.details.join(', ')"
        >
          {{ item.details.join(', ') }}
        </p>
      </div>
      <Button
        v-if="item.target"
        size="sm"
        variant="outline"
        as-child
      >
        <RouterLink :to="{ name: item.target.name, params: item.target.params, query: item.target.query, hash: item.target.hash }">
          {{ copy(item, 'action') }}
        </RouterLink>
      </Button>
    </li>
  </ul>
</template>
