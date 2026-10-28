<script setup lang="ts">
import AiSuggestionActions from '~/components/ai/AiSuggestionActions.vue'
import type { AiThreadMessage } from '~/components/ai/threads/AiThreadComposer.vue'
import AiThreadComposer from '~/components/ai/threads/AiThreadComposer.vue'
import AiThreadTurn from '~/components/ai/threads/AiThreadTurn.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { Input } from '~/components/ui/input'
import {
  MessageScroller,
  MessageScrollerButton,
  MessageScrollerContent,
  MessageScrollerItem,
  MessageScrollerProvider,
  MessageScrollerViewport,
} from '~/components/ui/message-scroller'
import { Skeleton } from '~/components/ui/skeleton'
import {
  aiDock,
  aiThreadRunKey,
  beginLiveTurn,
  endLiveTurn,
  takeDraft,
  threadCreated,
  trackLiveStep,
} from '~/lib/aiDock'
import type { AiQuestionnaireAnswers } from '~/lib/aiRun'
import type { AiThreadChip } from '~/lib/aiThreads'
import { chipsToRunContext, sameChip, THREAD_STATUS_STYLES } from '~/lib/aiThreads'

/**
 * A thread, or a new one when `threadId` is null: header with the thread
 * menu, its turns with the live run streaming at the end, and the composer.
 * The run is the one the space layout provides, so it outlives this view.
 */
const props = defineProps<{
  spaceId: string
  threadId: string | null
  layout: 'dock' | 'page'
}>()

const emit = defineEmits<{
  back: []
  expand: []
  close: []
  created: [threadId: string]
}>()

const { t } = useI18n()
const { showAiError } = useAiErrorToast()
const provided = inject(aiThreadRunKey)
if (!provided) throw new Error('AiThreadView needs the thread run of the space layout')
const run = provided

const {
  useThreadQuery,
  refresh,
  updateMutation,
  answerMutation,
  cancelMutation,
  acceptSuggestionMutation,
  dismissSuggestionMutation,
} = useAiThreads(() => props.spaceId)
const { data: thread, isLoading } = useThreadQuery(() => props.threadId)
const { data: skills } = useAiSurfaceSkills(() => props.spaceId, 'cmdk')
const screenChips = useAiScreenChips(() => props.spaceId)

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManage = computed(() => access.hasAbility('ai.manage'))

const isNew = computed(() => props.threadId === null)
const isOwner = computed(() => isNew.value || !!thread.value?.is_owner)
const isChat = computed(() => isNew.value || thread.value?.kind === 'chat')
const canEdit = computed(() => isOwner.value || (!isChat.value && canManage.value))

const live = computed(() => {
  const turn = aiDock.state.live
  return turn && turn.threadId === props.threadId ? turn : null
})
const liveRunId = computed(() => (live.value ? run.runId.value : null))
const turns = computed(() => (thread.value?.turns ?? []).filter((turn) => turn.run.id !== liveRunId.value))

const composer = useTemplateRef<InstanceType<typeof AiThreadComposer>>('composer')
const chips = ref<AiThreadChip[]>([])
const skill = ref<string | null>(null)
const prompt = ref<string | null>(null)
const ghostChips = computed(() => screenChips.value.filter((chip) => !chips.value.some((attached) => sameChip(attached, chip))))

watch(
  () => thread.value?.context,
  (context) => {
    if (!isNew.value) chips.value = [...(context ?? [])]
  },
  { immediate: true }
)

const send = async (message: AiThreadMessage) => {
  if (run.isActive.value) return
  const threadId = props.threadId
  // A run waiting for answers is not active: sending replaces it as the live
  // turn and leaves it answerable from its own turn in the thread.
  const turn = beginLiveTurn({ threadId, prompt: message.prompt, attachments: message.attachments, skill: message.skill })

  const outcome = await run.start(
    {
      surface: 'cmdk',
      skill: message.skill,
      prompt: message.prompt,
      context: { ...chipsToRunContext(chips.value), mentions: message.mentions },
      config_id: message.configId,
      conversation_id: threadId,
      attachments: message.attachments.map((attachment) => attachment.id),
    },
    {
      onEvent: (event) => {
        if (event.type === 'run_started' && event.conversation_id && !threadId) {
          threadCreated(event.conversation_id)
          emit('created', event.conversation_id)
        } else if (event.type === 'tool_started') {
          trackLiveStep(event)
        } else if (event.type === 'tool_finished') {
          trackLiveStep(event, { ok: event.ok })
        }
      },
    }
  )

  if (outcome.status === 'failed') {
    showAiError(outcome.error?.reason, outcome.error?.message)
    // A refused run has no turn to show the prompt in; give it back to the composer.
    if (!prompt.value) prompt.value = message.prompt
    composer.value?.restore(message.attachments)
  }
  await refresh()
  endLiveTurn(turn)
}

/** A new thread starts from the draft Cmd+K or a feature left, with the screen attached. */
watch(
  () => aiDock.state.draft,
  (draft) => {
    if (!draft || !isNew.value) return
    takeDraft()
    chips.value = [...(draft.context ?? screenChips.value)]
    skill.value = draft.skill
    prompt.value = draft.prompt
    if (draft.send) void send({ prompt: draft.prompt, skill: draft.skill, configId: null, mentions: [], attachments: [] })
  },
  { immediate: true }
)

watch(
  isNew,
  (fresh) => {
    if (fresh && !aiDock.state.draft && chips.value.length === 0) chips.value = [...screenChips.value]
  },
  { immediate: true }
)

const answer = (runId: string, answers: AiQuestionnaireAnswers) => {
  if (live.value && runId === run.runId.value) void run.answer(answers)
  else answerMutation.mutate({ runId, answers })
}

const cancel = (runId: string) => {
  if (live.value && runId === run.runId.value) void run.cancel()
  else cancelMutation.mutate(runId)
}

const title = computed(() => (isNew.value ? t('components.aiThreads.newThread') : (thread.value?.title ?? '')))

const renaming = ref(false)
const draftTitle = ref('')
const startRename = () => {
  draftTitle.value = thread.value?.title ?? ''
  renaming.value = true
}
const saveTitle = () => {
  const value = draftTitle.value.trim()
  renaming.value = false
  if (props.threadId && value && value !== thread.value?.title) {
    updateMutation.mutate({ threadId: props.threadId, patch: { title: value } })
  }
}

const patch = (change: { shared?: boolean; archived?: boolean }) => {
  if (props.threadId) updateMutation.mutate({ threadId: props.threadId, patch: change })
}

const showComposer = computed(() => isNew.value || (isChat.value && isOwner.value))
const suggestionsBusy = computed(
  () => acceptSuggestionMutation.isPending.value || dismissSuggestionMutation.isPending.value
)
</script>

<template>
  <div class="flex h-full min-h-0 flex-col bg-background">
    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-border bg-sidebar px-3 text-sidebar-foreground">
      <Button
        v-if="layout === 'dock'"
        size="toolbar"
        variant="ghost"
        :title="$t('components.aiThreads.allThreads')"
        :aria-label="$t('components.aiThreads.allThreads')"
        @click="emit('back')"
      >
        <Icon name="lucide:chevron-left" />
      </Button>
      <Icon
        v-if="thread && !thread.archived"
        :name="THREAD_STATUS_STYLES[thread.status].icon"
        :class="['size-4 shrink-0', THREAD_STATUS_STYLES[thread.status].class]"
        :aria-label="$t(`components.aiThreads.status.${thread.status}`)"
      />
      <Input
        v-if="renaming"
        v-model="draftTitle"
        class="h-8 min-w-0 flex-1"
        :aria-label="$t('components.aiThreads.titleLabel')"
        autofocus
        @keydown.enter.prevent="saveTitle"
        @keydown.esc.prevent="renaming = false"
        @blur="saveTitle"
      />
      <h2
        v-else
        class="min-w-0 flex-1 truncate font-semibold text-primary"
      >
        {{ title }}
      </h2>
      <Badge
        v-if="thread?.archived"
        variant="surface"
        size="sm"
      >
        {{ $t('components.aiThreads.settled') }}
      </Badge>

      <DropdownMenu v-if="thread && canEdit">
        <DropdownMenuTrigger as-child>
          <Button
            size="toolbar"
            variant="ghost"
            :aria-label="$t('components.aiThreads.menu')"
          >
            <Icon name="lucide:ellipsis" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem @select="startRename">
            <Icon name="lucide:pencil" />
            {{ $t('components.aiThreads.rename') }}
          </DropdownMenuItem>
          <DropdownMenuCheckboxItem
            v-if="thread.kind === 'chat' && thread.is_owner"
            :model-value="thread.shared"
            @select="patch({ shared: !thread.shared })"
          >
            {{ $t('components.aiThreads.share') }}
          </DropdownMenuCheckboxItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem @select="patch({ archived: !thread.archived })">
            <Icon :name="thread.archived ? 'lucide:undo-2' : 'lucide:check'" />
            {{ thread.archived ? $t('components.aiThreads.unsettle') : $t('components.aiThreads.settle') }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        v-if="layout === 'dock'"
        size="toolbar"
        variant="ghost"
        :title="$t('components.aiThreads.expand')"
        :aria-label="$t('components.aiThreads.expand')"
        @click="emit('expand')"
      >
        <Icon name="lucide:maximize-2" />
      </Button>
      <Button
        v-if="layout === 'dock'"
        size="toolbar"
        variant="ghost"
        :title="$t('components.aiThreads.close')"
        :aria-label="$t('components.aiThreads.close')"
        @click="emit('close')"
      >
        <Icon name="lucide:x" />
      </Button>
    </header>

    <MessageScrollerProvider
      auto-scroll
      default-scroll-position="end"
    >
      <MessageScroller class="min-h-0 flex-1">
        <MessageScrollerViewport>
          <MessageScrollerContent
            class="gap-6 p-4"
            :class="layout === 'page' && 'mx-auto w-full max-w-3xl'"
          >
            <template v-if="isLoading">
              <Skeleton class="ml-auto h-9 w-2/3 rounded-xl" />
              <Skeleton class="h-4 w-full" />
              <Skeleton class="h-4 w-4/5" />
            </template>

            <template v-else-if="thread?.kind === 'suggestions'">
              <p
                v-if="!thread.suggestions?.length"
                class="text-sm text-muted"
              >
                {{ $t('components.aiThreads.suggestions.empty') }}
              </p>
              <MessageScrollerItem
                v-for="suggestion in thread.suggestions"
                :key="suggestion.id"
                :message-id="suggestion.id"
              >
                <section class="grid gap-2 rounded-xl border border-border bg-card p-4 shadow-soft-sm">
                  <h3 class="font-semibold text-primary">{{ suggestion.term }}</h3>
                  <p class="text-sm text-muted">
                    {{ Object.entries(suggestion.translations).map(([code, value]) => `${code.toUpperCase()} ${value}`).join(', ') }}
                  </p>
                  <p
                    v-if="suggestion.evidence"
                    class="text-sm"
                  >
                    {{ $t('components.aiThreads.suggestions.evidence', { ai: suggestion.evidence.ai, final: suggestion.evidence.final }) }}
                  </p>
                  <AiSuggestionActions
                    :space-id="spaceId"
                    :term="suggestion.term"
                    :glossaries="thread.glossaries ?? []"
                    :disabled="suggestionsBusy"
                    @accept="(glossaryId) => acceptSuggestionMutation.mutate({ suggestionId: suggestion.id, glossaryId })"
                    @dismiss="dismissSuggestionMutation.mutate(suggestion.id)"
                  />
                </section>
              </MessageScrollerItem>
            </template>

            <template v-else>
              <MessageScrollerItem
                v-for="turn in turns"
                :key="turn.run.id"
                :message-id="turn.run.id"
              >
                <AiThreadTurn
                  :space-id="spaceId"
                  :prompt="turn.prompt"
                  :attachments="turn.attachments"
                  :skill="turn.run.skill"
                  :state="turn.run.state"
                  :answer="turn.answer ?? turn.run.result?.text ?? null"
                  :questionnaire="turn.run.questionnaire"
                  :error="turn.run.error"
                  :changeset="turn.changeset"
                  :can-act="isOwner"
                  :busy="answerMutation.isPending.value || cancelMutation.isPending.value"
                  @answer="(answers) => answer(turn.run.id, answers)"
                  @cancel="cancel(turn.run.id)"
                />
              </MessageScrollerItem>
              <MessageScrollerItem
                v-if="live"
                message-id="live"
              >
                <AiThreadTurn
                  :space-id="spaceId"
                  :prompt="live.prompt"
                  :attachments="live.attachments"
                  :skill="live.skill"
                  :state="run.status.value === 'awaiting_input' ? 'awaiting_input' : run.isActive.value ? 'running' : 'completed'"
                  :answer="run.text.value || null"
                  :questionnaire="run.questionnaire.value"
                  :error="null"
                  :changeset="null"
                  :steps="live.steps"
                  can-act
                  @answer="(answers) => run.answer(answers)"
                  @cancel="run.cancel()"
                />
              </MessageScrollerItem>
              <p
                v-if="isNew && !live"
                class="text-sm text-muted"
              >
                {{ $t('components.aiThreads.emptyThread') }}
              </p>
            </template>
          </MessageScrollerContent>
        </MessageScrollerViewport>
        <MessageScrollerButton />
      </MessageScroller>
    </MessageScrollerProvider>

    <div
      v-if="showComposer"
      class="shrink-0 px-3 pb-3"
      :class="layout === 'page' && 'mx-auto w-full max-w-3xl'"
    >
      <AiThreadComposer
        ref="composer"
        v-model:chips="chips"
        v-model:skill="skill"
        v-model:prompt="prompt"
        :space-id="spaceId"
        :skills="skills ?? []"
        :ghost-chips="ghostChips"
        :running="run.isActive.value"
        @send="send"
        @cancel="run.cancel()"
      />
    </div>
    <p
      v-else-if="thread && thread.kind === 'chat' && !thread.is_owner"
      class="shrink-0 border-t border-border px-4 py-3 text-sm text-muted"
    >
      {{ $t('components.aiThreads.ownerOnly', { name: thread.owner?.name ?? '' }) }}
    </p>
  </div>
</template>
