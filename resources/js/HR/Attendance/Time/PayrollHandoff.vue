<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, money, statusColor } from '@/Modules/Agri/Components/agri'

const props = defineProps<{
  handoffs: any[]
  selected: null | { id: string; reference: string; status: string; totals: any; period_start: string; period_end: string; lines: any[] }
  defaults: { period_start: string; period_end: string }
}>()
const start = ref(props.defaults.period_start)
const end = ref(props.defaults.period_end)
const keep = { preserveScroll: true }
const prepare = () => router.post(route('attendance.payroll-handoff.prepare'), { period_start: start.value, period_end: end.value }, keep)
const send = () => confirm('Send to payroll? Hours go to HR attendance and piecework to the open payroll run. Sending again replaces, never adds.') && router.post(route('attendance.payroll-handoff.send', props.selected!.id), {}, keep)
const reconcile = () => router.post(route('attendance.payroll-handoff.reconcile', props.selected!.id), {}, keep)
const pick = (id: string) => router.get(route('attendance.payroll-handoff.index'), { handoff: id }, { preserveState: false })
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-4">
      <h4 class="text-h4 mb-1">Payroll handoff</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Approved time and piecework, handed to payroll once. Payroll stays the authority on pay; this shows what was sent and what arrived.</p>
    </div>
    <WorkforceTabs current="payroll" />

    <VCard class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VTextField v-model="start" type="date" label="From" density="compact" hide-details style="max-width: 170px" />
        <VTextField v-model="end" type="date" label="To" density="compact" hide-details style="max-width: 170px" />
        <VBtn color="primary" prepend-icon="tabler-package" @click="prepare">Prepare handoff</VBtn>
        <span class="text-caption text-medium-emphasis">Collects approved timesheets and piecework not yet sent. Each record goes once.</span>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" md="3">
        <VCard title="Handoffs">
          <VList density="compact">
            <VListItem v-for="h in handoffs" :key="h.id" :active="selected?.id === h.id" :title="h.reference" :subtitle="h.period" @click="pick(h.id)">
              <template #append><VChip size="x-small" :color="statusColor(h.status === 'sent' ? 'submitted' : h.status === 'reconciled' ? 'approved' : 'draft')">{{ h.status }}</VChip></template>
            </VListItem>
            <VListItem v-if="!handoffs.length" title="None yet" />
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="9">
        <VCard v-if="selected">
          <VCardItem>
            <VCardTitle>{{ selected.reference }} · {{ selected.period_start }} → {{ selected.period_end }}</VCardTitle>
            <template #append>
              <VBtn variant="text" prepend-icon="tabler-download" :href="route('attendance.payroll-handoff.export', selected.id)">CSV</VBtn>
              <VBtn v-if="selected.status !== 'reconciled'" color="primary" class="ms-2" prepend-icon="tabler-send" @click="send">{{ selected.status === 'sent' ? 'Send again' : 'Send to payroll' }}</VBtn>
              <VBtn v-if="selected.status === 'sent'" color="success" variant="tonal" class="ms-2" @click="reconcile">Reconcile</VBtn>
            </template>
          </VCardItem>
          <VCardText v-if="selected.totals" class="d-flex flex-wrap gap-6">
            <div><div class="text-caption">Workers</div><div class="text-h6">{{ selected.totals.workers }}</div></div>
            <div><div class="text-caption">Regular / overtime</div><div class="text-h6">{{ selected.totals.regular_hours }} / {{ selected.totals.overtime_hours }} h</div></div>
            <div><div class="text-caption">Piecework</div><div class="text-h6">{{ money(selected.totals.piecework) }}</div></div>
            <div><div class="text-caption">Delivered</div><div class="text-h6">{{ selected.totals.delivered_hours }} h · {{ money(selected.totals.delivered_piecework) }}</div></div>
            <div v-if="selected.totals.exported_piecework"><div class="text-caption">To export</div><div class="text-h6">{{ money(selected.totals.exported_piecework) }}</div></div>
            <div v-if="selected.totals.mismatches"><div class="text-caption">Mismatches</div><div class="text-h6 text-error">{{ selected.totals.mismatches }}</div></div>
          </VCardText>
          <VTable density="compact">
            <thead><tr><th>Worker</th><th class="text-end">Regular</th><th class="text-end">OT</th><th class="text-end">Sun</th><th class="text-end">PH</th><th class="text-end">Piecework</th><th>Delivered</th><th>Status</th></tr></thead>
            <tbody><tr v-for="(l, i) in selected.lines" :key="i">
              <td>{{ l.worker }}<div class="text-caption">{{ l.timesheets }} timesheet(s)</div></td>
              <td class="text-end">{{ l.regular }}</td><td class="text-end">{{ l.overtime }}</td><td class="text-end">{{ l.sunday }}</td><td class="text-end">{{ l.holiday }}</td>
              <td class="text-end">{{ money(l.piecework) }}</td>
              <td class="text-caption">{{ l.to ? human(l.to) : '—' }}<template v-if="l.delivered_hours !== null"> · {{ l.delivered_hours }} h</template><template v-if="l.delivered_piecework"> · {{ money(l.delivered_piecework) }}</template></td>
              <td><VChip size="x-small" :color="l.status === 'delivered' ? 'success' : l.status === 'mismatch' ? 'error' : l.status === 'exported' ? 'info' : undefined">{{ human(l.status) }}</VChip><div v-if="l.note" class="text-caption">{{ l.note }}</div></td>
            </tr></tbody>
          </VTable>
        </VCard>
        <VCard v-else><VCardText class="text-medium-emphasis">Prepare a handoff for a period to see it here.</VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>
