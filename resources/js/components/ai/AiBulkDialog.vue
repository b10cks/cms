<script setup lang="ts">
import { useMutation, useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AiResolvedSkill } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import { Spinner } from '~/components/ui/spinner'
import type { AiEntryScope } from '~/lib/aiChangesets'
import { openDock } from '~/lib/aiDock'
import { aiSkillLabel } from '~/lib/aiSkills'
import { toastError } from '~/lib/toast-error'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  spaceId: string
  /** `bulk:content`, `bulk:data_entries` or `bulk:assets` */
  surface: string
  scope: AiEntryScope
}>()

const { t } = useI18n()
const { formatCurrency, formatNumber } = useFormat()
const { data: skills } = useAiSurfaceSkills(() => props.spaceId, () => props.surface)

const skill = ref<AiResolvedSkill | null>(null)
const prompt = ref('')

watch(open, (isOpen) => {
  if (!isOpen) return
  skill.value = null
  prompt.value = ''
})

const { data: preflight, isFetching, error } = useQuery({
  queryKey: computed(() => [...queryKeys.ai(props.spaceId).all(), 'preflight', props.surface, props.scope, skill.value?.name ?? null]),
  queryFn: async () =>
    (await api.forSpace(props.spaceId).ai.preflight({ surface: props.surface, scope: props.scope, skill: skill.value?.name ?? null })).data,
  enabled: open,
  staleTime: 0,
  gcTime: 0,
})

const startMutation = useMutation({
  mutationFn: () =>
    api.forSpace(props.spaceId).ai.startBackgroundRun({
      surface: props.surface,
      skill: skill.value?.name ?? null,
      prompt: prompt.value,
      targets: { type: props.scope.type, ids: preflight.value?.ids ?? [] },
    }),
  onSuccess: ({ data }) => {
    open.value = false
    openDock(data.conversation_id ?? undefined)
  },
  onError: (caught: unknown) => toastError(t, 'components.aiBulk.failed', caught),
})

const canStart = computed(
  () =>
    !!preflight.value?.count &&
    (skill.value !== null || prompt.value.trim() !== '') &&
    !startMutation.isPending.value
)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-2xl!">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Icon
            name="ai:bot-message"
            class="text-ai"
          />
          {{ $t('components.aiBulk.title') }}
        </DialogTitle>
        <DialogDescription>{{ $t('components.aiBulk.description') }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[60vh] space-y-4 overflow-y-auto">
        <div
          v-if="skills?.length"
          class="flex flex-wrap gap-2"
          role="radiogroup"
          :aria-label="$t('components.aiBulk.skills')"
        >
          <Button
            v-for="option in skills"
            :key="option.name"
            size="sm"
            :variant="skill?.name === option.name ? 'ai' : 'outline'"
            role="radio"
            :aria-checked="skill?.name === option.name"
            :title="option.description"
            @click="skill = skill?.name === option.name ? null : option"
          >
            {{ aiSkillLabel(option.name, t) }}
          </Button>
        </div>

        <TextField
          id="ai-bulk-prompt"
          v-model="prompt"
          name="ai-bulk-prompt"
          :label="skill ? $t('components.aiBulk.extraInstructions') : $t('components.aiBulk.task')"
        />

        <div
          v-if="isFetching"
          class="flex justify-center py-4"
        >
          <Spinner />
        </div>
        <p
          v-else-if="error"
          class="text-sm text-destructive"
        >
          {{ $t('components.aiBulk.preflightFailed') }}
        </p>
        <div
          v-else-if="preflight"
          class="space-y-3 rounded-lg border border-border p-4 text-sm"
        >
          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-muted">{{ $t('components.aiBulk.entries') }}</div>
              <div class="font-semibold">{{ formatNumber(preflight.count) }}</div>
            </div>
            <div>
              <div class="text-muted">{{ $t('components.aiBulk.estimate') }}</div>
              <div class="font-semibold">
                {{ formatCurrency(preflight.cost_usd, 'USD') }}
              </div>
            </div>
          </div>
          <p class="text-muted">
            {{ $t('components.aiBulk.cap', { cap: formatCurrency(preflight.max_usd, 'USD'), model: preflight.model ?? '' }) }}
          </p>
          <p
            v-if="preflight.count === 0"
            class="text-warning"
          >
            {{ $t('components.aiBulk.nothing') }}
          </p>
          <ul
            v-else
            class="max-h-40 space-y-1 overflow-y-auto"
          >
            <li
              v-for="entry in preflight.entries"
              :key="entry.id"
              class="truncate"
            >
              {{ entry.name }}
            </li>
            <li
              v-if="preflight.count > preflight.entries.length"
              class="text-muted"
            >
              {{ $t('components.aiBulk.more', { count: preflight.count - preflight.entries.length }) }}
            </li>
          </ul>
        </div>
      </div>

      <DialogFooter>
        <Button
          variant="ghost"
          @click="open = false"
        >
          {{ $t('components.aiBulk.cancel') }}
        </Button>
        <Button
          variant="ai"
          :disabled="!canStart"
          :loading="startMutation.isPending.value"
          @click="startMutation.mutate()"
        >
          {{ $t('components.aiBulk.confirm', { count: preflight?.count ?? 0 }) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
