<script setup lang="ts">
/**
 * "Which farm" picker that reloads the page with ?farm=…, keeping the other filters. On Time &
 * Attendance's screens it also offers the organisation's own staff (no site): ?farm=organisation.
 */
import { router } from '@inertiajs/vue3'

const props = withDefaults(defineProps<{ farms: Array<{ id: string; name: string }>; modelValue: string | null | undefined; allowAll?: boolean; extra?: Record<string, any>; organisation?: boolean }>(), { allowAll: true, organisation: false })

function pick(v: string | null) {
  const q = Object.fromEntries(new URLSearchParams(window.location.search))
  router.get(window.location.pathname, { ...q, ...(props.extra ?? {}), farm: v || undefined }, { preserveState: false, replace: true })
}
</script>

<template>
  <VSelect :model-value="modelValue ?? null" :items="[...(allowAll ? [{ title: organisation ? 'Everyone' : 'All my farms', value: null }] : []),
      ...(organisation ? [{ title: 'Organisation staff', value: 'organisation' }] : []), ...farms.map(f => ({ title: f.name, value: f.id }))]"
    :label="organisation ? 'Site' : 'Farm'" density="compact" hide-details style="min-width: 220px; max-width: 280px" prepend-inner-icon="tabler-map-2" @update:model-value="pick" />
</template>
