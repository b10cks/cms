<script setup lang="ts">
import { I18nT } from 'vue-i18n'

import { Avatar } from '~/components/ui/avatar'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { TeamAuditEntry } from '~/types/team-workspace'

/** Team activity rows: who did what to whom, in one sentence each. */
defineProps<{
  entries: TeamAuditEntry[]
}>()

const { t } = useI18n()
const { formatDate, formatDateTime, formatRelativeTime } = useFormat()

const MEMBER_ACTIONS = ['member_added', 'member_removed', 'member_role_changed', 'member_expiry_changed']

/** The sentence key under `labels.teamWorkspace.activity.actions`. */
const sentence = (entry: TeamAuditEntry) => {
  if (entry.action === 'member_removed' && entry.reason === 'expired') return 'member_expired'
  if (entry.action === 'member_expiry_changed' && !entry.new_values?.expires_at) return 'member_expiry_cleared'
  if (entry.action === 'updated' && entry.subject.type === 'team' && entry.parents.to) return 'team_moved'
  if (entry.subject.type === 'team' && ['created', 'updated', 'deleted'].includes(entry.action)) {
    return `team_${entry.action}`
  }
  return MEMBER_ACTIONS.includes(entry.action) ? entry.action : 'fallback'
}

const values = (entry: TeamAuditEntry) => ({
  from: entry.old_values?.role ?? '',
  to: entry.new_values?.role ?? '',
  role: entry.new_values?.role ?? entry.old_values?.role ?? '',
  date: entry.new_values?.expires_at ? formatDate(entry.new_values.expires_at) : '',
  action: entry.action,
})
</script>

<template>
  <ul class="-mx-5 flex flex-col">
    <li
      v-for="entry in entries"
      :key="entry.id"
      class="flex items-center gap-3 border-t border-border px-5 py-3 first:border-t-0"
    >
      <Avatar
        :name="entry.actor?.name ?? t('labels.teamWorkspace.activity.system')"
        :avatar="entry.actor?.avatar"
        class="size-7 shrink-0 rounded-full"
      />
      <I18nT
        :keypath="`labels.teamWorkspace.activity.actions.${sentence(entry)}`"
        tag="p"
        class="min-w-0 flex-1 text-sm text-muted"
        scope="global"
      >
        <template #actor>
          <span class="font-medium text-primary">{{ entry.actor?.name ?? t('labels.teamWorkspace.activity.system') }}</span>
        </template>
        <template #member>
          <span class="font-medium text-primary">{{ entry.member?.name ?? t('labels.teamWorkspace.activity.someone') }}</span>
        </template>
        <template #subject>
          <span class="font-medium text-primary">{{ entry.subject.name ?? t('labels.teamWorkspace.activity.deleted') }}</span>
        </template>
        <template #target>
          <span class="font-medium text-primary">{{ entry.parents.to }}</span>
        </template>
        <template
          v-for="(value, name) in values(entry)"
          :key="name"
          #[name]
        >
          {{ value }}
        </template>
      </I18nT>
      <SimpleTooltip :tooltip="formatDateTime(entry.created_at)">
        <span class="shrink-0 text-sm text-muted tabular-nums">{{ formatRelativeTime(entry.created_at) }}</span>
      </SimpleTooltip>
    </li>
  </ul>
</template>
