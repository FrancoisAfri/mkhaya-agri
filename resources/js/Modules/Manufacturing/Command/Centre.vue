<script setup lang="ts">
/**
 * The Command & Control Centre: open incidents by severity, every unit's live values (each with its
 * unit, when it was measured, and a stale label when the source has gone quiet), commands in flight,
 * recommendations waiting for a person, and the state of the outside sources.
 *
 * Live: a server-sent event stream pushes a fresh snapshot when anything changes; if the stream
 * cannot be held open the page polls instead, and says which it is doing.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import ReadingChart from './ReadingChart.vue'
import { ago, at, commandColor, human, num, severityColor } from '../Agriculture/prd'

type Value = { device: string; metric: string | null; value: number | null; unit: string | null; measured_at: string | null; stale: boolean; status: string; controllable: boolean }
type Snapshot = {
  generated_at: string; demo: boolean; counts: Record<string, number>; unacknowledged: number
  incidents: Array<{ id: string; number: string; severity: string; status: string; kind: string; title: string; farm: string | null; unit: string | null
    value: number | null; occurrences: number; opened_at: string; last_seen_at: string | null; acknowledged: boolean; escalation_level: number }>
  units: Array<{ id: string; code: string; name: string; kind: string; status: string; farm: string | null; timezone: string; demo: boolean
    cycle: { id: string; code: string; stage: string | null; type: string | null; count: number | null; count_unit: string | null; age_days: number | null } | null
    values: Value[] }>
  commands: Array<{ id: string; command: string; device: string | null; status: string; parameters: any; requested_at: string | null }>
  recommendations: Array<{ id: string; agent: string; title: string; priority: string; action_kind: string; ai_used: boolean }>
  sources: Array<{ code: string; name: string; kind: string; trust: string; status: string; stale: boolean; last_success_at: string | null; next_attempt_at: string | null; last_error: string | null }>
  brief: { brief: string | null; ai: string; at: string; agents: Record<string, string> } | null
}

const props = defineProps<{ snapshot: Snapshot; stream: string; can: { respond: boolean; command: boolean; approve: boolean; configure: boolean } }>()

const snap = ref<Snapshot>(props.snapshot)
const mode = ref<'live' | 'polling' | 'connecting'>('connecting')
let source: EventSource | null = null
let poll: number | null = null

function connect() {
  if (typeof EventSource === 'undefined') return startPolling()
  source = new EventSource(props.stream)
  source.addEventListener('snapshot', (e: MessageEvent) => { snap.value = JSON.parse(e.data); mode.value = 'live' })
  // When the server ends a response the browser reconnects by itself after the retry it was given;
  // only a stream that cannot be opened at all falls back to polling.
  source.onerror = () => { if (source?.readyState === EventSource.CLOSED) { source = null; startPolling() } }
}
function startPolling() {
  mode.value = 'polling'
  if (poll) return
  poll = window.setInterval(() => router.reload({ only: ['snapshot'], onSuccess: p => { snap.value = (p.props as any).snapshot } }), 15000)
}
onMounted(connect)
onBeforeUnmount(() => { source?.close(); if (poll) window.clearInterval(poll) })

const severities = ['emergency', 'critical', 'warning', 'advisory', 'info']
const selected = ref<{ unit: string; metric: string; title: string } | null>(null)
const units = computed(() => snap.value.units.filter(u => u.values.length || u.cycle))
const ack = (id: string) => router.post(`/manufacturing/command/incidents/${id}`, { action: 'acknowledge' }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Command Centre</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Live values, alarms and incidents across every farm you can reach.</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip v-if="snap.demo" color="info" label prepend-icon="tabler-flask">Demonstration data</VChip>
        <VChip :color="mode === 'live' ? 'success' : 'warning'" label :prepend-icon="mode === 'live' ? 'tabler-broadcast' : 'tabler-refresh'">
          {{ mode === 'live' ? 'Live' : mode === 'polling' ? 'Refreshing every 15 s' : 'Connecting…' }} · {{ at(snap.generated_at) }}
        </VChip>
        <VBtn variant="tonal" prepend-icon="tabler-list" :href="'/manufacturing/command/incidents'">All incidents</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol v-for="s in severities" :key="s" cols="6" sm="4" md>
        <VCard border :color="snap.counts[s] && ['critical', 'emergency'].includes(s) ? 'error' : undefined" :variant="snap.counts[s] && ['critical', 'emergency'].includes(s) ? 'tonal' : 'elevated'">
          <VCardText>
            <div class="text-caption text-medium-emphasis text-uppercase">{{ s }}</div>
            <div class="text-h4">{{ snap.counts[s] ?? 0 }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md>
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis text-uppercase">Not acknowledged</div>
          <div class="text-h4" :class="{ 'text-error': snap.unacknowledged }">{{ snap.unacknowledged }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-alert-octagon" color="error" /> Open incidents</VCardTitle>
          <VTable density="comfortable">
            <tbody>
              <tr v-for="i in snap.incidents" :key="i.id">
                <td style="width: 110px"><VChip size="small" label :color="severityColor(i.severity)">{{ i.severity }}</VChip></td>
                <td>
                  <Link :href="`/manufacturing/command/incidents/${i.id}`" class="font-weight-medium">{{ i.title }}</Link>
                  <div class="text-caption text-medium-emphasis">
                    {{ i.number }} · {{ i.farm ?? '—' }}<template v-if="i.unit"> · {{ i.unit }}</template> · opened {{ ago(i.opened_at) }}
                    <template v-if="i.occurrences > 1"> · seen {{ i.occurrences }}×</template>
                    <template v-if="i.escalation_level > 1"> · <span class="text-error">escalated to step {{ i.escalation_level }}</span></template>
                  </div>
                </td>
                <td class="text-end" style="width: 150px">
                  <VChip v-if="i.acknowledged" size="x-small" label>{{ human(i.status) }}</VChip>
                  <VBtn v-else-if="can.respond" size="small" color="error" variant="tonal" @click="ack(i.id)">Acknowledge</VBtn>
                  <VChip v-else size="x-small" color="warning" label>Not acknowledged</VChip>
                </td>
              </tr>
              <tr v-if="!snap.incidents.length"><td colspan="3" class="text-center text-medium-emphasis py-6"><VIcon icon="tabler-circle-check" color="success" /> Nothing open.</td></tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-home-signal" /> Houses, fields and ponds</VCardTitle>
          <VCardText>
            <VRow>
              <VCol v-for="u in units" :key="u.id" cols="12" md="6">
                <VCard variant="outlined" class="h-100">
                  <VCardText>
                    <div class="d-flex justify-space-between align-start">
                      <div>
                        <div class="font-weight-medium">{{ u.name }} <span class="text-caption text-medium-emphasis">{{ u.farm }}</span></div>
                        <div v-if="u.cycle" class="text-caption">
                          <Link :href="`/manufacturing/agriculture/cycles/${u.cycle.id}`">{{ u.cycle.code }}</Link> · {{ u.cycle.type }} · {{ human(u.cycle.stage) }}
                          <template v-if="u.cycle.count !== null"> · {{ num(u.cycle.count, 0) }} {{ u.cycle.count_unit }}</template>
                          <template v-if="u.cycle.age_days !== null"> · day {{ u.cycle.age_days }}</template>
                        </div>
                        <div v-else class="text-caption text-medium-emphasis">{{ human(u.status) }} — no cycle running</div>
                      </div>
                      <VChip v-if="u.demo" size="x-small" color="info" label>demo</VChip>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                      <VChip v-for="v in u.values.filter(x => x.metric)" :key="v.device" size="small" :color="v.stale ? 'warning' : undefined" label
                        :prepend-icon="v.stale ? 'tabler-clock-pause' : undefined" class="cursor-pointer"
                        @click="selected = { unit: u.id, metric: v.metric!, title: `${u.name} — ${human(v.metric)}` }">
                        {{ human(v.metric) }}: <strong class="ms-1">{{ num(v.value, 1) }}</strong>&nbsp;{{ v.unit }}
                        <span class="text-caption ms-1">({{ v.stale ? 'stale, ' : '' }}{{ ago(v.measured_at) }})</span>
                      </VChip>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol v-if="!units.length" cols="12" class="text-center text-medium-emphasis">No units with sensors or cycles yet.</VCol>
            </VRow>
            <div v-if="selected" class="mt-4">
              <ReadingChart :unit="selected.unit" :metric="selected.metric" :title="selected.title" />
            </div>
            <div v-else class="text-caption text-medium-emphasis mt-2">Click a value to see its graph.</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard v-if="snap.brief" border class="mb-4">
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-robot" /> Brief</VCardTitle>
          <VCardText>
            <p v-if="snap.brief.brief" class="mb-2" style="white-space: pre-line">{{ snap.brief.brief }}</p>
            <VAlert v-else type="info" variant="tonal" density="compact" class="mb-2">{{ snap.brief.ai === 'unavailable' ? 'The written brief is unavailable (AI service down).' : snap.brief.ai === 'off' ? 'The AI-written brief is turned off.' : 'Nothing to summarise.' }} The rules ran; alarms never depend on the AI.</VAlert>
            <div class="text-caption text-medium-emphasis">AI-written summary of rule findings · {{ at(snap.brief.at) }}</div>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-lightbulb" color="warning" /> Waiting for a decision</VCardTitle>
          <VList density="compact">
            <VListItem v-for="r in snap.recommendations" :key="r.id" :href="'/manufacturing/intelligence/recommendations'">
              <VListItemTitle>{{ r.title }}</VListItemTitle>
              <VListItemSubtitle>{{ human(r.agent) }} agent · {{ r.action_kind === 'command' ? 'proposes a command' : 'proposes a task' }} · {{ r.priority }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!snap.recommendations.length"><VListItemSubtitle>Nothing waiting.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-send" /> Commands in flight</VCardTitle>
          <VList density="compact">
            <VListItem v-for="c in snap.commands" :key="c.id" :href="'/manufacturing/command/devices'">
              <VListItemTitle>{{ human(c.command) }} <span v-if="c.parameters?.value !== undefined">→ {{ c.parameters.value }}</span> · {{ c.device }}</VListItemTitle>
              <template #append><VChip size="x-small" label :color="commandColor(c.status)">{{ human(c.status) }}</VChip></template>
            </VListItem>
            <VListItem v-if="!snap.commands.length"><VListItemSubtitle>None.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>

        <VCard border>
          <VCardTitle class="d-flex align-center gap-2"><VIcon icon="tabler-rss" /> Outside sources</VCardTitle>
          <VList density="compact">
            <VListItem v-for="s in snap.sources" :key="s.code">
              <VListItemTitle>{{ s.name }} <span class="text-caption text-medium-emphasis">{{ s.kind }} · {{ s.trust }}</span></VListItemTitle>
              <VListItemSubtitle>
                Last good {{ ago(s.last_success_at) }}<template v-if="s.last_error"> · {{ s.last_error }}</template>
              </VListItemSubtitle>
              <template #append>
                <VChip size="x-small" label :color="s.status === 'failing' ? 'error' : s.stale ? 'warning' : 'success'">{{ s.status === 'failing' ? 'failing — retrying' : s.stale ? 'stale' : 'current' }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!snap.sources.length"><VListItemSubtitle>No sources registered.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
