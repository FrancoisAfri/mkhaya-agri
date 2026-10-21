<script setup lang="ts">
/**
 * One incident: what happened, who was told and when, who acknowledged it, what was done, and the
 * evidence it was resolved on. A critical or emergency incident needs a root cause and evidence;
 * files are kept in the database with the incident.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import ReadingChart from './ReadingChart.vue'
import { at, human, num, severityColor, statusColor } from '../Agriculture/prd'

const props = defineProps<{
  incident: Record<string, any>
  events: Array<{ event: string; at: string; by: string | null; detail: Record<string, any> | null }>
  notifications: Array<{ to: string; channel: string; level: number; status: string; sent_at: string; acknowledged_at: string | null; error: string | null }>
  can: { respond: boolean }
}>()

const i = props.incident
const act = (action: string, extra: Record<string, any> = {}) => router.post(`/manufacturing/command/incidents/${i.id}`, { action, ...extra }, { preserveScroll: true })
const note = ref('')
const resolving = ref(false)
const form = useForm<{ action: string; resolution: string; root_cause: string; evidence: Array<Record<string, any>> }>({ action: 'resolve', resolution: '', root_cause: '', evidence: [{ kind: 'note', description: '' }] })
const serious = ['critical', 'emergency'].includes(i.severity)
function attach(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const r = new FileReader()
  r.onload = () => form.evidence.push({ kind: 'file', name: file.name, mime: file.type, description: file.name, data: String(r.result).split(',')[1] })
  r.readAsDataURL(file)
}
const resolve = () => form.post(`/manufacturing/command/incidents/${i.id}`, { preserveScroll: true, onSuccess: () => { resolving.value = false } })
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VChip label :color="severityColor(i.severity)">{{ i.severity }}</VChip>
          <VChip label :color="statusColor(i.status)">{{ human(i.status) }}</VChip>
          <span class="text-caption text-medium-emphasis">{{ i.number }} · {{ human(i.kind) }}</span>
        </div>
        <h4 class="text-h4">{{ i.title }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ i.farm }}<template v-if="i.unit"> · {{ i.unit }}</template> · opened {{ at(i.opened_at) }}<template v-if="i.occurrences > 1"> · seen {{ i.occurrences }} times, last {{ at(i.last_seen_at) }}</template></p>
      </div>
      <div v-if="can.respond && !['resolved', 'closed'].includes(i.status)" class="d-flex gap-2">
        <VBtn v-if="!i.acknowledged_at" color="error" @click="act('acknowledge')">Acknowledge</VBtn>
        <VBtn v-if="i.status !== 'in_progress'" variant="tonal" @click="act('start')">Working on it</VBtn>
        <VBtn color="success" @click="resolving = true">Resolve</VBtn>
      </div>
      <VBtn v-else-if="can.respond && i.status === 'resolved'" variant="tonal" @click="act('close')">Close</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardText>
            <p v-if="i.detail" class="mb-2">{{ i.detail }}</p>
            <div v-if="i.value !== null" class="text-body-2">Observed: <strong>{{ num(i.value, 2) }}</strong> {{ i.metric ? `(${human(i.metric)})` : '' }}</div>
            <div class="text-body-2">Acknowledged: {{ i.acknowledged_at ? `${at(i.acknowledged_at)} by ${i.acknowledged_by}` : 'not yet' }}</div>
            <div class="text-body-2">Assigned to: {{ i.assigned_to ?? '—' }}</div>
            <div v-if="i.next_escalation_at" class="text-body-2 text-warning">Goes to step {{ i.escalation_level + 1 }} at {{ at(i.next_escalation_at) }} unless acknowledged.</div>
          </VCardText>
        </VCard>
        <VCard v-if="i.metric && (i.device_id || i.unit_id)" border class="mb-4"><VCardText>
          <ReadingChart :device="i.device_id" :unit="i.device_id ? null : i.unit_id" :metric="i.metric" :hours="6" :title="human(i.metric)" />
        </VCardText></VCard>
        <VCard v-if="i.resolution" border class="mb-4">
          <VCardTitle>Resolution</VCardTitle>
          <VCardText>
            <p class="mb-1">{{ i.resolution }}</p>
            <div class="text-body-2">Root cause: {{ i.root_cause ?? '—' }}</div>
            <div class="text-caption text-medium-emphasis mb-2">{{ i.resolved_by }} · {{ at(i.resolved_at) }}</div>
            <div v-for="(e, k) in i.evidence" :key="k" class="text-body-2">
              <VIcon :icon="e.has_file ? 'tabler-paperclip' : 'tabler-note'" size="16" />
              <a v-if="e.has_file" :href="`/manufacturing/command/incidents/${i.id}/evidence/${k}`" target="_blank">{{ e.name ?? e.description }}</a>
              <span v-else>{{ e.description }}</span>
              <span class="text-caption text-medium-emphasis"> — {{ e.by }}</span>
            </div>
          </VCardText>
        </VCard>
        <VCard v-if="can.respond && i.status !== 'closed'" border><VCardText class="d-flex gap-2">
          <VTextField v-model="note" label="Add a note" hide-details density="compact" />
          <VBtn :disabled="!note" @click="act('note', { note }); note = ''">Add</VBtn>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardTitle>Timeline</VCardTitle>
          <VCardText>
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem v-for="(e, k) in events" :key="k" size="x-small" :dot-color="e.event === 'escalated' ? 'error' : e.event === 'resolved' ? 'success' : 'primary'">
                <div class="font-weight-medium">{{ human(e.event) }}</div>
                <div class="text-caption text-medium-emphasis">{{ at(e.at) }}<template v-if="e.by"> · {{ e.by }}</template></div>
                <div v-if="e.detail?.note" class="text-caption">{{ e.detail.note }}</div>
                <div v-if="e.event === 'escalated'" class="text-caption">Step {{ e.detail?.from }} → {{ e.detail?.to }}</div>
                <div v-if="e.event === 'notified'" class="text-caption">Step {{ e.detail?.level }}: {{ e.detail?.recipients }} people</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
        <VCard border>
          <VCardTitle>Who was told</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>Person</th><th>Step</th><th>Channel</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="(n, k) in notifications" :key="k">
                <td>{{ n.to }}<div class="text-caption">{{ at(n.sent_at) }}</div></td><td>{{ n.level }}</td><td>{{ human(n.channel) }}</td>
                <td><VChip size="x-small" label :color="n.status === 'failed' ? 'error' : n.status === 'acknowledged' ? 'success' : 'info'">{{ n.status }}</VChip>
                  <div v-if="n.error" class="text-caption text-error">{{ n.error }}</div></td>
              </tr>
              <tr v-if="!notifications.length"><td colspan="4" class="text-medium-emphasis">Nobody — check the escalation settings.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="resolving" max-width="640">
      <VCard title="Resolve incident">
        <VCardText>
          <VAlert v-if="serious" type="info" variant="tonal" density="compact" class="mb-3">A {{ i.severity }} incident needs its root cause and evidence of what was done.</VAlert>
          <VTextarea v-model="form.resolution" label="What was done" rows="2" :error-messages="form.errors.resolution" />
          <VTextField v-model="form.root_cause" label="Root cause" />
          <div v-for="(e, k) in form.evidence" :key="k" class="d-flex gap-2 align-center">
            <VTextField v-model="e.description" :label="e.data ? 'File' : 'Evidence (a reading, a check, a note)'" hide-details density="compact" class="mb-2" :readonly="!!e.data" />
            <VBtn icon="tabler-x" variant="text" size="small" @click="form.evidence.splice(k, 1)" />
          </div>
          <div class="d-flex gap-2 mt-2">
            <VBtn size="small" variant="tonal" @click="form.evidence.push({ kind: 'note', description: '' })">Add evidence</VBtn>
            <VBtn size="small" variant="tonal" tag="label" prepend-icon="tabler-paperclip">Attach a file<input type="file" hidden @change="attach"></VBtn>
          </div>
          <div v-if="form.errors.evidence" class="text-error text-caption mt-2">{{ form.errors.evidence }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="resolving = false">Cancel</VBtn><VBtn color="success" :loading="form.processing" @click="resolve">Resolve</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
