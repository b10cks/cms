<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Skeleton } from '~/components/ui/skeleton'

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
    class="divide-y rounded-lg border"
  >
    <div
      v-for="i in 3"
      :key="i"
      class="flex items-center justify-between gap-3 px-4 py-3"
    >
      <div class="flex items-center gap-3">
        <Skeleton class="h-4 w-20" />
        <Skeleton class="h-4 w-16" />
        <Skeleton class="h-5 w-14 rounded-full" />
      </div>
      <Skeleton class="h-4 w-16" />
    </div>
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
