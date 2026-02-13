<script setup lang="ts">
import type { ContentResource } from '~/types/contents'

const { formatDateTime } = useFormat()

defineProps<{
  content: ContentResource
}>()
</script>

<template>
  <dl class="grid text-primary">
    <h2 class="mb-2 text-xl font-bold text-primary">{{ content.name }}</h2>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.type') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{ content.block?.name }}
    </dd>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.fullSlug') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{ content.full_slug }}
    </dd>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.firstPublishedAt') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{
        content.first_published_at ? formatDateTime(content.first_published_at) : 'Never published'
      }}
    </dd>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.publishedAt') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{ content.published_at ? formatDateTime(content.published_at) : 'Not published' }}
    </dd>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.createdAt') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{ formatDateTime(content.created_at) }}
    </dd>
    <dt class="text-sm font-medium text-muted">
      {{ $t('labels.contents.fields.updatedAt') }}
    </dt>
    <dd class="mt-1 mb-4">
      {{ formatDateTime(content.updated_at) }}
    </dd>
  </dl>
</template>
