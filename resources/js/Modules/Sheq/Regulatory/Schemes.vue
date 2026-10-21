<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/**
 * Compliance schemes the organisation writes (GLOBALG.A.P., SIZA, organic, a customer's code),
 * adopted per site into the legal register; and the inspections that gate kinds of work.
 */
type Clause = { clause: string; instrument: string; requirement: string; category: string | null; evidence_types: string[]; review_frequency: string | null; responsible_role: string | null }
type Scheme = { uuid: string; code: string; name: string; scheme: string | null; jurisdiction: string | null; version: number; status: string; clauses: Clause[]; sites: { name: string; version: number }[] }
type Option = { value: string | number; title: string }
const props = defineProps<{
  schemes: Scheme[]
  sites: Option[]
  evidenceTypes: Option[]
  gates: { template_code: string; subject_type: string; category: string }[]
  templates: Option[]
  gateable: { subject_type: string; label: string; categories: Option[] }[]
  canManage: boolean
}>()

const keep = { preserveScroll: true }
const opened = ref<string | null>(null)

const newScheme = ref<null | { name: string; code: string; scheme: string; jurisdiction: string }>(null)
const saveScheme = () => router.post('/sheq/compliance-schemes', newScheme.value!, { ...keep, onSuccess: () => { newScheme.value = null } })

const clauseFor = ref<Scheme | null>(null)
const clause = ref<any>({})
const openClause = (s: Scheme, c?: Clause) => { clauseFor.value = s; clause.value = c ? { ...c } : { clause: '', instrument: s.scheme ?? s.name, requirement: '', category: '', evidence_types: [], review_frequency: 'P12M', responsible_role: '' } }
const saveClause = () => router.post(`/sheq/compliance-schemes/${clauseFor.value!.uuid}/clauses`, clause.value, { ...keep, onSuccess: () => { clauseFor.value = null } })

const publish = (s: Scheme) => router.post(`/sheq/compliance-schemes/${s.uuid}/publish`, {}, keep)
const adoptSite = ref<Record<string, number | null>>({})
const adopt = (s: Scheme) => router.post(`/sheq/compliance-schemes/${s.uuid}/adopt`, { site_id: adoptSite.value[s.uuid] ?? null }, keep)

const gate = ref<{ template_code: string | null; subject_type: string | null; category: string | null }>({ template_code: null, subject_type: props.gateable[0]?.subject_type ?? null, category: null })
const categoriesFor = (type: string | null) => props.gateable.find(g => g.subject_type === type)?.categories ?? []
const addGate = () => router.post('/sheq/inspection-gates', gate.value, { ...keep, onSuccess: () => { gate.value.category = null } })
const removeGate = (g: any) => router.post('/sheq/inspection-gates', { ...g, remove: true }, keep)
const titleOf = (opts: Option[], v: any) => opts.find(o => o.value === v)?.title ?? v
const evidenceTitle = (v: string) => titleOf(props.evidenceTypes, v)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.schemes.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 900px">{{ t('sheq.schemes.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="primary" prepend-icon="mdi-plus" @click="newScheme = { name: '', code: '', scheme: '', jurisdiction: '' }">{{ t('sheq.schemes.new') }}</VBtn>
    </div>

    <VCard v-for="s in schemes" :key="s.uuid" elevation="0" border rounded="lg" class="mb-3">
      <VCardItem>
        <VCardTitle class="d-flex align-center gap-2">{{ s.name }}
          <VChip size="x-small" :color="s.status === 'published' ? 'success' : 'default'" variant="tonal">{{ s.status === 'published' ? t('sheq.schemes.published') : t('sheq.schemes.draft') }}</VChip>
          <VChip v-if="s.version" size="x-small" variant="outlined">{{ t('sheq.schemes.version', { n: s.version }) }}</VChip></VCardTitle>
        <VCardSubtitle>{{ [s.scheme, s.jurisdiction, s.code].filter(Boolean).join(' · ') }}</VCardSubtitle>
        <template #append><VBtn variant="text" size="small" @click="opened = opened === s.uuid ? null : s.uuid">{{ t('sheq.schemes.clauses') }} ({{ s.clauses.length }})</VBtn></template>
      </VCardItem>
      <VCardText v-if="s.sites.length" class="pt-0 text-caption">{{ t('sheq.schemes.adopted_at') }}: {{ s.sites.map(x => `${x.name} (v${x.version})`).join(', ') }}</VCardText>
      <VCardText v-if="opened === s.uuid" class="pt-0">
        <VTable density="compact">
          <thead><tr><th>{{ t('sheq.schemes.clause') }}</th><th>{{ t('sheq.schemes.requirement') }}</th><th>{{ t('sheq.schemes.evidence') }}</th><th>{{ t('sheq.schemes.frequency') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="c in s.clauses" :key="c.clause"><td>{{ c.clause }}<div class="text-caption">{{ c.instrument }}</div></td><td>{{ c.requirement }}</td>
              <td><VChip v-for="e in c.evidence_types" :key="e" size="x-small" class="me-1">{{ evidenceTitle(e) }}</VChip></td><td>{{ c.review_frequency ?? '—' }}</td>
              <td><VBtn v-if="canManage" icon="mdi-pencil" size="x-small" variant="text" @click="openClause(s, c)" /></td></tr>
          </tbody>
        </VTable>
        <div v-if="canManage" class="d-flex flex-wrap align-center gap-2 mt-3">
          <VBtn size="small" variant="tonal" prepend-icon="mdi-plus" @click="openClause(s)">{{ t('sheq.schemes.add_clause') }}</VBtn>
          <VBtn size="small" color="primary" :disabled="!s.clauses.length" @click="publish(s)">{{ t('sheq.schemes.publish') }}</VBtn>
          <template v-if="s.status === 'published'">
            <VSelect v-model="adoptSite[s.uuid]" :items="sites" :label="t('sheq.schemes.site')" :placeholder="t('sheq.schemes.whole_org')" persistent-placeholder clearable
                     density="compact" variant="outlined" hide-details style="max-width: 260px" />
            <VBtn size="small" variant="tonal" color="primary" @click="adopt(s)">{{ t('sheq.schemes.adopt') }}</VBtn>
          </template>
        </div>
      </VCardText>
    </VCard>
    <VCard v-if="!schemes.length" elevation="0" border rounded="lg" class="mb-3"><VCardText class="text-center text-medium-emphasis py-6">{{ t('sheq.schemes.none') }}</VCardText></VCard>

    <VCard elevation="0" border rounded="lg" class="mt-6" :title="t('sheq.schemes.gates')" :subtitle="t('sheq.schemes.gates_hint')">
      <VCardText>
        <VTable density="compact">
          <thead><tr><th>{{ t('sheq.schemes.template') }}</th><th>{{ t('sheq.schemes.work') }}</th><th>{{ t('sheq.schemes.kind') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="g in gates" :key="g.template_code + g.subject_type + g.category"><td>{{ titleOf(templates, g.template_code) }}</td>
              <td>{{ gateable.find(x => x.subject_type === g.subject_type)?.label ?? g.subject_type }}</td><td>{{ titleOf(categoriesFor(g.subject_type), g.category) }}</td>
              <td class="text-end"><VBtn v-if="canManage" size="x-small" variant="text" color="error" @click="removeGate(g)">{{ t('sheq.schemes.remove') }}</VBtn></td></tr>
            <tr v-if="!gates.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ t('sheq.schemes.no_gates') }}</td></tr>
          </tbody>
        </VTable>
        <div v-if="canManage && gateable.length" class="d-flex flex-wrap align-center gap-2 mt-3">
          <VSelect v-model="gate.template_code" :items="templates" :label="t('sheq.schemes.template')" density="compact" variant="outlined" hide-details style="min-width: 240px" />
          <VSelect v-model="gate.subject_type" :items="gateable.map(g => ({ value: g.subject_type, title: g.label }))" :label="t('sheq.schemes.work')" density="compact" variant="outlined" hide-details style="min-width: 160px" />
          <VSelect v-model="gate.category" :items="categoriesFor(gate.subject_type)" :label="t('sheq.schemes.kind')" density="compact" variant="outlined" hide-details style="min-width: 180px" />
          <VBtn size="small" color="primary" :disabled="!gate.template_code || !gate.category" @click="addGate">{{ t('sheq.schemes.add_gate') }}</VBtn>
        </div>
      </VCardText>
    </VCard>

    <VDialog :model-value="!!newScheme" max-width="560" @update:model-value="v => { if (!v) newScheme = null }">
      <VCard v-if="newScheme" :title="t('sheq.schemes.new')">
        <VCardText><VRow dense>
          <VCol cols="12" md="8"><VTextField v-model="newScheme.name" :label="t('sheq.schemes.name')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="newScheme.code" :label="t('sheq.schemes.code')" /></VCol>
          <VCol cols="12" md="8"><VTextField v-model="newScheme.scheme" :label="t('sheq.schemes.scheme')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="newScheme.jurisdiction" :label="t('sheq.schemes.jurisdiction')" /></VCol>
        </VRow></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="newScheme = null">Cancel</VBtn><VBtn color="primary" :disabled="!newScheme.name" @click="saveScheme">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!clauseFor" max-width="720" @update:model-value="v => { if (!v) clauseFor = null }">
      <VCard v-if="clauseFor" :title="`${clauseFor.name} — ${t('sheq.schemes.clause')}`">
        <VCardText><VRow dense>
          <VCol cols="12" md="3"><VTextField v-model="clause.clause" :label="t('sheq.schemes.clause')" /></VCol>
          <VCol cols="12" md="9"><VTextField v-model="clause.instrument" :label="t('sheq.schemes.instrument')" /></VCol>
          <VCol cols="12"><VTextarea v-model="clause.requirement" :label="t('sheq.schemes.requirement')" rows="2" /></VCol>
          <VCol cols="12" md="8"><VSelect v-model="clause.evidence_types" :items="evidenceTypes" :label="t('sheq.schemes.evidence')" multiple chips /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="clause.review_frequency" :label="t('sheq.schemes.frequency')" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="clause.category" :label="t('sheq.schemes.category')" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="clause.responsible_role" :label="t('sheq.schemes.role')" /></VCol>
        </VRow></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="clauseFor = null">Cancel</VBtn>
          <VBtn color="primary" :disabled="!clause.clause || !clause.requirement || !clause.evidence_types?.length" @click="saveClause">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
