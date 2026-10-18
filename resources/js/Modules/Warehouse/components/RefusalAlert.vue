<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// A business rule's refusal ("A-01 still holds stock") arrives as errors.refused on whichever
// page made the request. Shown at the top of the page rather than beside a field, because it is
// about the action, not about one input.
const page = usePage()
const message = computed(() => (page.props as any).errors?.refused as string | undefined)
</script>

<template>
  <VAlert v-if="message" type="error" variant="tonal" class="mb-4" closable>{{ message }}</VAlert>
</template>
