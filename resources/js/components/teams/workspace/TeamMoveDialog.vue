<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { Skeleton } from '~/components/ui/skeleton'
import type { TeamMovePerson } from '~/types/team-workspace'

/**
 * Confirms a team move with the server's preview of what changes: who gains
 * or loses inherited access, which custom roles start or stop applying, and
 * that direct space roles stay.
 */
const props = defineProps<{
  move: { teamId: string; teamName: string; parentId: string; parentName: string } | null
  isMoving: boolean
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const { t } = useI18n()
const { useTeamMovePreviewQuery } = useTeamWorkspace()

const { data: preview, isLoading, isError } = useTeamMovePreviewQuery(
  computed(() => props.move?.teamId ?? null),
  computed(() => props.move?.parentId ?? null)
)

const groups = computed(() => {
  if (!preview.value) return []
  return [
    { key: 'gains', icon: 'lucide:user-plus', people: preview.value.gains },
    { key: 'losses', icon: 'lucide:user-minus', people: preview.value.losses },
    { key: 'changes', icon: 'lucide:arrow-left-right', people: preview.value.changes },
  ].filter((group) => group.people.length > 0)
})

const roleLine = (person: TeamMovePerson) =>
  person.previous_role_name
    ? t('labels.teamWorkspace.move.roleChange', {
        from: person.previous_role_name,
        to: person.role_name,
        team: person.via.name,
      })
    : t('labels.teamWorkspace.move.roleVia', { role: person.role_name, team: person.via.name })
</script>

<template>
  <Dialog
    :open="!!move"
    @update:open="(open) => !open && emit('cancel')"
  >
    <DialogContent class="sm:max-w-xl">
      <DialogHeaderCombined
        v-if="move"
        :title="t('labels.teamWorkspace.move.title', { team: move.teamName, target: move.parentName })"
      />

      <div
        v-if="isLoading"
        class="flex flex-col gap-3"
        :aria-label="t('labels.teamWorkspace.move.loading')"
      >
        <Skeleton class="h-5 w-48" />
        <Skeleton class="h-10 w-full" />
        <Skeleton class="h-10 w-full" />
      </div>

      <p
        v-else-if="isError"
        class="text-sm text-destructive"
      >
        {{ t('labels.teamWorkspace.move.previewError') }}
      </p>

      <div
        v-else-if="preview"
        class="flex max-h-[60vh] flex-col gap-5 overflow-y-auto"
      >
        <p
          v-if="groups.length === 0"
          class="text-sm text-muted"
        >
          {{ t('labels.teamWorkspace.move.nothingChanges') }}
        </p>

        <section
          v-for="group in groups"
          :key="group.key"
          class="flex flex-col gap-2"
        >
          <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
            <Icon :name="group.icon" />
            {{ t(`labels.teamWorkspace.move.${group.key}`, { count: group.people.length }) }}
          </h3>
          <ul class="flex flex-col gap-1.5">
            <li
              v-for="person in group.people"
              :key="person.id"
              class="flex items-center gap-3 text-sm"
            >
              <Avatar
                :name="person.name"
                :avatar="person.avatar"
                class="size-6 rounded-full"
              />
              <span class="min-w-0 flex-1 truncate">
                <span class="font-medium text-primary">{{ person.name }}</span>
                <span class="text-muted"> {{ person.email }}</span>
              </span>
              <span class="shrink-0 text-muted">{{ roleLine(person) }}</span>
            </li>
          </ul>
        </section>

        <section
          v-if="preview.roles_starting.length || preview.roles_stopping.length"
          class="flex flex-col gap-2"
        >
          <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
            <Icon name="lucide:shield" />
            {{ t('labels.teamWorkspace.move.customRoles') }}
          </h3>
          <ul class="flex flex-col gap-1.5 text-sm">
            <li
              v-for="role in preview.roles_starting"
              :key="`start-${role.key}`"
              class="flex items-center gap-2"
            >
              <Badge
                variant="success"
                size="sm"
              >
                {{ t('labels.teamWorkspace.move.starts') }}
              </Badge>
              <span class="text-primary">{{ role.name }}</span>
              <span class="text-muted">{{ t('labels.teamWorkspace.move.definedIn', { team: role.team.name }) }}</span>
            </li>
            <li
              v-for="role in preview.roles_stopping"
              :key="`stop-${role.key}`"
              class="flex flex-wrap items-center gap-2"
            >
              <Badge
                variant="warning"
                size="sm"
              >
                {{ t('labels.teamWorkspace.move.stops') }}
              </Badge>
              <span class="text-primary">{{ role.name }}</span>
              <span class="text-muted">{{ t('labels.teamWorkspace.move.definedIn', { team: role.team.name }) }}</span>
              <span
                v-if="role.assignments"
                class="text-warning-foreground"
              >
                {{ t('labels.teamWorkspace.move.inUse', { count: role.assignments }) }}
              </span>
            </li>
          </ul>
        </section>

        <p class="flex items-center gap-2 text-sm text-muted">
          <Icon name="lucide:box" />
          {{ t('labels.teamWorkspace.move.directKept', { count: preview.direct_space_roles }) }}
        </p>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="emit('cancel')"
        >
          {{ t('actions.cancel') }}
        </Button>
        <Button
          variant="primary"
          :disabled="!preview"
          :loading="isMoving"
          @click="emit('confirm')"
        >
          {{ t('labels.teamWorkspace.move.confirm') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
