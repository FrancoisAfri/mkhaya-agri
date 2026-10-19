<script setup lang="ts">
/** "Which farm" picker that reloads the page with ?farm=…, keeping the other filters. */
import { router } from '@inertiajs/vue3'

const props = withDefaults(defineProps<{ farms: Array<{ id: string; name: string }>; modelValue: string | null | undefined; allowAll?: boolean; extra?: Record<string, any> }>(), { allowAll: true })

function pick(v: string | null) {
  const q = Object.fromEntries(new URLSearchParams(window.location.search))
  router.get(window.location.pathname, { ...q, ...(props.extra ?? {}), farm: v || undefined }, { preserveState: false, replace: true })
}
</script>

<template>
  <VSelect :model-value="modelValue ?? null" :items="[...(allowAll ? [{ title: 'All my farms', value: null }] : []), ...farms.map(f => ({ title: f.name, value: f.id }))]"
    label="Farm" density="compact" hide-details style="min-width: 220px; max-width: 280px" prepend-inner-icon="tabler-map-2" @update:model-value="pick" />
</template>
