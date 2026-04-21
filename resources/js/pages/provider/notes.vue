<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import ProviderNotesTable from '~/components/provider/ProviderNotesTable.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const providerNotesTableRef = ref<InstanceType<typeof ProviderNotesTable> | null>(null)
const { t } = useI18n()

useSeoMeta({
  title: t('labels.provider.notes.title'),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="t('labels.provider.notes.title')"
        :description="t('labels.provider.notes.description')"
      >
        <template #actions>
          <Button
            variant="primary"
            @click="providerNotesTableRef?.openCreateDialog()"
          >
            <Icon name="lucide:plus" />
            {{ t('labels.provider.notes.create') }}
          </Button>
        </template>
      </ContentHeader>

      <ProviderNotesTable ref="providerNotesTableRef" />
    </div>
  </div>
</template>
