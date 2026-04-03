<script setup lang="ts">
import AuditLogsTable from '~/components/audit-logs/AuditLogsTable.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.auditLog.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.auditLog.title')"
        :description="$t('labels.auditLog.description')"
      />
      <AuditLogsTable :space-id="spaceId" />
    </div>
  </div>
</template>
