<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import type { ProviderUserStatus } from '~/types/provider-console'

defineProps<{ status: ProviderUserStatus }>()

const variants = { active: 'success', suspended: 'destructive', root: 'warning' } as const
</script>

<template>
  <Badge
    :variant="variants[status]"
    size="sm"
  >
    {{ $t(`providerConsole.users.status.${status}`) }}
  </Badge>
</template>
