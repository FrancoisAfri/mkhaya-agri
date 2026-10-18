<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { when } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{ workflows: Array<{ uuid: string; name: string; description: string | null; category: string; confidential: boolean; draft_saved: string | null }> }>()
const search = ref('')
const groups = computed(() => {
  const g: Record<string, typeof props.workflows> = {}
  for (const w of props.workflows.filter(w => !search.value || `${w.name} ${w.description ?? ''}`.toLowerCase().includes(search.value.toLowerCase()))) (g[w.category] ??= []).push(w)
  return g
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Start a request</h4>
        <div class="text-body-2 text-medium-emphasis">Choose what you need. Requests that belong to a module (leave, claims, requisitions) start from that module's own screen.</div>
      </div>
      <VTextField v-model="search" prepend-inner-icon="tabler-search" placeholder="Search" density="compact" hide-details style="max-inline-size: 280px" />
    </div>
    <div v-for="(items, cat) in groups" :key="cat" class="mb-5">
      <div class="text-overline text-medium-emphasis mb-2">{{ cat }}</div>
      <VRow>
        <VCol v-for="w in items" :key="w.uuid" cols="12" sm="6" md="4" lg="3">
          <VCard border class="h-100 d-flex flex-column" @click="router.get(route('workflow.start.form', w.uuid))">
            <VCardItem>
              <VCardTitle class="text-body-1 text-wrap">{{ w.name }}</VCardTitle>
              <template #append><VIcon v-if="w.confidential" icon="tabler-lock" size="18" color="warning" /></template>
            </VCardItem>
            <VCardText class="text-body-2 text-medium-emphasis flex-grow-1">{{ w.description || 'No description.' }}</VCardText>
            <VCardText v-if="w.draft_saved" class="pt-0"><VChip size="x-small" color="info" label>Draft saved {{ when(w.draft_saved) }}</VChip></VCardText>
          </VCard>
        </VCol>
      </VRow>
    </div>
    <VCard v-if="!workflows.length" border><VCardText class="text-medium-emphasis">No request forms are published yet. A workflow designer publishes them under Design → Workflows.</VCardText></VCard>
  </div>
</template>
