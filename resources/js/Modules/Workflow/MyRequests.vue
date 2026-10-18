<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{ requests: { data: any[]; links: any[] }; filters: { status?: string | null }; drafts: Array<{ workflow: string; uuid: string; saved: string }> }>()
const f = reactive({ status: props.filters.status ?? null })
function apply() { router.get(route('workflow.requests'), f.status ? { status: f.status } : {}, { preserveState: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">My requests</h4><div class="text-body-2 text-medium-emphasis">What you have asked for, where each one is, and who it is waiting on.</div></div>
      <div class="d-flex gap-2 align-center">
        <VSelect v-model="f.status" :items="['running', 'completed', 'rejected', 'cancelled'].map(s => ({ title: label(s), value: s }))" label="Status" clearable density="compact" hide-details style="min-inline-size: 180px" @update:model-value="apply" />
        <VBtn color="primary" prepend-icon="tabler-circle-plus" @click="router.get(route('workflow.start'))">Start a request</VBtn>
      </div>
    </div>
    <VAlert v-if="drafts.length" type="info" variant="tonal" density="compact" class="mb-3">
      Unfinished drafts: <a v-for="d in drafts" :key="d.uuid" :href="route('workflow.start.form', d.uuid)" class="me-3">{{ d.workflow }} ({{ when(d.saved) }})</a>
    </VAlert>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Reference</th><th>Request</th><th>Status</th><th>Waiting on</th><th>Started</th></tr></thead>
        <tbody>
          <tr v-for="r in requests.data" :key="r.uuid" style="cursor: pointer" @click="router.get(route('workflow.instances.show', r.uuid))">
            <td class="text-no-wrap">{{ r.reference }}</td>
            <td><div>{{ r.title }}</div><div class="text-caption text-medium-emphasis">{{ r.workflow }}</div></td>
            <td><VChip size="small" :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip></td>
            <td class="text-body-2">{{ r.status === 'running' ? (r.waiting_on || 'the system') : '—' }}</td>
            <td class="text-no-wrap text-body-2">{{ when(r.started_at) }}</td>
          </tr>
          <tr v-if="!requests.data.length"><td colspan="5" class="text-medium-emphasis">You have not made any requests yet.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="requests.links.length > 3" class="d-flex gap-1 flex-wrap">
        <VBtn v-for="(l, i) in requests.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
