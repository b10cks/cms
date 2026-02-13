<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'

defineProps<{
  icon?: string
  title?: string | CleanTranslation
  description?: string | CleanTranslation
}>()
</script>

<template>
  <Card class="relative">
    <CardHeader class="flex flex-row items-center justify-between">
      <CardTitle class="text-sm">{{ title }}</CardTitle>
      <Icon
        :name="icon"
        :size="18"
        class="shrink-0 text-muted"
      />
    </CardHeader>
    <CardContent>
      <slot />
      <p
        v-if="description"
        class="text-sm text-muted"
      >
        {{ description }}
      </p>
    </CardContent>
  </Card>
</template>
