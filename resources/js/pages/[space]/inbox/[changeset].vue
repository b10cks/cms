<script setup lang="ts">
import AiChangesetReview from '~/components/ai/AiChangesetReview.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { AiChangeset } from '~/lib/aiChangesets'

const route = useRoute()
const { t } = useI18n()
const { formatRelativeTime, formatCurrency } = useFormat()
const spaceId = computed(() => route.params.space as string)
const changesetId = computed(() => route.params.changeset as string)

const { useChangesetQuery } = useAiInbox(spaceId)
const { data: changeset } = useChangesetQuery(changesetId)

const byline = (current: AiChangeset) =>
  [
    current.actor?.name,
    formatRelativeTime(current.created_at),
    current.run ? t('components.aiChangeset.cost', { cost: formatCurrency(current.run.cost_usd, 'USD') }) : null,
    t('components.aiChangeset.meta', { pending: current.pending_count, total: current.item_count }),
  ]
    .filter(Boolean)
    .join(', ')

useSeoMeta({
  title: computed(() => changeset.value?.title ?? t('components.aiInbox.title')),
})
</script>

<template>
  <div class="content-grid">
    <AiChangesetReview
      :key="changesetId"
      :space-id="spaceId"
      :changeset-id="changesetId"
      keyboard
    >
      <template #header="{ changeset: current, busy, accepting, acceptAll, rejectAll }">
        <ContentHeader
          :header="current.title"
          :description="$t('components.aiChangeset.keyboardHint')"
        >
          <template #start>
            <RouterLink
              :to="{ name: 'space-ai-inbox', params: { space: spaceId } }"
              class="flex w-fit items-center gap-2 rounded-sm text-sm text-muted hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
              <Icon name="lucide:chevron-left" />
              {{ $t('components.aiChangeset.backToInbox') }}
            </RouterLink>
          </template>
          <p class="text-sm text-muted">{{ byline(current) }}</p>
          <template #actions>
            <div class="flex items-center gap-2">
              <Button
                variant="outline"
                :disabled="busy || current.pending_count === 0"
                @click="rejectAll"
              >
                {{ $t('components.aiChangeset.discard') }}
              </Button>
              <Button
                variant="ai"
                :disabled="busy || current.pending_count === 0"
                :loading="accepting"
                @click="acceptAll"
              >
                {{ $t('components.aiChangeset.acceptAll') }}
              </Button>
            </div>
          </template>
        </ContentHeader>
      </template>
    </AiChangesetReview>
  </div>
</template>
