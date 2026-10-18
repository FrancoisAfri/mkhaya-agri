<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, statusColor } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{
  definitions: Array<{ uuid: string; key: string; name: string; category: string | null; module: string; status: string; trigger: string; version: number | null
    owner: string | null; pending_review: boolean; draft: boolean; running: number; confidential: boolean }>
  modules: Array<{ key: string; label: string }>
  categories: string[]
  can: { design: boolean; ai: boolean }
}>()
const search = ref('')
const status = ref<string | null>(null)
const category = ref<string | null>(null)
const rows = computed(() => props.definitions.filter(d => (!status.value || d.status === status.value) && (!category.value || d.category === category.value) && (!search.value || `${d.name} ${d.category ?? ''}`.toLowerCase().includes(search.value.toLowerCase()))))
const moduleLabel = (k: string) => props.modules.find(m => m.key === k)?.label ?? k

const createDialog = ref(false)
const create = useForm({ name: '', description: '', category: '', module: 'generic' })
const aiDialog = ref(false)
const ai = useForm({ name: '', category: '', description: 'When a purchase request exceeds R50 000, obtain manager and finance approval before procurement can proceed.' })
const importForm = useForm({ file: null as File | null })
function doImport(f: File | File[] | null) { importForm.file = Array.isArray(f) ? f[0] : f; if (importForm.file) importForm.post(route('workflow.definitions.import'), { forceFormData: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Workflows</h4><div class="text-body-2 text-medium-emphasis">Design, publish and version the company's processes. Published versions never change; edits become a new version.</div></div>
      <div v-if="can.design" class="d-flex flex-wrap gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-template" @click="router.get(route('workflow.templates.index'))">From a template</VBtn>
        <VBtn v-if="can.ai" variant="tonal" prepend-icon="tabler-sparkles" @click="aiDialog = true">Describe it</VBtn>
        <VFileInput accept=".json,application/json" density="compact" hide-details label="Import" prepend-icon="tabler-upload" style="max-inline-size: 170px" @update:model-value="doImport" />
        <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New workflow</VBtn>
      </div>
    </div>
    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0">
        <VTextField v-model="search" placeholder="Search" prepend-inner-icon="tabler-search" density="compact" hide-details style="max-inline-size: 260px" />
        <VSelect v-model="category" :items="categories" label="Category" clearable density="compact" hide-details style="max-inline-size: 200px" />
        <VSelect v-model="status" :items="['draft', 'in_review', 'published', 'suspended', 'retired'].map(s => ({ title: label(s), value: s }))" label="Status" clearable density="compact" hide-details style="max-inline-size: 200px" />
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Workflow</th><th>Runs on</th><th>Status</th><th>Version</th><th>Running</th><th>Owner</th></tr></thead>
        <tbody>
          <tr v-for="d in rows" :key="d.uuid" style="cursor: pointer" @click="router.get(route('workflow.definitions.show', d.uuid))">
            <td><div class="font-weight-medium">{{ d.name }} <VIcon v-if="d.confidential" icon="tabler-lock" size="14" color="warning" /></div><div class="text-caption text-medium-emphasis">{{ d.category ?? 'Uncategorised' }} · {{ label(d.trigger) }}</div></td>
            <td class="text-body-2">{{ moduleLabel(d.module) }}</td>
            <td><VChip size="small" :color="statusColor[d.status]" label>{{ label(d.status) }}</VChip>
              <VChip v-if="d.pending_review" size="x-small" color="warning" class="ms-1" label>review pending</VChip>
              <VChip v-else-if="d.draft && d.status === 'published'" size="x-small" class="ms-1" label>draft changes</VChip></td>
            <td>{{ d.version ?? '—' }}</td>
            <td>{{ d.running }}</td>
            <td class="text-body-2">{{ d.owner ?? '—' }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="text-medium-emphasis">No workflows yet. Start from a template, describe one, or build it on the canvas.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="createDialog" max-width="520">
      <VCard title="New workflow">
        <VCardText class="d-flex flex-column gap-2">
          <VTextField v-model="create.name" label="Name" :error-messages="create.errors.name" />
          <VSelect v-model="create.category" :items="categories" label="Category" clearable :error-messages="create.errors.category" hint="Your administrator manages this list" persistent-hint />
          <VSelect v-model="create.module" :items="modules" item-title="label" item-value="key" label="Business record it runs on" hint="A module's records hand the outcome back to that module." persistent-hint />
          <VTextarea v-model="create.description" label="Purpose" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="createDialog = false">Cancel</VBtn><VBtn color="primary" :loading="create.processing" :disabled="!create.name" @click="create.post(route('workflow.definitions.store'))">Create and design</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="aiDialog" max-width="600">
      <VCard title="Describe the process">
        <VCardText class="d-flex flex-column gap-2">
          <p class="text-body-2">Describe who does what, in plain language. A draft is built for you to check; nothing goes live until someone authorised publishes it. People are never chosen for you.</p>
          <VTextField v-model="ai.name" label="Name" :error-messages="ai.errors.name" />
          <VSelect v-model="ai.category" :items="categories" label="Category" clearable :error-messages="ai.errors.category" />
          <VTextarea v-model="ai.description" label="The process" rows="4" auto-grow :error-messages="ai.errors.description" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="aiDialog = false">Cancel</VBtn><VBtn color="primary" prepend-icon="tabler-sparkles" :loading="ai.processing" :disabled="!ai.name || ai.description.length < 15" @click="ai.post(route('workflow.definitions.ai_draft'))">Draft it</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
