<script setup lang="ts">
import Icon from '~/components/Icon.vue'

// import RedirectDialog from '~/components/redirects/RedirectDialog.vue'
import RedirectsTable from '~/components/redirects/RedirectsTable.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.redirects.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.redirects.title')"
        :description="$t('labels.redirects.description')"
      >
        <template #actions>
          <Button
            variant="primary"
            @click="handleAddRedirect"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.redirects.add') }}
          </Button>
        </template>
      </ContentHeader>
      <RedirectsTable :space-id="spaceId" />
    </div>
  </div>
</template>
