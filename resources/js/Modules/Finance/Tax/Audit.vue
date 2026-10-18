<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, statusColor } from './useTax'

const route = ziggyRoute

type Event = {
  id: number; at: string | null; actor: string | null; actor_type: string; action: string; subject: string; tax_type: string | null
  outcome: string; reason: string | null; before: unknown; after: unknown; context: unknown; correlation_id: string | null
}

const props = defineProps<{
  events: { data: Event[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { action?: string; outcome?: string; from?: string; to?: string; search?: string }
  actions: string[]
}>()

const f = reactive({ action: props.filters.action ?? null, outcome: props.filters.outcome ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '', search: props.filters.search ?? '' })
function params() {
  return Object.fromEntries(Object.entries(f).filter(([, v]) => v))
}
function apply() {
  router.get(route('tax.audit.index'), params(), { preserveState: true, preserveScroll: true })
}
const open = ref<number | null>(null)
function exportCsv() {
  window.location.href = route('tax.audit.export', params())
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Tax audit trail</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Every tax decision, change and refusal. Entries cannot be edited or deleted; each is chained to the one before it.</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-shield-check" @click="router.post(route('tax.audit.verify'), {}, { preserveScroll: true })">Verify chain</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-download" @click="exportCsv">Export CSV</VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0">
        <VSelect v-model="f.action" :items="actions" label="Action" clearable density="compact" style="min-inline-size: 220px" @update:model-value="apply" />
        <VSelect v-model="f.outcome" :items="['success', 'denied', 'failed']" label="Outcome" clearable density="compact" style="max-inline-size: 160px" @update:model-value="apply" />
        <VTextField v-model="f.from" type="date" label="From" density="compact" style="max-inline-size: 170px" @change="apply" />
        <VTextField v-model="f.to" type="date" label="To" density="compact" style="max-inline-size: 170px" @change="apply" />
        <VTextField v-model="f.search" prepend-inner-icon="tabler-search" placeholder="Person or reason" density="compact" hide-details style="max-inline-size: 220px" @keyup.enter="apply" />
      </VCardText>
      <VTable density="compact">
        <thead><tr><th /><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Outcome</th><th>Reason</th></tr></thead>
        <tbody>
          <template v-for="e in events.data" :key="e.id">
            <tr>
              <td><VBtn size="x-small" variant="text" :icon="open === e.id ? 'tabler-chevron-down' : 'tabler-chevron-right'" @click="open = open === e.id ? null : e.id" /></td>
              <td class="text-caption">{{ e.at }}</td>
              <td>{{ e.actor ?? '—' }}<div v-if="e.actor_type === 'service'" class="text-caption text-disabled">service</div></td>
              <td>{{ e.action }}<div v-if="e.tax_type" class="text-caption text-disabled">{{ e.tax_type }}</div></td>
              <td class="text-caption">{{ e.subject }}</td>
              <td><VChip size="x-small" :color="statusColor[e.outcome]" label>{{ label(e.outcome) }}</VChip></td>
              <td class="text-caption" style="max-inline-size: 320px">{{ e.reason ?? '' }}</td>
            </tr>
            <tr v-if="open === e.id">
              <td colspan="7" class="bg-surface-light">
                <VRow class="py-2">
                  <VCol cols="12" md="4"><div class="text-overline">Before</div><pre class="text-caption">{{ JSON.stringify(e.before, null, 2) }}</pre></VCol>
                  <VCol cols="12" md="4"><div class="text-overline">After</div><pre class="text-caption">{{ JSON.stringify(e.after, null, 2) }}</pre></VCol>
                  <VCol cols="12" md="4"><div class="text-overline">Context</div><pre class="text-caption">{{ JSON.stringify(e.context, null, 2) }}</pre>
                    <div class="text-caption text-disabled">correlation {{ e.correlation_id }}</div></VCol>
                </VRow>
              </td>
            </tr>
          </template>
          <tr v-if="!events.data.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No events match.</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="events.links.length > 3" class="d-flex justify-center gap-1">
        <VBtn v-for="(l, i) in events.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
