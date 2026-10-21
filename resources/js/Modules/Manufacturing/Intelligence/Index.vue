<script setup lang="ts">
/**
 * Intelligence: what the agents recommend (with the evidence, for a person to approve or reject),
 * disease outbreaks and the farms near them (distance, never diagnosis), funding and markets the
 * farms may suit (suggestions, never applications), the outside sources and what they published
 * (flagged when it reads like an instruction), and how accurate each forecast model has been.
 */
import { ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { ago, at, human, money, num, statusColor } from '../Agriculture/prd'

const props = defineProps<{
  tab: string
  recommendations: Array<Record<string, any>>
  outbreaks: Array<Record<string, any>>
  opportunities: Array<Record<string, any>>
  disclaimer: string
  sources: Array<Record<string, any>>
  publications: Array<Record<string, any>>
  accuracy: Array<{ model: string; version: string; metric: string; forecasts: number; mape: number | null; bias: number | null; coverage: number | null }>
  forecastsRecent: Array<Record<string, any>>
  champions?: Record<string, { model: string; version: string; mape: number | null; chosen_at: string }>
  brief: Record<string, any> | null
  can: { decide: boolean; manage: boolean }
}>()
const tabs = [
  { value: 'recommendations', title: 'Recommendations', icon: 'tabler-bulb' }, { value: 'outbreaks', title: 'Outbreaks', icon: 'tabler-virus' },
  { value: 'opportunities', title: 'Funding & markets', icon: 'tabler-coins' }, { value: 'sources', title: 'Sources', icon: 'tabler-rss' },
  { value: 'forecasts', title: 'Forecast accuracy', icon: 'tabler-chart-dots' }, { value: 'assistant', title: 'Ask', icon: 'tabler-message-chatbot' },
]
const go = (t: string) => router.visit(`/manufacturing/intelligence/${t}`)
const deciding = ref<{ id: string; decision: string } | null>(null)
const note = ref('')
const decide = () => router.post(`/manufacturing/intelligence/recommendations/${deciding.value!.id}`, { decision: deciding.value!.decision, note: note.value },
  { preserveScroll: true, onSuccess: () => { deciding.value = null; note.value = '' } })
const match = (id: string, status: string) => router.post(`/manufacturing/intelligence/matches/${id}`, { status }, { preserveScroll: true })
const running = ref(false)
const runAgents = () => router.post('/manufacturing/intelligence/run', {}, { preserveScroll: true, onStart: () => { running.value = true }, onFinish: () => { running.value = false } })
const adding = ref(false)
const sourceFields: FormField[] = [
  { key: 'code', label: 'Code (CAPITALS)', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
  { key: 'kind', label: 'Kind', type: 'select', items: ['weather', 'disease', 'government', 'ngo', 'market', 'research'], required: true, cols: 4 },
  { key: 'trust', label: 'Trust', type: 'select', items: ['official', 'trusted', 'community', 'unverified'], default: 'official', cols: 4 },
  { key: 'refresh_minutes', label: 'Check every (minutes)', type: 'number', default: 60, cols: 4 },
  { key: 'publisher', label: 'Publisher', cols: 6 }, { key: 'url', label: 'Address (https)', cols: 6 },
  { key: 'adapter', label: 'Fetch it as', type: 'select', items: [{ title: 'Entered by hand', value: '' }, { title: 'RSS / Atom feed', value: 'rss' }, { title: 'CAP alerts (weather warnings)', value: 'cap' }, { title: 'JSON API', value: 'json' }], cols: 6 },
  { key: 'item_kind', label: 'Each item is', type: 'select', items: ['notice', 'warning', 'outbreak', 'funding', 'price', 'advisory'], cols: 6, show: f => !!f.adapter },
  { key: 'auth_type', label: 'The provider issued', type: 'select', cols: 6, show: f => !!f.adapter,
    items: [{ title: 'Nothing — open feed', value: 'none' }, { title: 'An API key in a header', value: 'header' }, { title: 'A bearer token', value: 'bearer' }, { title: 'An API key in the address', value: 'query' }, { title: 'A username and password', value: 'basic' }] },
  { key: 'auth_name', label: 'Header or parameter name', hint: 'e.g. X-API-Key, apikey', cols: 6, show: f => ['header', 'query'].includes(f.auth_type) },
  { key: 'auth_value', label: 'Key or token (stored encrypted)', cols: 12, show: f => ['header', 'bearer', 'query'].includes(f.auth_type) },
  { key: 'auth_username', label: 'Username', cols: 6, show: f => f.auth_type === 'basic' },
  { key: 'auth_password', label: 'Password (stored encrypted)', cols: 6, show: f => f.auth_type === 'basic' },
  { key: 'config_json', label: 'Field map (JSON)', type: 'textarea', cols: 12, show: f => f.adapter === 'json' || f.adapter === 'cap',
    hint: 'JSON API: {"list":"data.items","map":{"external_id":"id","title":"headline","body":"summary"}}. CAP: {"default_radius_km":50}' },
]
const fetching = ref<string | null>(null)
const fetchNow = (id: string) => router.post(`/manufacturing/intelligence/sources/${id}/fetch`, {}, { preserveScroll: true, onStart: () => { fetching.value = id }, onFinish: () => { fetching.value = null } })
// The assistant: answered from the records; it never acts.
const question = ref('')
const asking = ref(false)
const chat = ref<Array<{ q: string; a: string; ai: string; topics: string[] }>>([])
async function ask() {
  if (question.value.trim().length < 3) return
  asking.value = true
  const q = question.value
  try {
    const r = await axios.post('/manufacturing/intelligence/assistant', { question: q })
    chat.value.unshift({ q, a: r.data.answer, ai: r.data.ai, topics: r.data.topics })
    question.value = ''
  }
  catch (e: any) { chat.value.unshift({ q, a: e?.response?.data?.message ?? 'The question could not be answered.', ai: 'error', topics: [] }) }
  finally { asking.value = false }
}
const verify = (p: any) => { const note = prompt(`How was "${p.title}" verified? (e.g. confirmed with the state vet, a second source)`); if (note) router.post(`/manufacturing/intelligence/publications/${p.id}/verify`, { note }, { preserveScroll: true }) }
const bandColor = (b: string) => b === 'inside' ? 'error' : b === 'near' ? 'warning' : 'info'
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Intelligence</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Advice from the agents and outside sources. Nothing here acts without a person.</p>
      </div>
      <VBtn v-if="can.decide" variant="tonal" prepend-icon="tabler-robot" :loading="running" @click="runAgents">Run the agents now</VBtn>
    </div>
    <VTabs :model-value="tab" class="mb-4" @update:model-value="v => go(String(v))">
      <VTab v-for="t in tabs" :key="t.value" :value="t.value" :prepend-icon="t.icon">{{ t.title }}</VTab>
    </VTabs>

    <!-- Recommendations -->
    <template v-if="tab === 'recommendations'">
      <VAlert v-if="brief?.brief" type="info" variant="tonal" class="mb-4" icon="tabler-robot" title="Brief (AI-written from the rule findings)">
        <div style="white-space: pre-line">{{ brief.brief }}</div><div class="text-caption mt-1">{{ at(brief.at) }}</div>
      </VAlert>
      <VCard v-for="r in recommendations" :key="r.id" border class="mb-3">
        <VCardText>
          <div class="d-flex align-start gap-3 flex-wrap">
            <div class="flex-grow-1" style="min-width: 260px">
              <div class="d-flex align-center gap-2 mb-1">
                <VChip size="x-small" label :color="statusColor(r.priority)">{{ r.priority }}</VChip>
                <VChip size="x-small" label>{{ human(r.agent) }} agent</VChip>
                <VChip size="x-small" label :color="r.action_kind === 'command' ? 'warning' : undefined">{{ r.action_kind === 'command' ? 'proposes a command' : r.action_kind === 'task' ? 'proposes a task' : 'for review' }}</VChip>
                <span class="text-caption text-medium-emphasis">{{ r.farm }} · {{ ago(r.created_at) }}<template v-if="r.confidence !== null"> · confidence {{ Math.round(r.confidence * 100) }}%</template> · {{ r.ai_used ? 'AI' : 'rules' }}</span>
              </div>
              <div class="font-weight-medium">{{ r.title }}</div>
              <div class="text-body-2 mt-1">{{ r.rationale }}</div>
              <details v-if="r.evidence" class="text-caption mt-1"><summary>Evidence</summary><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(r.evidence, null, 2) }}</pre></details>
              <div v-if="r.decision_note" class="text-caption mt-1">Decision: {{ r.decision_note }}</div>
            </div>
            <div class="text-end">
              <VChip size="small" label :color="statusColor(r.status)" class="mb-2">{{ human(r.status) }}</VChip>
              <div v-if="r.status === 'proposed' && can.decide" class="d-flex gap-1">
                <VBtn size="small" color="success" variant="tonal" @click="deciding = { id: r.id, decision: 'approve' }">Approve</VBtn>
                <VBtn size="small" variant="text" @click="deciding = { id: r.id, decision: 'reject' }">Reject</VBtn>
              </div>
              <div v-if="r.task_id" class="text-caption">Farm task created</div>
              <div v-if="r.command_id" class="text-caption">Command requested — waiting for its approval</div>
            </div>
          </div>
        </VCardText>
      </VCard>
      <VCard v-if="!recommendations.length" border><VCardText class="text-center text-medium-emphasis">No recommendations.</VCardText></VCard>
    </template>

    <!-- Outbreaks -->
    <template v-if="tab === 'outbreaks'">
      <VAlert type="info" variant="tonal" class="mb-4">An exposure is distance from an official or trusted report — a reason to review biosecurity, never a diagnosis. No farm is marked infected from here.</VAlert>
      <VCard v-for="o in outbreaks" :key="o.id" border class="mb-3">
        <VCardText>
          <div class="d-flex align-center gap-2 mb-1 flex-wrap">
            <VChip size="small" label :color="o.status === 'confirmed' ? 'error' : o.status === 'suspected' ? 'warning' : undefined">{{ o.status }}</VChip>
            <span class="font-weight-medium">{{ o.disease }}</span>
            <span class="text-caption text-medium-emphasis">{{ o.species }} · {{ o.region }} · radius {{ num(o.radius_km, 0) }} km · reported {{ o.reported_on }} · {{ o.source }} ({{ o.trust }})<template v-if="o.revision > 1"> · revised {{ o.revision - 1 }}×</template></span>
          </div>
          <VTable v-if="o.exposures.length" density="compact">
            <tbody><tr v-for="(e, k) in o.exposures" :key="k">
              <td style="width: 160px">{{ e.farm }}</td><td style="width: 90px">{{ num(e.distance_km, 1) }} km</td>
              <td style="width: 90px"><VChip size="x-small" label :color="bandColor(e.band)">{{ e.band }}</VChip></td>
              <td class="text-caption">{{ e.statement }}</td><td><VChip size="x-small" label>{{ e.status }}</VChip></td>
            </tr></tbody>
          </VTable>
          <div v-else class="text-caption text-medium-emphasis">No farm within range.</div>
        </VCardText>
      </VCard>
      <VCard v-if="!outbreaks.length" border><VCardText class="text-center text-medium-emphasis">No outbreaks reported by the registered sources.</VCardText></VCard>
    </template>

    <!-- Opportunities -->
    <template v-if="tab === 'opportunities'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ disclaimer }}</VAlert>
      <VCard v-for="o in opportunities" :key="o.id" border class="mb-3">
        <VCardText>
          <div class="d-flex align-center gap-2 flex-wrap mb-1">
            <VChip size="small" label>{{ human(o.kind) }}</VChip><span class="font-weight-medium">{{ o.title }}</span>
            <span class="text-caption text-medium-emphasis">{{ o.funder }}<template v-if="o.amount_max"> · up to {{ money(o.amount_max, o.currency) }}</template><template v-if="o.closes_on"> · closes {{ o.closes_on }}</template></span>
            <VSpacer /><VBtn v-if="o.url" size="small" variant="text" :href="o.url" target="_blank" append-icon="tabler-external-link">Read the call</VBtn>
          </div>
          <p v-if="o.summary" class="text-body-2">{{ o.summary }}</p>
          <div v-for="m in o.matches" :key="m.id" class="d-flex align-start gap-2 py-2 border-t">
            <VChip size="small" label :color="m.score >= 80 ? 'success' : 'info'">{{ m.score }}%</VChip>
            <div class="flex-grow-1"><div class="font-weight-medium">{{ m.farm }}</div>
              <div class="text-caption">{{ m.reasons.join(' ') }}</div>
              <div v-if="m.gaps?.length" class="text-caption text-warning">{{ m.gaps.join(' ') }}</div></div>
            <VChip size="x-small" label>{{ m.status }}</VChip>
            <template v-if="can.decide && m.status !== 'dismissed'">
              <VBtn size="x-small" variant="tonal" @click="match(m.id, 'reviewing')">Reviewing</VBtn>
              <VBtn size="x-small" variant="text" @click="match(m.id, 'dismissed')">Dismiss</VBtn>
            </template>
          </div>
        </VCardText>
      </VCard>
      <VCard v-if="!opportunities.length" border><VCardText class="text-center text-medium-emphasis">No opportunities registered.</VCardText></VCard>
    </template>

    <!-- Sources -->
    <template v-if="tab === 'sources'">
      <div class="d-flex justify-end mb-2"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="adding = true">Register a source</VBtn></div>
      <VCard border class="mb-4">
        <VTable density="comfortable">
          <thead><tr><th>Source</th><th>Kind</th><th>Trust</th><th>Last good fetch</th><th>Next</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="s in sources" :key="s.id">
              <td class="font-weight-medium">{{ s.name }}<div class="text-caption text-medium-emphasis">{{ s.code }}<template v-if="s.publisher"> · {{ s.publisher }}</template></div></td>
              <td>{{ human(s.kind) }}</td><td>{{ human(s.trust) }}</td>
              <td>{{ ago(s.last_success_at) }}<div v-if="s.last_error" class="text-caption text-error">{{ s.last_error }} ({{ s.failure_count }}×)</div></td>
              <td>{{ s.next_attempt_at ? at(s.next_attempt_at) : '—' }}</td>
              <td><VChip size="small" label :color="s.status === 'failing' ? 'error' : s.stale ? 'warning' : 'success'">{{ s.status === 'failing' ? 'failing — retrying' : s.stale ? 'stale' : 'current' }}</VChip>
                <div class="text-caption">{{ s.adapter ? `${s.adapter.toUpperCase()} · ${s.item_kind ?? 'notice'}` : 'by hand' }}<template v-if="s.has_credentials"> · <VIcon icon="tabler-key" size="12" /> credentials</template></div>
                <VBtn v-if="s.adapter && can.manage" size="x-small" variant="text" :loading="fetching === s.id" @click="fetchNow(s.id)">Fetch now</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard border>
        <VCardTitle>Latest published</VCardTitle>
        <VList density="compact">
          <VListItem v-for="(p, k) in publications" :key="k">
            <VListItemTitle><VIcon v-if="p.flagged" icon="tabler-alert-triangle" color="warning" size="16" /> {{ p.title }} <span class="text-caption text-medium-emphasis">{{ p.source }} · {{ human(p.kind) }} · {{ ago(p.fetched_at) }}<template v-if="p.revision > 1"> · revision {{ p.revision }}</template></span></VListItemTitle>
            <VListItemSubtitle style="white-space: normal">{{ p.body }}</VListItemSubtitle>
            <div v-if="p.flagged" class="text-caption text-warning">{{ p.flags.join(' ') }}</div>
            <div v-if="p.verified_at" class="text-caption text-success">Verified: {{ p.verification_note }}</div>
            <VBtn v-else-if="['community', 'unverified'].includes(p.trust) && !p.flagged && can.decide" size="x-small" variant="tonal" class="mt-1" @click="verify(p)">Verify</VBtn>
          </VListItem>
        </VList>
      </VCard>
      <FormDialog v-model="adding" title="Register a source" :fields="sourceFields" url="/manufacturing/intelligence/sources" />
    </template>

    <!-- Forecasts -->
    <template v-if="tab === 'forecasts'">
      <VCard border class="mb-4">
        <VCardTitle>Accuracy by model</VCardTitle>
        <VTable density="comfortable">
          <thead><tr><th>Model</th><th>Forecasts</th><th class="text-end">Mean absolute % error</th><th class="text-end">Bias</th><th class="text-end">Actual inside interval</th></tr></thead>
          <tbody>
            <tr v-for="(a, k) in accuracy" :key="k"><td class="font-weight-medium">{{ a.model }} {{ a.version }}
              <VChip v-if="champions?.[a.metric]?.model === a.model" size="x-small" color="success" label class="ms-1">champion</VChip><div class="text-caption">{{ human(a.metric) }}</div></td>
              <td>{{ a.forecasts }}</td><td class="text-end">{{ a.mape === null ? '—' : `${num(a.mape, 1)}%` }}</td><td class="text-end">{{ num(a.bias, 3) }}</td>
              <td class="text-end">{{ a.coverage === null ? '—' : `${num(a.coverage, 0)}%` }}</td></tr>
            <tr v-if="!accuracy.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No forecast has reached its date yet.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard border>
        <VCardTitle>Recent forecasts</VCardTitle>
        <VTable density="compact">
          <thead><tr><th>Cycle</th><th>For</th><th class="text-end">Forecast</th><th class="text-end">Actual</th><th>Model</th></tr></thead>
          <tbody><tr v-for="(f, k) in forecastsRecent" :key="k">
            <td>{{ f.cycle }}</td><td>{{ human(f.metric) }} on {{ f.target_on }}</td>
            <td class="text-end">{{ num(f.predicted, 3) }} {{ f.unit }}<div class="text-caption">{{ num(f.lower, 3) }}–{{ num(f.upper, 3) }}</div></td>
            <td class="text-end">{{ f.actual === null ? '—' : `${num(f.actual, 3)} (${num(f.abs_pct_error, 1)}% off)` }}</td><td>{{ f.model }}</td>
          </tr></tbody>
        </VTable>
      </VCard>
    </template>

    <!-- Assistant -->
    <template v-if="tab === 'assistant'">
      <VCard border class="mb-4">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">Ask about your flocks, crops, herds, ponds, alarms, holds, water, outbreaks near your farms, funding or forecasts. Answers come from your own records; the assistant cannot operate equipment or approve anything.</p>
          <div class="d-flex gap-2">
            <VTextField v-model="question" label="Your question" placeholder="Why is mortality up in broiler house 1?" hide-details @keyup.enter="ask" />
            <VBtn color="primary" :loading="asking" :disabled="question.trim().length < 3" @click="ask">Ask</VBtn>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <VChip v-for="ex in ['What needs attention right now?', 'Which cycles cannot be sold yet?', 'How is the broiler flock doing?', 'Any outbreaks near our farms?', 'Which grants could we apply for?']"
              :key="ex" size="small" @click="question = ex; ask()">{{ ex }}</VChip>
          </div>
        </VCardText>
      </VCard>
      <VCard v-for="(m, k) in chat" :key="k" border class="mb-3">
        <VCardText>
          <div class="font-weight-medium mb-2"><VIcon icon="tabler-user" size="16" /> {{ m.q }}</div>
          <div style="white-space: pre-line">{{ m.a }}</div>
          <div class="text-caption text-medium-emphasis mt-2">{{ m.ai === 'ok' ? 'Worded by AI from your records' : 'From your records (AI wording unavailable)' }}<template v-if="m.topics.length"> · looked at {{ m.topics.join(', ') }}</template></div>
        </VCardText>
      </VCard>
    </template>

    <VDialog :model-value="!!deciding" max-width="520" @update:model-value="v => !v && (deciding = null)">
      <VCard v-if="deciding" :title="deciding.decision === 'approve' ? 'Approve recommendation' : 'Reject recommendation'">
        <VCardText>
          <p v-if="deciding.decision === 'approve'" class="text-body-2">A task proposal becomes a farm task. A command proposal is requested, and then waits for its own approval before anything is sent.</p>
          <VTextField v-model="note" :label="deciding.decision === 'approve' ? 'Note (optional)' : 'Why not'" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="deciding = null">Cancel</VBtn><VBtn :color="deciding.decision === 'approve' ? 'success' : 'error'" @click="decide">{{ human(deciding.decision) }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
