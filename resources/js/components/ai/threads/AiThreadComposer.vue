<script setup lang="ts">
import type { AiReasoningEffort, AiResolvedSkill, MentionItem } from '~/api/resources/ai'
import AiConfigPicker from '~/components/ai/AiConfigPicker.vue'
import AiReasoningPicker from '~/components/ai/AiReasoningPicker.vue'
import AiThreadQuestions from '~/components/ai/threads/AiThreadQuestions.vue'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputGroupTipTap } from '~/components/ui/input-group'
import { useAiMentions } from '~/composables/useAiMentions'
import type { AiAttachment, AiAttachmentKind } from '~/lib/aiAttachments'
import { ATTACHMENT_ACCEPT, ATTACHMENT_LIMITS, checkAttachment, formatMegabytes, MAX_ATTACHMENTS } from '~/lib/aiAttachments'
import { useAiReasoning } from '~/lib/aiReasoning'
import type { AiQuestionnaire, AiQuestionnaireAnswers } from '~/lib/aiRun'
import type { AiThreadChip } from '~/lib/aiThreads'
import { sameChip } from '~/lib/aiThreads'
import { aiSkillLabel } from '~/lib/aiSkills'

export interface AiThreadMessage {
  prompt: string
  skill: string | null
  configId: string | null
  /** Null for models that don't reason */
  reasoningEffort: AiReasoningEffort | null
  mentions: MentionItem[]
  attachments: AiAttachment[]
}

/** A file in the composer: uploading until `attachment` is set. */
interface ComposerFile {
  key: string
  name: string
  kind: AiAttachmentKind
  progress: number
  attachment: AiAttachment | null
  controller: AbortController
}

/**
 * The thread composer, modelled on T3 Code: one rounded surface with a strip
 * of context chips and files above the editor, and a footer with attach, the
 * config picker and the send button. Files upload as they are added, by the
 * paperclip, paste or drop. `@` mentions entries, assets and knowledge
 * documents; `/` picks a skill. Next to the config picker, models that reason
 * get the reasoning bars; the level starts at the thread's last one, else the
 * config's default.
 *
 * While a run waits for answers, its questions sit as a tray on top and the
 * composer is the answer form: Send takes the selected option, or the typed
 * text as an own answer, and moves on; after the last question it submits.
 */
const props = defineProps<{
  spaceId: string
  skills: readonly AiResolvedSkill[]
  /** Chips the screen offers that the thread doesn't have yet */
  ghostChips: readonly AiThreadChip[]
  running: boolean
  /** Questions of a paused run the user answers here */
  questionnaire?: AiQuestionnaire | null
  /** The reasoning level of the thread's last run */
  threadReasoning?: AiReasoningEffort | null
}>()

const chips = defineModel<AiThreadChip[]>('chips', { required: true })
const skill = defineModel<string | null>('skill', { required: true })
const prompt = defineModel<string | null>('prompt', { required: true })

const emit = defineEmits<{
  send: [message: AiThreadMessage]
  cancel: []
  answer: [answers: AiQuestionnaireAnswers]
}>()

const { t } = useI18n()
const { uploadAttachment } = useAiThreads(() => props.spaceId)
const editor = ref<InstanceType<typeof InputGroupTipTap> | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const configId = ref<string | null>(null)
const {
  modelInfo,
  level: reasoning,
  effort: reasoningEffort,
} = useAiReasoning(() => props.spaceId, configId, () => props.threadReasoning ?? null)

const { useMentionItemsQuery } = useAiMentions(computed(() => props.spaceId))
const { items: baseMentionItems } = useMentionItemsQuery()
const { useDocumentsQuery } = useAiKnowledge(() => ({ kind: 'space' as const, id: props.spaceId }))
const { data: documents } = useDocumentsQuery()
const { useAssetsQuery } = useAssets(computed(() => props.spaceId))
const { data: assets } = useAssetsQuery({ per_page: 50, sort: '-updated_at' })

const mentionItems = computed<AiMentionItem[]>(() => {
  const items: AiMentionItem[] = [
    ...baseMentionItems.value,
    ...(assets.value?.data ?? []).map((asset) => ({
      id: asset.id,
      label: asset.filename,
      type: 'asset' as const,
      icon: 'lucide:image',
    })),
    ...(documents.value ?? []).map((document) => ({
      id: document.id,
      label: document.title,
      type: 'document' as const,
      icon: 'lucide:book-open',
    })),
  ]
  const seen = new Set<string>()
  return items.filter((item) => {
    const key = `${item.type}:${item.id}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
})

const commands = computed(() =>
  props.skills.map((option) => ({ id: option.name, label: aiSkillLabel(option.name, t), description: option.description }))
)

const pickSkill = (name: string) => {
  skill.value = name
  editor.value?.focus()
}

const attach = (chip: AiThreadChip) => {
  if (!chips.value.some((attached) => sameChip(attached, chip))) chips.value = [...chips.value, chip]
}

const detach = (chip: AiThreadChip) => {
  chips.value = chips.value.filter((attached) => !sameChip(attached, chip))
}

const CHIP_ICONS = {
  content: 'lucide:file-text',
  field: 'lucide:text-cursor-input',
  data_source: 'lucide:database',
  asset: 'lucide:image',
  asset_folder: 'lucide:folder',
  block: 'lucide:blocks',
  document: 'lucide:book-open',
} as const satisfies Record<AiThreadChip['type'], string>

const files = ref<ComposerFile[]>([])
const fileError = ref<string | null>(null)
const uploading = computed(() => files.value.some((file) => !file.attachment))

const upload = async (entry: ComposerFile, file: File) => {
  try {
    const attachment = await uploadAttachment(file, {
      signal: entry.controller.signal,
      onProgress: (progress) => {
        const current = files.value.find((candidate) => candidate.key === entry.key)
        if (current) current.progress = progress
      },
    })
    const current = files.value.find((candidate) => candidate.key === entry.key)
    if (current) current.attachment = attachment
  } catch (error) {
    if (entry.controller.signal.aborted) return
    files.value = files.value.filter((candidate) => candidate.key !== entry.key)
    fileError.value = t('components.aiThreads.files.uploadFailed', {
      name: file.name,
      error: error instanceof Error ? error.message : String(error),
    })
  }
}

/** Check and upload each file; the first problem is shown, the other files still go. */
const addFiles = (incoming: readonly File[]) => {
  fileError.value = null
  for (const file of incoming) {
    if (files.value.length >= MAX_ATTACHMENTS) {
      fileError.value = t('components.aiThreads.files.tooMany', { max: MAX_ATTACHMENTS })
      break
    }
    const check = checkAttachment(file)
    if (!check.ok) {
      fileError.value ??=
        check.reason === 'type'
          ? t('components.aiThreads.files.unsupported', { name: file.name })
          : t('components.aiThreads.files.tooLarge', {
              name: file.name,
              max: formatMegabytes(ATTACHMENT_LIMITS[check.kind]),
            })
      continue
    }
    const entry: ComposerFile = {
      key: crypto.randomUUID(),
      name: file.name,
      kind: check.kind,
      progress: 0,
      attachment: null,
      controller: new AbortController(),
    }
    files.value = [...files.value, entry]
    void upload(entry, file)
  }
}

const removeFile = (key: string) => {
  files.value.find((file) => file.key === key)?.controller.abort()
  files.value = files.value.filter((file) => file.key !== key)
}

const onPickFiles = (event: Event) => {
  if (!(event.target instanceof HTMLInputElement)) return
  addFiles([...(event.target.files ?? [])])
  event.target.value = ''
}

const filesOf = (transfer: DataTransfer | null) => [...(transfer?.files ?? [])]

const onPaste = (event: ClipboardEvent) => {
  const pasted = filesOf(event.clipboardData)
  if (pasted.length === 0) return
  event.preventDefault()
  event.stopPropagation()
  addFiles(pasted)
}

const dragging = ref(false)
const carriesFiles = (event: DragEvent) => !!event.dataTransfer?.types.includes('Files')

const onDragOver = (event: DragEvent) => {
  if (!carriesFiles(event)) return
  event.preventDefault()
  dragging.value = true
}

const onDragLeave = (event: DragEvent) => {
  if (!(event.currentTarget instanceof Node) || !(event.relatedTarget instanceof Node)) dragging.value = false
  else if (!event.currentTarget.contains(event.relatedTarget)) dragging.value = false
}

const onDrop = (event: DragEvent) => {
  dragging.value = false
  if (!carriesFiles(event)) return
  event.preventDefault()
  event.stopPropagation()
  addFiles(filesOf(event.dataTransfer))
}

const hasText = computed(() => (prompt.value?.trim().length ?? 0) > 0)

const questionIndex = ref(0)
const answers = ref<AiQuestionnaireAnswers>({})
const selected = ref<string | null>(null)
const question = computed(() => props.questionnaire?.questions[questionIndex.value] ?? null)

watch(
  () => props.questionnaire,
  () => {
    questionIndex.value = 0
    answers.value = {}
    selected.value = null
  }
)

/** Show a question with its earlier answer: an option is selected, own text goes back to the editor. */
const showQuestion = (index: number) => {
  questionIndex.value = index
  const next = props.questionnaire?.questions[index]
  const answer = next ? (answers.value[next.id] ?? null) : null
  const isOption = !!next?.options.some((option) => option.id === answer)
  selected.value = isOption ? answer : null
  prompt.value = isOption ? null : answer
  if (!prompt.value) editor.value?.clear()
}

const commitAnswer = (value: string | null) => {
  const questionnaire = props.questionnaire
  if (!questionnaire || !question.value) return
  answers.value = { ...answers.value, [question.value.id]: value }
  if (questionIndex.value < questionnaire.questions.length - 1) {
    showQuestion(questionIndex.value + 1)
    editor.value?.focus()
  } else {
    emit('answer', { ...answers.value })
  }
}

/** Number keys pick an option while the editor is empty; once there is text they type. */
const onKeydown = (event: KeyboardEvent) => {
  const options = question.value?.options ?? []
  if (options.length === 0 || hasText.value || event.metaKey || event.ctrlKey || event.altKey) return
  if (!/^[1-9]$/.test(event.key)) return
  const option = options[Number(event.key) - 1]
  if (!option) return
  event.preventDefault()
  selected.value = option.id
}

const canSend = computed(() => {
  if (props.running) return false
  if (question.value) return hasText.value || !!selected.value || !question.value.required
  return (hasText.value || !!skill.value) && !uploading.value && !!configId.value
})

const send = () => {
  if (!canSend.value) return
  if (question.value) {
    commitAnswer(hasText.value ? (editor.value?.getText() ?? prompt.value ?? '').trim() : selected.value)
    return
  }
  const text = (editor.value?.getTextWithMentions() ?? prompt.value ?? '').trim()
  const mentions = (editor.value?.getMentions() ?? []).map((mention) => ({
    type: mention.type,
    id: mention.id,
    content: null,
    label: mention.label,
  }))
  emit('send', {
    prompt: text,
    skill: skill.value,
    configId: configId.value,
    reasoningEffort: reasoningEffort.value,
    mentions,
    attachments: files.value.flatMap((file) => (file.attachment ? [file.attachment] : [])),
  })
  skill.value = null
  prompt.value = null
  files.value = []
  fileError.value = null
  editor.value?.clear()
}

const showStrip = computed(
  () =>
    !question.value &&
    (!!skill.value || chips.value.length > 0 || props.ghostChips.length > 0 || files.value.length > 0)
)

const placeholder = computed(() => {
  if (question.value) {
    return question.value.options.length > 0
      ? t('components.aiThreads.questions.ownAnswer')
      : t('components.aiThreads.questions.typeAnswer')
  }
  return skill.value ? t('components.aiThreads.extraInstructions') : t('components.aiThreads.placeholder')
})

const controlClass =
  'inline-flex h-7 shrink-0 cursor-pointer items-center justify-center gap-1 rounded-md px-2 text-sm text-muted transition-colors outline-none hover:bg-muted-background hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50'

/** Put already uploaded files back, for a message the server refused. */
const restore = (attachments: readonly AiAttachment[]) => {
  files.value = [
    ...files.value,
    ...attachments.map((attachment) => ({
      key: attachment.id,
      name: attachment.name,
      kind: attachment.kind,
      progress: 100,
      attachment,
      controller: new AbortController(),
    })),
  ]
}

defineExpose({ focus: () => editor.value?.focus(), restore })
</script>

<template>
  <div class="grid gap-1.5">
    <div
      class="relative rounded-2xl border bg-card shadow-soft-sm transition-colors [&_.tiptap]:min-h-6! [&_.tiptap]:p-0! [&_.is-editor-empty]:after:hidden! [&_.tiptap-editor]:px-4 [&_.tiptap-editor]:py-2"
      :class="dragging ? 'border-ai' : 'border-border focus-within:border-border-strong'"
      @paste.capture="onPaste"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop.capture="onDrop"
      @keydown.capture="onKeydown"
    >
      <AiThreadQuestions
        v-if="question && questionnaire"
        v-model:selected="selected"
        :question="question"
        :index="questionIndex"
        :total="questionnaire.questions.length"
        :overridden="hasText"
        @previous="showQuestion(questionIndex - 1)"
        @skip="commitAnswer(null)"
      />
      <div
        v-if="showStrip"
        class="flex flex-wrap items-center gap-1.5 px-2 pt-2"
        data-composer-strip
      >
        <span
          v-if="skill"
          class="inline-flex h-7 items-center gap-1 rounded-md bg-ai-background/20 pr-1 pl-2 text-sm text-ai"
        >
          <Icon name="ai:bot-star" />
          {{ aiSkillLabel(skill, t) }}
          <button
            type="button"
            class="flex size-5 items-center justify-center rounded-sm hover:bg-ai-background/40"
            :aria-label="$t('components.aiThreads.removeSkill')"
            @click="skill = null"
          >
            <Icon name="lucide:x" />
          </button>
        </span>
        <span
          v-for="chip in chips"
          :key="`${chip.type}:${chip.id}`"
          class="inline-flex h-7 max-w-full items-center gap-1 rounded-md bg-chip pr-1 pl-2 text-sm text-chip-foreground"
          data-chip
        >
          <Icon :name="CHIP_ICONS[chip.type]" />
          <span class="truncate">{{ chip.label }}</span>
          <button
            type="button"
            class="flex size-5 shrink-0 items-center justify-center rounded-sm hover:bg-input"
            :aria-label="$t('components.aiThreads.removeChip', { name: chip.label })"
            @click="detach(chip)"
          >
            <Icon name="lucide:x" />
          </button>
        </span>
        <button
          v-for="chip in ghostChips"
          :key="`ghost:${chip.type}:${chip.id}`"
          type="button"
          class="inline-flex h-7 max-w-full items-center gap-1 rounded-md border border-dashed border-border-strong px-2 text-sm text-muted hover:text-foreground"
          :aria-label="$t('components.aiThreads.attachChip', { name: chip.label })"
          data-ghost-chip
          @click="attach(chip)"
        >
          <Icon name="lucide:plus" />
          <span class="truncate">{{ chip.label }}</span>
        </button>
        <span
          v-for="file in files"
          :key="file.key"
          class="relative inline-flex h-7 max-w-full items-center gap-1 overflow-hidden rounded-md bg-chip pr-1 text-sm text-chip-foreground"
          :class="file.kind === 'image' && file.attachment ? 'pl-0.5' : 'pl-2'"
          :aria-busy="file.attachment ? undefined : 'true'"
          data-file
        >
          <img
            v-if="file.kind === 'image' && file.attachment"
            :src="file.attachment.url"
            alt=""
            class="size-6 shrink-0 rounded-sm object-cover"
          />
          <Icon
            v-else
            :name="file.attachment ? 'lucide:file-text' : 'lucide:loader'"
            :class="!file.attachment && 'animate-spin'"
          />
          <span class="truncate">{{ file.name }}</span>
          <span
            v-if="!file.attachment"
            class="text-muted tabular-nums"
          >
            {{ file.progress }}%
          </span>
          <button
            type="button"
            class="flex size-5 shrink-0 items-center justify-center rounded-sm hover:bg-input"
            :aria-label="$t('components.aiThreads.removeChip', { name: file.name })"
            data-remove-file
            @click="removeFile(file.key)"
          >
            <Icon name="lucide:x" />
          </button>
          <span
            v-if="!file.attachment"
            class="absolute inset-x-0 bottom-0 h-0.5 bg-ai transition-[width]"
            :style="{ width: `${file.progress}%` }"
          />
        </span>
      </div>

      <InputGroupTipTap
        ref="editor"
        :model-value="prompt ?? ''"
        :placeholder="placeholder"
        :disabled="running"
        :mention-items="mentionItems"
        :commands="commands"
        @update:model-value="(value: string) => (prompt = value)"
        @submit="send"
        @command="(item) => pickSkill(item.id)"
      />

      <div class="flex items-center gap-1 px-2 pb-2">
        <AiConfigPicker
          v-show="!question"
          v-model="configId"
          :space-id="spaceId"
          :disabled="running"
        />
        <AiReasoningPicker
          v-if="modelInfo?.supports_reasoning"
          v-show="!question"
          v-model="reasoning"
          :required="modelInfo.reasoning_required"
          :disabled="running"
        />
        <span class="flex-1" />
        <button
          type="button"
          :class="controlClass"
          :title="$t('components.aiThreads.files.attach')"
          :aria-label="$t('components.aiThreads.files.attach')"
          :disabled="files.length >= MAX_ATTACHMENTS"
          v-show="!question"
          data-attach
          @click="fileInput?.click()"
        >
          <Icon
            name="lucide:paperclip"
            class="size-4"
          />
        </button>
        <input
          ref="fileInput"
          type="file"
          class="hidden"
          multiple
          :accept="ATTACHMENT_ACCEPT"
          data-file-input
          @change="onPickFiles"
        />
        <Button
          v-if="running"
          type="button"
          variant="primary"
          size="icon"
          class="size-8! rounded-full!"
          :aria-label="$t('actions.cancel')"
          @click="emit('cancel')"
        >
          <Icon name="lucide:square" />
        </Button>
        <Button
          v-else
          type="button"
          variant="primary"
          size="icon"
          class="size-8! rounded-full! disabled:bg-foreground/10! disabled:text-muted! disabled:opacity-100!"
          :disabled="!canSend"
          :aria-label="$t('actions.send')"
          data-send
          @click="send"
        >
          <Icon name="lucide:arrow-up" />
        </Button>
      </div>
    </div>
    <p
      v-if="fileError"
      class="px-1 text-sm text-destructive"
      role="alert"
    >
      {{ fileError }}
    </p>
  </div>
</template>
