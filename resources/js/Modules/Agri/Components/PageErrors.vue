<script setup lang="ts">
/**
 * Shows why an action was refused when it was not made from a form — an approval, a status change,
 * a ⋮ menu action. Without it the server's reason ("you drafted this plan, so somebody else must
 * approve it") would arrive and never be seen.
 */
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const errors = computed(() => Object.values((page.props as any).errors ?? {}) as string[])
const show = ref(false)
watch(errors, e => { show.value = e.length > 0 }, { immediate: true })
</script>

<template>
  <VAlert v-if="show && errors.length" type="error" variant="tonal" closable class="mb-4" icon="tabler-alert-circle" @click:close="show = false">
    <div v-for="(e, i) in errors" :key="i">{{ e }}</div>
  </VAlert>
</template>
