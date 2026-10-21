<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import { human } from './Components/agri'

const props = defineProps<{
  suggestions: Array<{ topic: string; question: string }>
  plans: Array<{ value: string; title: string }>
  drafts: any[]
  models: any[]
  references: any[]
  options: { crop_types: any[]; kinds: string[] }
  can: { references: boolean }
}>()

type Answer = { topic: string | null; title: string; refused: boolean; reply: string; reply_source: string; columns: string[]; rows: any[]; sources: string[]; flags: Array<{ level: string; message: string }>
  recommendations: any[]; farms?: string[]; as_of?: string; period?: string; assumptions?: string[]; drafts?: any[]; advisory?: boolean; questions?: string[]; references?: any[]; context?: any }
const tab = ref('ask')
const question = ref('')
const planId = ref<string | null>(null)
const loading = ref(false)
const thread = ref<Array<{ q: string; topic?: string; a?: Answer; error?: string }>>([])
const keep = { preserveScroll: true }

async function ask(q?: string, topic?: string) {
  const text = (q ?? question.value).trim()
  if (!text) return
  const entry = { q: text, topic } as { q: string; topic?: string; a?: Answer; error?: string }
  thread.value.unshift(entry)
  question.value = ''
  loading.value = true
  try {
    const { data } = await axios.post(route('agri.assistant.ask'), { question: text, topic, plan_id: planId.value })
    entry.a = data
  } catch (e: any) {
    entry.error = e?.response?.data?.message ?? 'The assistant could not answer.'
  } finally {
    loading.value = false
    thread.value = [...thread.value]
  }
}
const cell = (v: any) => (v === null || v === undefined ? '—' : typeof v === 'number' ? new Intl.NumberFormat('en-ZA', { maximumFractionDigits: 2 }).format(v) : v)
const decide = (d: any, accept: boolean) => router.post(route('agri.assistant.drafts.decide', d.uuid), { accept }, { ...keep, onSuccess: () => { d.status = accept ? 'accepted' : 'discarded' } })
const keepReport = (t: any) => router.post(route('agri.assistant.report'), { question: t.q, topic: t.a?.topic }, keep)

// references
const refForm = ref<any>(null)
const newRef = (r: any = null) => { refForm.value = r ? { ...r, stages: r.stages ?? [], topics: r.topics ?? [] } : { title: '', kind: 'agronomic', crop_type_id: null, stages: [], region: '', topics: [], content: '', source: '', review_by: null } }
const saveRef = () => router.post(refForm.value.uuid ? route('agri.assistant.references.update', refForm.value.uuid) : route('agri.assistant.references.store'), refForm.value,
  { ...keep, onSuccess: () => { refForm.value = null } })
const approveRef = (r: any) => router.post(route('agri.assistant.references.approve', r.uuid), {}, keep)
const metric = (m: any) => {
  if (!m.metric) return 'No outcomes known yet'
  if (m.model === 'yield') return `Off by ${m.metric.mean_abs_error_pct}% on average (bias ${m.metric.bias_pct}%)`
  if (m.model === 'harvest_ready') return `Off by ${m.metric.mean_abs_days} days on average (bias ${m.metric.bias_days} days)`
  if (m.model === 'stock_out') return `${m.metric.ran_out_or_nearly}% of flagged items did run (nearly) out`
  return Object.entries(m.metric).map(([band, v]: any) => `${human(band)}: ${v.broke_down}/${v.assessed} broke down`).join(' · ')
}
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-4">
      <h4 class="text-h4 mb-1">Farm Assistant</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Answers come from your own records, only for the farms and modules you can open. It proposes; a person decides. It never pays, sprays, changes payroll or the books, disposes of stock or starts machinery.</p>
    </div>
    <VTabs v-model="tab" class="mb-4">
      <VTab value="ask">Ask</VTab><VTab value="drafts">Proposals <VBadge v-if="drafts.filter(d => d.status === 'proposed').length" :content="drafts.filter(d => d.status === 'proposed').length" inline color="primary" /></VTab>
      <VTab value="models">How predictions have done</VTab><VTab value="references">Approved references</VTab>
    </VTabs>

    <template v-if="tab === 'ask'">
      <VCard class="mb-4">
        <VCardText>
          <div class="d-flex gap-2">
            <VTextField v-model="question" placeholder="e.g. Which stock items may run out before harvest?" prepend-inner-icon="tabler-message-question" :loading="loading"
              append-inner-icon="tabler-send" hide-details @keyup.enter="ask()" @click:append-inner="ask()" />
            <VSelect v-model="planId" :items="plans" label="About crop (for advice)" clearable hide-details style="max-inline-size: 260px" />
          </div>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <VChip v-for="s in suggestions" :key="s.topic" size="small" variant="tonal" @click="ask(s.question, s.topic)">{{ s.question }}</VChip>
          </div>
        </VCardText>
      </VCard>

      <VCard v-for="(t, i) in thread" :key="i" class="mb-4">
        <VCardItem>
          <template #prepend><VAvatar color="primary" variant="tonal" size="32"><VIcon icon="tabler-user" /></VAvatar></template>
          <VCardTitle class="text-wrap text-body-1 font-weight-medium">{{ t.q }}</VCardTitle>
          <template v-if="t.a?.topic && t.a.topic !== 'advice' && !t.a.refused" #append><VBtn size="small" variant="text" prepend-icon="tabler-file-text" @click="keepReport(t)">Keep as report</VBtn></template>
        </VCardItem>
        <VCardText v-if="!t.a && !t.error"><VProgressLinear indeterminate /></VCardText>
        <VCardText v-else-if="t.error"><VAlert type="error" variant="tonal">{{ t.error }}</VAlert></VCardText>
        <VCardText v-else-if="t.a">
          <div class="d-flex gap-3 mb-3">
            <VAvatar color="success" variant="tonal" size="32"><VIcon icon="tabler-robot" /></VAvatar>
            <div>
              <VAlert v-if="t.a.refused" type="warning" variant="tonal" density="compact">{{ t.a.reply }}</VAlert>
              <p v-else class="text-body-1 mb-1">{{ t.a.reply }}</p>
              <div class="text-caption text-medium-emphasis">
                {{ t.a.reply_source === 'MODEL' ? 'Worded by AI from the figures below' : t.a.reply_source === 'references' ? 'From approved references only' : 'Computed from your records' }}
                <template v-if="t.a.period"> · Period: {{ t.a.period }}</template><template v-if="t.a.sources?.length"> · Source: {{ t.a.sources.join('; ') }}</template>
              </div>
              <div v-if="t.a.assumptions?.length" class="text-caption text-medium-emphasis">Assumes: {{ t.a.assumptions.join(' ') }}</div>
            </div>
          </div>
          <template v-if="t.a.advisory">
            <VList v-if="t.a.questions?.length" density="compact"><VListItem v-for="(q, k) in t.a.questions" :key="k" prepend-icon="tabler-help" :title="q" /></VList>
            <VCard v-for="(r, k) in t.a.references" :key="k" variant="outlined" class="mb-2"><VCardText>
              <div class="font-weight-medium">{{ r.title }} <VChip size="x-small">{{ human(r.kind) }}</VChip></div>
              <div class="text-body-2 my-1">"{{ r.excerpt }}"</div>
              <div class="text-caption text-medium-emphasis">{{ r.source }} · approved {{ r.approved_on }}</div></VCardText></VCard>
          </template>
          <VAlert v-for="(fl, k) in t.a.flags" :key="k" :type="fl.level === 'WARNING' ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-2">{{ fl.message }}</VAlert>
          <VTable v-if="t.a.rows?.length" density="compact" class="text-no-wrap">
            <thead><tr><th v-for="c in t.a.columns" :key="c">{{ human(c) }}</th></tr></thead>
            <tbody><tr v-for="(r, j) in t.a.rows" :key="j"><td v-for="c in t.a.columns" :key="c"><a v-if="c === t.a.columns[1] && r.url" :href="r.url">{{ cell(r[c]) }}</a><template v-else>{{ cell(r[c]) }}</template></td></tr></tbody>
          </VTable>
          <div v-for="(r, k) in t.a.recommendations" :key="k" class="mt-2 text-body-2"><VIcon icon="tabler-bulb" color="warning" size="16" /> {{ typeof r === 'string' ? r : r.text }} <a v-if="r?.url" :href="r.url">Open</a></div>
          <div v-if="t.a.drafts?.length" class="mt-3">
            <div class="text-subtitle-2">Proposed — nothing happens until you accept it</div>
            <div v-for="d in t.a.drafts" :key="d.uuid" class="d-flex align-center gap-2 text-body-2">
              <VChip size="x-small">{{ human(d.kind) }}</VChip> {{ d.title }} <span class="text-caption text-medium-emphasis">{{ d.reason }}</span>
              <VSpacer /><template v-if="d.status === 'proposed'"><VBtn size="x-small" color="primary" variant="tonal" @click="decide(d, true)">Accept as draft</VBtn>
                <VBtn size="x-small" variant="text" @click="decide(d, false)">Discard</VBtn></template><VChip v-else size="x-small">{{ human(d.status) }}</VChip>
            </div>
          </div>
        </VCardText>
      </VCard>
    </template>

    <template v-if="tab === 'drafts'">
      <VCard><VTable density="compact">
        <thead><tr><th>Proposal</th><th>Why</th><th>Status</th><th /></tr></thead>
        <tbody><tr v-for="d in drafts" :key="d.id"><td><VChip size="x-small" class="me-1">{{ human(d.kind) }}</VChip>{{ d.title }}</td><td class="text-caption">{{ d.reason }}</td>
          <td><VChip size="x-small" :color="d.status === 'accepted' ? 'success' : undefined">{{ human(d.status) }}</VChip></td>
          <td class="text-end text-no-wrap"><template v-if="d.status === 'proposed'"><VBtn size="x-small" color="primary" variant="tonal" @click="decide(d, true)">Accept as draft</VBtn>
            <VBtn size="x-small" variant="text" @click="decide(d, false)">Discard</VBtn></template></td></tr>
          <tr v-if="!drafts.length"><td colspan="4" class="text-center text-medium-emphasis py-4">No proposals. Ask a question; where an action helps, the assistant proposes one.</td></tr></tbody>
      </VTable></VCard>
    </template>

    <template v-if="tab === 'models'">
      <VAlert type="info" variant="tonal" class="mb-4">Each prediction is written down when it is made and checked when the outcome is known. These are the farm's own results, not a vendor's claims.</VAlert>
      <VRow>
        <VCol v-for="m in models" :key="m.model" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ m.name }}</VCardTitle><VCardSubtitle>{{ m.scored }} of {{ m.predictions }} predictions checked · confidence {{ m.confidence }}</VCardSubtitle></VCardItem>
          <VCardText>
            <div class="text-body-1 mb-2">{{ metric(m) }}</div>
            <div class="text-caption">Limitations: {{ m.limitations }}</div>
            <div class="text-caption text-medium-emphasis">Data as of {{ m.data_as_of ?? '—' }}<template v-if="m.last_scored"> · last checked {{ m.last_scored }}</template></div>
          </VCardText></VCard></VCol>
      </VRow>
    </template>

    <template v-if="tab === 'references'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.references" color="primary" prepend-icon="tabler-plus" @click="newRef()">Reference</VBtn></div>
      <VAlert type="info" variant="tonal" class="mb-3">Advice quotes only approved references. A reference is approved by someone other than who wrote it, and goes back to draft when changed.</VAlert>
      <VCard><VTable density="compact">
        <thead><tr><th>Reference</th><th>Applies to</th><th>Source</th><th>Status</th><th /></tr></thead>
        <tbody><tr v-for="r in references" :key="r.id"><td>{{ r.title }}<div class="text-caption">{{ human(r.kind) }}<template v-if="r.topics?.length"> · {{ r.topics.join(', ') }}</template></div></td>
          <td class="text-caption">{{ r.crop ?? 'Any crop' }}<template v-if="r.stages?.length"> · {{ r.stages.join(', ') }}</template><template v-if="r.region"> · {{ r.region }}</template></td>
          <td class="text-caption">{{ r.source }}<template v-if="r.review_by"><br>review by {{ String(r.review_by).slice(0, 10) }}</template></td>
          <td><VChip size="x-small" :color="r.status === 'approved' ? 'success' : undefined">{{ human(r.status) }}</VChip></td>
          <td class="text-end text-no-wrap"><template v-if="can.references"><VBtn v-if="r.status === 'draft'" size="x-small" color="success" variant="tonal" @click="approveRef(r)">Approve</VBtn>
            <VBtn size="x-small" variant="text" @click="newRef(r)">Edit</VBtn></template></td></tr>
          <tr v-if="!references.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No references yet: the assistant gives no advice until some are approved.</td></tr></tbody>
      </VTable></VCard>
    </template>

    <VDialog :model-value="!!refForm" max-width="820" @update:model-value="v => { if (!v) refForm = null }">
      <VCard v-if="refForm" title="Reference">
        <VCardText><VRow dense>
          <VCol cols="8"><VTextField v-model="refForm.title" label="Title *" /></VCol><VCol cols="4"><VSelect v-model="refForm.kind" :items="options.kinds.map(k => ({ title: human(k), value: k }))" label="Kind" /></VCol>
          <VCol cols="4"><VAutocomplete v-model="refForm.crop_type_id" :items="options.crop_types" label="Crop (blank: any)" clearable /></VCol>
          <VCol cols="4"><VCombobox v-model="refForm.stages" multiple chips label="Growth stages (blank: any)" /></VCol>
          <VCol cols="4"><VTextField v-model="refForm.region" label="Region (blank: any)" /></VCol>
          <VCol cols="12"><VCombobox v-model="refForm.topics" multiple chips label="Found by (topics)" hint="rust, nitrogen, planting date…" persistent-hint /></VCol>
          <VCol cols="12"><VTextarea v-model="refForm.content" label="Content *" rows="8" /></VCol>
          <VCol cols="8"><VTextField v-model="refForm.source" label="Source *" hint="Publisher, edition, page" persistent-hint /></VCol>
          <VCol cols="4"><VTextField v-model="refForm.review_by" type="date" label="Review by" /></VCol>
        </VRow></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="refForm = null">Cancel</VBtn><VBtn color="primary" @click="saveRef">Save as draft</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
