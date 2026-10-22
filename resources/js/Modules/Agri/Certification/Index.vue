<script setup lang="ts">
/**
 * Certification checklists: install GLOBALG.A.P. IFA, SIZA or organic as the organisation's own
 * scheme, adopt it at a farm, and prepare for the audit — each control point with its evidence,
 * % compliant by level, and the PDF for the auditor.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'

type Evidence = { type: string; label: string; auto: boolean; status: string; detail: string; url?: string | null }
type Point = { clause: string; section: string; level: string; requirement: string; responsible: string | null; status: string; evidence: Evidence[]; needs_document: boolean
  line_uuid: string | null; answer: null | { answer: string; comment: string | null; by: string | null; at: string | null } }
type Level = { total: number; applicable: number; compliant: number; non_compliant: number; no_evidence: number; not_applicable: number; pct: number; required: number | null; met: boolean }
const props = defineProps<{
  filters: { farm: string | null; scheme: number | null }
  farms: Array<{ id: string; name: string }>
  templates: Array<{ code: string; name: string; scheme: string; version: string; summary: string; points: number; levels: Record<string, number>; automatic: number; installed: boolean; scheme_id: number | null }>
  schemes: Array<{ id: number; name: string; version: number }>
  assessment: null | { points: Point[]; levels: Record<string, Level>; ready: boolean; adopted: boolean }
  levels: string[]
  can: { manage: boolean }
  sheq_url: string
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.certification.${key}`, params ?? {})
const keep = { preserveScroll: true }
const COLOR: Record<string, string> = { compliant: 'success', non_compliant: 'error', no_evidence: 'warning', not_applicable: 'secondary' }

const pickScheme = (id: number) => router.get(route('agri.certification.index'), { farm: props.filters.farm ?? undefined, scheme: id }, { preserveState: false })
const install = (code: string) => router.post(route('agri.certification.install'), { template: code }, keep)
const adopt = () => props.filters.farm && router.post(route('agri.certification.adopt', props.filters.farm), { scheme_id: props.filters.scheme }, keep)
const levelFilter = ref<string | null>(null)
const statusFilter = ref<string | null>(null)
const points = computed(() => (props.assessment?.points ?? []).filter(p => (!levelFilter.value || p.level === levelFilter.value) && (!statusFilter.value || p.status === statusFilter.value)))

const answering = ref<null | { point: Point; answer: string; comment: string }>(null)
const answer = (p: Point) => { answering.value = { point: p, answer: p.answer?.answer ?? 'compliant', comment: p.answer?.comment ?? '' } }
const saveAnswer = () => answering.value && props.filters.farm && router.post(route('agri.certification.answer', props.filters.farm),
  { scheme_id: props.filters.scheme, clause: answering.value.point.clause, answer: answering.value.answer, comment: answering.value.comment }, { ...keep, onSuccess: () => { answering.value = null } })
const pdfUrl = computed(() => props.filters.farm && props.filters.scheme ? `${route('agri.certification.pdf', props.filters.farm)}?scheme=${props.filters.scheme}` : null)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" :allow-all="false" :extra="{ scheme: filters.scheme ?? undefined }" />
    </div>

    <VRow class="mb-2">
      <VCol v-for="tpl in templates" :key="tpl.code" cols="12" md="4">
        <VCard class="h-100">
          <VCardItem><VCardTitle class="text-wrap">{{ tpl.name }}</VCardTitle><VCardSubtitle>{{ tpl.scheme }} · {{ tpl.version }}</VCardSubtitle></VCardItem>
          <VCardText>
            <p class="text-body-2">{{ tpl.summary }}</p>
            <div class="text-caption">{{ k('points', { n: tpl.points, auto: tpl.automatic }) }}</div>
            <div class="text-caption">{{ Object.entries(tpl.levels).map(([l, n]) => `${k(`levels.${l}`)} ${n}`).join(' · ') }}</div>
          </VCardText>
          <VCardActions>
            <VChip v-if="tpl.installed" color="success" size="small">{{ k('installed') }}</VChip>
            <VBtn v-if="tpl.installed && tpl.scheme_id" size="small" variant="text" @click="pickScheme(tpl.scheme_id)">{{ k('assess') }}</VBtn>
            <VBtn v-else-if="!tpl.installed && can.manage" size="small" color="primary" variant="tonal" @click="install(tpl.code)">{{ k('install') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
    <p class="text-caption text-medium-emphasis mb-4">{{ k('paraphrase_note') }} <a :href="sheq_url">{{ k('edit_in_sheq') }}</a></p>

    <VCard v-if="schemes.length" :title="k('self_assessment')">
      <VCardText class="d-flex flex-wrap gap-2 align-center">
        <VSelect :model-value="filters.scheme" :items="schemes.map(s => ({ title: `${s.name} (v${s.version})`, value: s.id }))" :label="k('scheme')" density="compact" hide-details
          style="max-width: 460px" @update:model-value="(v: any) => pickScheme(v)" />
        <VSpacer />
        <VBtn v-if="assessment && !assessment.adopted && can.manage" color="primary" variant="tonal" @click="adopt">{{ k('adopt') }}</VBtn>
        <VBtn v-if="pdfUrl" :href="pdfUrl" target="_blank" prepend-icon="tabler-file-type-pdf" variant="tonal">{{ k('pdf_button') }}</VBtn>
      </VCardText>
      <template v-if="assessment">
        <VAlert v-if="!assessment.adopted" type="info" variant="tonal" density="compact" class="mx-4 mb-2">{{ k('not_adopted') }}</VAlert>
        <VCardText>
          <VRow>
            <VCol v-for="(l, name) in assessment.levels" :key="name" cols="12" sm="6" md="3">
              <VCard variant="tonal" :color="l.met ? 'success' : 'error'" class="pa-3">
                <div class="text-overline">{{ k(`levels.${name}`) }}</div>
                <div class="text-h5">{{ l.pct }}%<span v-if="l.required !== null" class="text-caption"> / {{ k('required', { pct: l.required }) }}</span></div>
                <div class="text-caption">{{ k('level_counts', { ok: l.compliant, nc: l.non_compliant, ne: l.no_evidence, na: l.not_applicable }) }}</div>
              </VCard>
            </VCol>
          </VRow>
          <VChip class="mt-3" :color="assessment.ready ? 'success' : 'warning'">{{ assessment.ready ? k('ready') : k('not_ready') }}</VChip>
        </VCardText>
        <VCardText class="d-flex gap-2 pt-0">
          <VSelect v-model="levelFilter" :items="levels.map(l => ({ title: k(`levels.${l}`), value: l }))" :label="k('level')" density="compact" hide-details clearable style="max-width: 200px" />
          <VSelect v-model="statusFilter" :items="['compliant', 'non_compliant', 'no_evidence', 'not_applicable'].map(s => ({ title: k(`status.${s}`), value: s }))" :label="k('status_label')"
            density="compact" hide-details clearable style="max-width: 220px" />
        </VCardText>
        <VTable density="compact">
          <thead><tr><th>{{ k('ref') }}</th><th>{{ k('requirement') }}</th><th>{{ k('status_label') }}</th><th>{{ k('evidence') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="p in points" :key="p.clause">
              <td class="text-no-wrap">{{ p.clause }}<div class="text-caption">{{ k(`levels.${p.level}`) }}</div></td>
              <td>{{ p.requirement }}<div class="text-caption text-medium-emphasis">{{ p.section }}<template v-if="p.responsible"> · {{ p.responsible }}</template></div></td>
              <td><VChip size="x-small" :color="COLOR[p.status]">{{ k(`status.${p.status}`) }}</VChip></td>
              <td class="text-caption">
                <div v-for="(e, i) in p.evidence" :key="i"><VIcon size="12" :icon="e.auto ? 'tabler-database' : 'tabler-file'" :color="COLOR[e.status]" />
                  <a v-if="e.url" :href="e.url">{{ e.label }}</a><template v-else>{{ e.label }}</template>: {{ e.detail }}</div>
                <div v-if="p.needs_document && !p.evidence.some(e => !e.auto)" class="text-medium-emphasis">{{ k('link_document') }}</div>
                <div v-if="p.answer" class="mt-1">{{ k('answered', { answer: k(`answers.${p.answer.answer}`), by: p.answer.by ?? '—', at: p.answer.at ?? '' }) }}<span v-if="p.answer.comment"> — {{ p.answer.comment }}</span></div>
              </td>
              <td class="text-end"><VBtn v-if="can.manage && assessment.adopted" size="x-small" variant="text" @click="answer(p)">{{ k('answer') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </template>
    </VCard>
    <VAlert v-else type="info" variant="tonal">{{ k('no_schemes') }}</VAlert>

    <VDialog v-if="answering" :model-value="true" max-width="560" @update:model-value="answering = null">
      <VCard :title="`${answering.point.clause} — ${k('answer')}`">
        <VCardText>
          <p class="text-body-2 mb-3">{{ answering.point.requirement }}</p>
          <VRadioGroup v-model="answering.answer" inline>
            <VRadio v-for="a in ['compliant', 'non_compliant', 'not_applicable']" :key="a" :value="a" :label="k(`answers.${a}`)" />
          </VRadioGroup>
          <VTextarea v-model="answering.comment" :label="k('comment')" :hint="k('comment_hint')" persistent-hint rows="3" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="answering = null">{{ k('cancel') }}</VBtn><VBtn color="primary" @click="saveAnswer">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
