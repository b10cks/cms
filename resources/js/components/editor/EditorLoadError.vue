<script setup lang="ts">
import { Alert } from '~/components/ui/alert'
</script>

<template>
  <Alert
    variant="modern"
    color="destructive"
    icon="lucide:triangle-alert"
  >
    <p>{{ $t('components.fieldEditor.loadFailed') }}</p>
  </Alert>
</template>
