<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'

const props = defineProps<{
  invoices: InvoiceResource[]
  loading?: boolean
}>()

function statusVariant(status: string | null) {
  switch (status) {
    case 'paid':
      return 'success'
    case 'refunded':
    case 'void':
      return 'secondary'
    case 'pending':
      return 'warning'
    default:
      return 'surface'
  }
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}
</script>

<template>
  <div
    v-if="props.loading"
    class="flex items-center gap-2 py-6 text-muted"
  >
    <Icon
      name="lucide:loader"
      class="animate-spin"
    />
    {{ $t('labels.loading') }}
  </div>

  <div
    v-else-if="props.invoices.length"
    class="divide-y rounded-lg border"
  >
    <div
      v-for="invoice in props.invoices"
      :key="invoice.id"
      class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
    >
      <div class="flex items-center gap-3">
        <span class="text-muted">{{ formatDate(invoice.created_at) }}</span>
        <span class="font-medium text-primary">{{ invoice.total_formatted }}</span>
        <Badge :variant="statusVariant(invoice.status)">
          {{ invoice.status_formatted ?? invoice.status }}
        </Badge>
      </div>
      <a
        v-if="invoice.invoice_url"
        :href="invoice.invoice_url"
        target="_blank"
        rel="noopener noreferrer"
        class="flex items-center gap-1.5 font-medium text-primary no-underline hover:underline"
      >
        <Icon
          name="lucide:external-link"
          size="0.875rem"
        />
        {{ $t('actions.invoices.view') }}
      </a>
    </div>
  </div>

  <div
    v-else
    class="rounded-lg border border-dashed p-8 text-center text-muted"
  >
    {{ $t('labels.invoices.empty') }}
  </div>
</template>
