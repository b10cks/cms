<script setup lang="ts">
import ContentHeader from '~/components/content/ContentHeader.vue'
import HeaderActions from '~/components/content/HeaderActions.vue'
import type { CollaborationPresenceUser } from '~/composables/useContentLiveCollaboration'
import type { ContentResource } from '~/types/contents'

/**
 * The app-shell header of the content editor pages: title/breadcrumb on the
 * left, save/publish actions on the right. Both live in the app header, which
 * is why they are teleported instead of rendered in place.
 */
defineProps<{
  content: ContentResource | null
  spaceId: string
  isDirty: boolean
  showPreviewToggle: boolean
  presentUsers: CollaborationPresenceUser[]
  remoteDraftUsers: CollaborationPresenceUser[]
}>()
</script>

<template>
  <Teleport
    defer
    to="#appHeader"
  >
    <ContentHeader
      v-if="content"
      :content="content"
      :show-preview-toggle="showPreviewToggle"
    />
  </Teleport>

  <Teleport
    defer
    to="#appHeaderActions"
  >
    <HeaderActions
      v-if="content"
      :content="content"
      :present-users="presentUsers"
      :remote-draft-users="remoteDraftUsers"
      :space-id="spaceId"
      :is-dirty="isDirty"
    />
  </Teleport>
</template>
