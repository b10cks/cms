<script setup lang="ts">
import AiChangesetReview from '~/components/ai/AiChangesetReview.vue'
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { aiSkillLabel } from '~/lib/aiSkills'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { formatRelativeTime } = useFormat()
const spaceId = computed(() => route.params.space as string)

const { useInboxQuery, answerMutation, cancelMutation } = useAiInbox(spaceId)
const { data: inbox, isLoading } = useInboxQuery()

const changesets = computed(() => inbox.value?.changesets ?? [])
const runs = computed(() => inbox.value?.runs ?? [])

const selectedId = computed(() => {
  const requested = route.query.changeset
  if (typeof requested === 'string') return requested
  return changesets.value[0]?.id ?? null
})

const select = (id: string) => void router.replace({ query: { ...route.query, changeset: id } })

const runLabel = (skill: string | null, surface: string) =>
  skill
    ? aiSkillLabel(skill, t)
    : t(`components.aiInbox.surfaces.${surface.startsWith('bulk:') ? 'bulk' : 'agent'}`)

useSeoMeta({
  title: computed(() => t('components.aiInbox.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('components.aiInbox.title')"
      :description="$t('components.aiInbox.description')"
    />
    <div class="grid gap-6 pt-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
      <div class="flex min-w-0 flex-col gap-6">
        <Panel
          :title="$t('components.aiInbox.changesets')"
          :meta="changesets.length ? String(changesets.length) : undefined"
          flush
        >
          <p
            v-if="!isLoading && changesets.length === 0"
            class="px-5 pb-5 text-sm text-muted"
          >
            {{ $t('components.aiInbox.empty') }}
          </p>
          <ul class="divide-y divide-border border-t border-border">
            <li
              v-for="changeset in changesets"
              :key="changeset.id"
            >
              <button
                type="button"
                class="flex w-full flex-col items-start gap-1 px-5 py-3 text-left hover:bg-tree-hover"
                :class="{ 'bg-tree-selected': changeset.id === selectedId }"
                @click="select(changeset.id)"
              >
                <span class="w-full truncate font-medium">{{ changeset.title }}</span>
                <span class="flex items-center gap-2 text-sm text-muted">
                  {{ $t('components.aiChangeset.meta', { pending: changeset.pending_count, total: changeset.item_count }) }}
                  <Badge
                    v-if="changeset.run?.partial"
                    variant="warning"
                    size="sm"
                  >
                    {{ $t('components.aiInbox.partial') }}
                  </Badge>
                </span>
                <span class="text-sm text-muted">
                  {{ changeset.actor?.name }}, {{ formatRelativeTime(changeset.created_at) }}
                </span>
              </button>
            </li>
          </ul>
        </Panel>

        <Panel
          v-if="runs.length > 0"
          :title="$t('components.aiInbox.runs')"
        >
          <ul class="space-y-4">
            <li
              v-for="run in runs"
              :key="run.id"
              class="space-y-2"
            >
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate font-medium">{{ runLabel(run.skill, run.surface) }}</span>
                <Badge
                  :variant="run.state === 'failed' ? 'destructive' : run.state === 'awaiting_input' ? 'warning' : 'info'"
                  size="sm"
                >
                  {{ $t(`components.aiInbox.states.${run.state}`) }}
                </Badge>
              </div>
              <p
                v-if="run.state === 'failed' && run.error"
                class="text-sm text-destructive"
              >
                {{ run.error }}
              </p>
              <AiQuestionnaireForm
                v-if="run.state === 'awaiting_input' && run.questionnaire"
                :questionnaire="run.questionnaire"
                :disabled="answerMutation.isPending.value"
                @submit="(answers) => answerMutation.mutate({ runId: run.id, answers })"
              />
              <Button
                v-if="run.state === 'running' || run.state === 'awaiting_input'"
                size="xs"
                variant="ghost"
                :disabled="cancelMutation.isPending.value"
                @click="cancelMutation.mutate(run.id)"
              >
                {{ $t('components.aiInbox.cancel') }}
              </Button>
            </li>
          </ul>
        </Panel>
      </div>

      <AiChangesetReview
        v-if="selectedId"
        :key="selectedId"
        :space-id="spaceId"
        :changeset-id="selectedId"
      />
    </div>
  </div>
</template>
