<script setup lang="ts">
import { DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog/index'

defineProps<{
  title?: string
  description?: string
}>()
</script>

<template>
  <DialogHeader>
    <DialogTitle v-if="title">{{ title }}</DialogTitle>
    <DialogDescription v-if="description">{{ description }}</DialogDescription>
  </DialogHeader>
</template>
