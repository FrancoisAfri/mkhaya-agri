<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, when, statusColor } from './useWorkflow'

const route = ziggyRoute
const props = defineProps<{ events: { data: any[]; links: any[] }; filters: Record<string, any>; actions: string[] }>()
const f = reactive({ action: props.filters.action ?? null, outcome: props.filters.outcome ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '', search: props.filters.search ?? '' })
function apply() { router.get(route('workflow.audit'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }) }
const open = ref<any>(null)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Workflow audit trail</h4><div class="text-body-2 text-medium-emphasis">Every design change, publication, decision, routing choice, automated action, refusal and intervention. Append-only and hash-chained.</div></div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-shield-check" @click="router.post(route('workflow.audit.verify'), {}, { preserveScroll: true })">Verify chain</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-download" :href="route('workflow.audit.export', Object.fromEntries(Object.entries(f).filter(([, v]) => v)))">Export CSV</VBtn>
      </div>
    </div>
    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0">
        <VAutocomplete v-model="f.action" :items="actions" label="Action" clearable density="compact" hide-details style="min-inline-size: 220px" @update:model-value="apply" />
        <VSelect v-model="f.outcome" :items="['success', 'denied', 'failed']" label="Outcome" clearable density="compact" hide-details style="min-inline-size: 140px" @update:model-value="apply" />
        <VTextField v-model="f.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 170px" @change="apply" />
        <VTextField v-model="f.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 170px" @change="apply" />
        <VTextField v-model="f.search" placeholder="Person or reason" density="compact" hide-details style="min-inline-size: 200px" @keydown.enter="apply" />
      </VCardText>
      <VTable density="compact">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Request</th><th>Outcome</th><th>Reason</th><th>Hash</th></tr></thead>
        <tbody>
          <tr v-for="e in events.data" :key="e.id" style="cursor: pointer" @click="open = e">
            <td class="text-no-wrap text-body-2">{{ when(e.at) }}</td>
            <td class="text-body-2">{{ e.actor ?? '—' }}<div v-if="e.actor_type !== 'user'" class="text-caption text-disabled">{{ e.actor_type }}</div></td>
            <td class="text-body-2">{{ e.action }}</td>
            <td class="text-body-2">{{ e.instance ?? e.subject }}</td>
            <td><VChip size="x-small" :color="statusColor[e.outcome]" label>{{ e.outcome }}</VChip></td>
            <td class="text-body-2" style="max-inline-size: 280px">{{ e.reason }}</td>
            <td class="text-caption text-medium-emphasis" style="font-family: monospace">{{ e.hash }}</td>
          </tr>
          <tr v-if="!events.data.length"><td colspan="7" class="text-medium-emphasis">No events match.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="events.links.length > 3" class="d-flex gap-1 flex-wrap">
        <VBtn v-for="(l, i) in events.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
    <VDialog :model-value="!!open" max-width="640" @update:model-value="open = null">
      <VCard :title="open?.action">
        <VCardText><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify({ before: open?.before, after: open?.after, context: open?.context }, null, 2) }}</pre></VCardText>
        <VCardActions><VSpacer /><VBtn @click="open = null">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
