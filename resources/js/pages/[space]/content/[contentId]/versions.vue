<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import ContentHeader from '~/components/content/ContentHeader.vue'
import ContentVersionHistory from '~/components/content/VersionHistory.vue'
import { Button } from '~/components/ui/button'

// Extract route parameters
const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const contentId = computed<string>(() => route.params.contentId as string)

// Fetch content data for breadcrumbs and title
const { useContentQuery } = useContent(spaceId.value)
const { data: content, isLoading: isLoadingContent } = useContentQuery(contentId.value)

// Navigate back to content edit page
const navigateToContent = () => {
  router.push({
    name: 'space-content-contentId',
    params: {
      space: spaceId.value,
      contentId: contentId.value,
    },
  })
}

// Document title
useSeoMeta({
  title: computed(() =>
    content.value?.name ? `History: ${content.value.name}` : 'Version History'
  ),
})
</script>

<template>
  <div class="flex h-full w-full flex-col bg-background">
    <div
      v-if="isLoadingContent"
      class="flex h-20 items-center justify-center"
    >
      <Icon
        name="lucide:loader"
        class="mr-2 h-6 w-6 animate-spin"
      />
      <span>Loading content details...</span>
    </div>

    <div
      v-else-if="content"
      class="flex h-full flex-col"
    >
      <!-- Main content section -->
      <div class="flex-1 overflow-hidden">
        <ContentVersionHistory
          :space-id="spaceId"
          :content="content"
        />
      </div>
    </div>

    <div
      v-else
      class="flex h-full flex-col items-center justify-center p-8"
    >
      <Icon
        name="lucide:file-question"
        class="mb-4 h-16 w-16 text-muted"
      />
      <h2 class="mb-2 text-xl font-bold">Content not found</h2>
      <p class="mb-6 text-muted">
        The requested content could not be found or you don't have permission to view it.
      </p>
      <Button @click="router.push(`/${spaceId}/content`)"> Return to content list </Button>
    </div>
  </div>

  <Teleport to="#appHeader">
    <ContentHeader
      v-if="content"
      :content="content"
    />
  </Teleport>

  <Teleport to="#appHeaderActions">
    <div
      v-if="content"
      class="flex items-center gap-3"
    >
      <Button @click="navigateToContent">
        <Icon name="lucide:arrow-left" />
        Back to Editor
      </Button>
    </div>
  </Teleport>
</template>
