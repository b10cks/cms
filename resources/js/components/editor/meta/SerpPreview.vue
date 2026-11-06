<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { fitSerp } from '~/lib/serpMetrics'

import type { MetaPreviewData } from './types'

/** A desktop Google result for the entry, cut at Google's pixel widths, with what falls off named below. */
const props = defineProps<{ data: MetaPreviewData }>()

const { t } = useI18n()

const title = computed(() => fitSerp(props.data.title, 'title'))
const description = computed(() => fitSerp(props.data.description, 'description'))

const trail = computed(() => props.data.url?.pathname.split('/').filter(Boolean) ?? [])

/** What Google drops, one line per part, so the lost words stay readable at any width. */
const cuts = computed(() =>
  [
    { key: 'title', label: t('components.metaPreview.titleCut'), text: title.value.cut },
    { key: 'description', label: t('components.metaPreview.descriptionCut'), text: description.value.cut },
  ].filter((cut) => cut.text)
)
</script>

<template>
  <div class="grid max-w-[652px] gap-1 font-[Arial,sans-serif]">
    <div class="flex min-w-0 items-center gap-3">
      <span class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-chip ring-1 ring-border">
        <NuxtImg
          v-if="data.siteIcon"
          :src="data.siteIcon"
          alt=""
          :width="28"
          :height="28"
          class="size-7 object-cover"
        />
        <Icon
          v-else
          name="lucide:globe"
          class="text-input-muted"
        />
      </span>
      <div class="min-w-0 leading-tight">
        <p class="truncate text-sm text-primary">{{ data.siteName }}</p>
        <p class="truncate text-sm text-muted">
          {{ data.url?.origin }}<template
            v-for="segment in trail"
            :key="segment"
            > › {{ segment }}</template
          >
        </p>
      </div>
    </div>

    <h3 class="mt-1 text-[20px] leading-[1.3] text-info-ink">
      {{ title.shown }}
    </h3>
    <p
      v-if="data.description"
      class="mt-1 text-sm leading-[1.58] text-muted"
    >
      {{ description.shown }}
    </p>
    <p
      v-else
      class="mt-1 text-sm text-warning-ink"
    >
      {{ $t('components.metaPreview.noDescription') }}
    </p>

    <p
      v-for="cut in cuts"
      :key="cut.key"
      class="mt-2 flex min-w-0 items-baseline gap-1.5 font-sans text-sm text-warning-ink"
    >
      <Icon
        name="lucide:scissors"
        class="shrink-0 translate-y-0.5"
      />
      <span class="shrink-0">{{ cut.label }}</span>
      <span class="min-w-0 truncate text-muted line-through decoration-warning">{{ cut.text }}</span>
    </p>
  </div>
</template>
