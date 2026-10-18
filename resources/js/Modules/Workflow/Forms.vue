<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { when } from './useWorkflow'

const route = ziggyRoute
defineProps<{ forms: Array<{ uuid: string; name: string; description: string | null; version: number | null; fields: number; versions: number; updated: string | null }> }>()
const dialog = ref(false)
const form = useForm({ name: '', description: '' })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Forms</h4><div class="text-body-2 text-medium-emphasis">Reusable request and task forms. Saving makes a new version; published workflows keep the version they were published with.</div></div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-books" @click="router.get(route('workflow.forms.library'))">Form library</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">New form</VBtn>
      </div>
    </div>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Form</th><th>Fields</th><th>Version</th><th>Updated</th></tr></thead>
        <tbody>
          <tr v-for="f in forms" :key="f.uuid" style="cursor: pointer" @click="router.get(route('workflow.forms.show', f.uuid))">
            <td><div class="font-weight-medium">{{ f.name }}</div><div class="text-caption text-medium-emphasis">{{ f.description }}</div></td>
            <td>{{ f.fields }}</td><td>{{ f.version ?? '—' }} <span class="text-caption text-medium-emphasis">of {{ f.versions }}</span></td><td class="text-body-2">{{ when(f.updated) }}</td>
          </tr>
          <tr v-if="!forms.length"><td colspan="4" class="text-medium-emphasis">No forms yet. Start from the <a href="#" @click.prevent="router.get(route('workflow.forms.library'))">form library</a> or create one.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VDialog v-model="dialog" max-width="480">
      <VCard title="New form">
        <VCardText><VTextField v-model="form.name" label="Name" :error-messages="form.errors.name" /><VTextarea v-model="form.description" label="Description" rows="2" auto-grow /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :disabled="!form.name" :loading="form.processing" @click="form.post(route('workflow.forms.store'))">Create</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
