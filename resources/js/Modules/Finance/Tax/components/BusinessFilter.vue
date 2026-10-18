<script setup lang="ts">
import { router } from '@inertiajs/vue3'

// Filters the current tax list to one business. Hidden when the company keeps tax for only one —
// a choice of one is not a choice. Keeps every other filter already in the address bar.
const props = defineProps<{
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

function choose(id: number | null) {
  const url = new URL(window.location.href)
  if (id) url.searchParams.set('business', String(id))
  else url.searchParams.delete('business')
  url.searchParams.delete('page')
  router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true })
}
</script>

<template>
  <VSelect
    v-if="props.businesses.length > 1"
    :model-value="props.business"
    :items="props.businesses"
    item-title="name"
    item-value="id"
    label="Business"
    placeholder="All businesses"
    persistent-placeholder
    clearable
    density="compact"
    hide-details
    style="min-inline-size: 220px; max-inline-size: 280px"
    @update:model-value="choose"
  />
</template>
