<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

const props = defineProps<{
  spaceId: string
  content?: string
  anchor?: string
  contentName: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  pick: []
  clearAnchor: []
}>()

const { t } = useI18n()

const { findAnchor, isLoaded } = useContentAnchors(
  props.spaceId,
  computed(() => (props.anchor ? props.content : null))
)

const selectedAnchor = computed(() => findAnchor(props.anchor))
// The block was deleted or never belonged to this content, so the link would land on the page top.
const isAnchorMissing = computed(() => !!props.anchor && isLoaded.value && !selectedAnchor.value)
</script>

<template>
  <div class="space-y-2">
    <button
      type="button"
      class="text-input-foreground flex min-h-[2.5rem] w-full items-center gap-2 rounded-md border border-input-border bg-input px-3 py-2 text-left text-sm"
      :disabled="disabled"
      @click="emit('pick')"
    >
      <span
        v-if="content"
        class="flex min-w-0 items-center gap-1 font-semibold"
      >
        <span class="shrink-0">{{ contentName }}</span>
        <template v-if="anchor">
          <Icon
            name="lucide:chevron-right"
            class="shrink-0 text-muted-foreground"
          />
          <span
            v-if="selectedAnchor"
            class="truncate font-normal [&_img]:inline [&_img]:size-4"
            :title="selectedAnchor.block.name"
            v-html="selectedAnchor.title"
          />
          <span
            v-else
            class="truncate font-mono text-xs font-normal text-muted-foreground"
          >
            #{{ anchor }}
          </span>
        </template>
      </span>
      <span
        v-else
        class="text-muted-foreground"
      >
        {{ t('labels.link.noContentSelected') }}
      </span>
      <Icon
        name="lucide:search"
        class="ml-auto shrink-0"
      />
    </button>

    <div
      v-if="isAnchorMissing"
      class="flex items-center gap-2 text-sm text-warning"
    >
      <Icon
        name="lucide:triangle-alert"
        class="shrink-0"
      />
      <span class="grow">{{ t('labels.link.anchorMissing') }}</span>
      <Button
        type="button"
        variant="ghost"
        size="xs"
        :disabled="disabled"
        @click="emit('clearAnchor')"
      >
        {{ t('labels.link.linkToPage') }}
      </Button>
    </div>
  </div>
</template>
