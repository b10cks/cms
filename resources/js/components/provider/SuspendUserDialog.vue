<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { SelectField, TextField } from '~/components/ui/form'
import { useProviderConsole } from '~/composables/useProviderConsole'
import type { ProviderUserDetail } from '~/types/provider-console'

const props = defineProps<{ user: ProviderUserDetail }>()
const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const { useSuspendMutation } = useProviderConsole()
const suspend = useSuspendMutation()

/** Sentinel for "leave the team flagged", since SelectField reads null as cleared. */
const LEAVE_FLAGGED = '__flag__'

const reason = ref('')
const replacements = ref<Record<string, string>>({})

const soleOwned = computed(() => props.user.owned_teams.filter((team) => team.is_sole_owner))

watch(open, (isOpen) => {
  if (!isOpen) return
  reason.value = ''
  replacements.value = Object.fromEntries(
    soleOwned.value.map((team) => [team.id, team.candidates[0]?.id ?? LEAVE_FLAGGED])
  )
})

const ownerOptions = (team: ProviderUserDetail['owned_teams'][number]) => [
  ...team.candidates.map((candidate) => ({ value: candidate.id, label: candidate.name })),
  { value: LEAVE_FLAGGED, label: t('providerConsole.suspend.leaveFlagged') },
]

const summary = computed(() =>
  t('providerConsole.suspend.description', {
    sessions:
      props.user.counts.sessions === null
        ? t('providerConsole.suspend.allSessions')
        : t('providerConsole.suspend.sessions', { count: props.user.counts.sessions }, props.user.counts.sessions),
    tokens: t('providerConsole.suspend.tokens', { count: props.user.counts.tokens }, props.user.counts.tokens),
  })
)

const canSubmit = computed(() => reason.value.trim().length >= 3 && !suspend.isPending.value)

const submit = () => {
  if (!canSubmit.value) return

  const replacementOwners = Object.fromEntries(
    Object.entries(replacements.value).filter(([, ownerId]) => ownerId !== LEAVE_FLAGGED)
  )

  suspend.mutate(
    { id: props.user.id, payload: { reason: reason.value.trim(), replacement_owners: replacementOwners } },
    { onSuccess: () => (open.value = false) }
  )
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('providerConsole.suspend.title', { name: user.name })"
        :description="summary"
      />
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <div
          v-for="team in soleOwned"
          :key="team.id"
          class="space-y-3 rounded-lg border border-border p-3"
        >
          <p class="flex items-start gap-2 text-sm">
            <Icon
              name="lucide:triangle-alert"
              class="mt-0.5 shrink-0 text-warning"
            />
            <span>{{ $t('providerConsole.suspend.soleOwner', { name: user.name, team: team.name }) }}</span>
          </p>
          <SelectField
            v-model="replacements[team.id]"
            :name="`owner-${team.id}`"
            :label="$t('providerConsole.suspend.newOwner')"
            :options="ownerOptions(team)"
          />
        </div>
        <TextField
          v-model="reason"
          name="reason"
          :label="$t('providerConsole.reason')"
          :placeholder="$t('providerConsole.suspend.reasonPlaceholder')"
          :rows="2"
          required
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="destructive"
            :loading="suspend.isPending.value"
            :disabled="!canSubmit"
          >
            {{ $t('providerConsole.suspend.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
