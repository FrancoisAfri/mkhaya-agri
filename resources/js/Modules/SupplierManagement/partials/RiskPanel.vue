<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Component { label: string; score: number; weight: number; covered: boolean; reasons: string[] }
interface Hit {
  id: number; subject: string; subject_type: string; list_type: string; list_name: string | null
  matched_name: string; match_score: number
  match_basis: { factor: string; effect: string; detail: string }[]
  status: string; note: string | null; times_seen: number; first_seen: string | null; last_seen: string | null
}

const props = defineProps<{
  /** The supplier's uuid — suppliers are addressed by it, never by the row id. */
  supplierUuid: string
  people: { id: number; name: string; role: string }[]
  risk: {
    score: {
      total: number; band: string; band_label: string; requires: string; movement: number | null
      components: Record<string, Component>; auto_approvable: boolean
      blockers: string[]; edd_triggers: string[]; calculated_at: string | null; trigger: string
    } | null
    history: { total: number; band: string; at: string | null; trigger: string }[]
    tier: {
      current: number | null; label: string | null; factors: Record<string, any>
      available: { key: string; label: string; tier: number }[]
      outstanding: { key: string; label: string }[]
    }
    hits: Hit[]
    credit: any[]
  }
}>()

// Bands carry a word and an icon as well as a colour — "not assessed" and "excellent" must never be
// separable by hue alone.
const bandMeta: Record<string, { colour: string; icon: string }> = {
  excellent: { colour: 'success', icon: 'tabler-shield-check' },
  low: { colour: 'success', icon: 'tabler-shield-check' },
  moderate: { colour: 'warning', icon: 'tabler-shield-half' },
  high: { colour: 'error', icon: 'tabler-shield-exclamation' },
  critical: { colour: 'error', icon: 'tabler-shield-x' },
}

const openHits = computed(() => props.risk.hits.filter(h => h.status === 'open'))
const sortedComponents = computed(() =>
  Object.entries(props.risk.score?.components ?? {})
    .map(([key, c]) => ({ key, ...c, contribution: c.score * c.weight }))
    .sort((a, b) => b.contribution - a.contribution))

// A sparkline over the stored snapshots. Drawn rather than charted: it is twenty-four points of one
// series, and a chart library for that is weight without meaning.
const spark = computed(() => {
  const points = props.risk.history
  if (points.length < 2) return null
  const w = 160, h = 34
  const step = w / (points.length - 1)
  return {
    path: points.map((p, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(h - (p.total / 100) * h).toFixed(1)}`).join(' '),
    last: points[points.length - 1],
    w, h,
  }
})

const assessForm = useForm({})
function reassess() {
  assessForm.post(route('suppliers.risk.assess', props.supplierUuid), { preserveScroll: true })
}

// ── Tier ─────────────────────────────────────────────────────────────────────
const tierDialog = ref(false)
const tierForm = useForm<any>({ annual_spend: null, contract_value: null })
function openTier() {
  tierForm.clearErrors()
  Object.assign(tierForm, { annual_spend: props.risk.tier.factors?.annual_spend ?? null, contract_value: props.risk.tier.factors?.contract_value ?? null })
  props.risk.tier.available.forEach(f => { (tierForm as any)[f.key] = !!props.risk.tier.factors?.[f.key] })
  tierDialog.value = true
}
function saveTier() {
  tierForm.post(route('suppliers.risk.tier', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { tierDialog.value = false },
  })
}

// ── Screening ────────────────────────────────────────────────────────────────
const screenForm = useForm<any>({ list_type: 'sanctions' })
function screen(listType: string) {
  screenForm.list_type = listType
  screenForm.post(route('suppliers.risk.screen', props.supplierUuid), { preserveScroll: true })
}

const adjudicating = ref<Hit | null>(null)
const adjudicateForm = useForm<any>({ status: 'false_positive', note: '' })
function openAdjudicate(hit: Hit) {
  adjudicating.value = hit
  adjudicateForm.clearErrors()
  adjudicateForm.status = 'false_positive'
  adjudicateForm.note = ''
}
function saveAdjudication() {
  if (!adjudicating.value) return
  adjudicateForm.patch(route('suppliers.risk.hits.adjudicate', [props.supplierUuid, adjudicating.value.id]), {
    preserveScroll: true,
    onSuccess: () => { adjudicating.value = null },
  })
}

const hitDialog = ref(false)
const hitForm = useForm<any>({ list_type: 'sanctions', matched_name: '', list_name: '', person_id: null, external_ref: '', note: '' })
function saveHit() {
  hitForm.post(route('suppliers.risk.hits.store', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { hitDialog.value = false; hitForm.reset() },
  })
}

// ── Credit ───────────────────────────────────────────────────────────────────
const creditDialog = ref(false)
const creditForm = useForm<any>({
  provider_code: 'manual', score: null, score_max: 100, payment_behaviour: null,
  defaults_count: 0, judgments_count: 0, insolvency: false, business_rescue: false,
  report_date: new Date().toISOString().slice(0, 10),
})
function saveCredit() {
  creditForm.post(route('suppliers.risk.credit.store', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { creditDialog.value = false },
  })
}
</script>

<template>
  <VCard border>
    <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
      <span class="text-subtitle-1">{{ t('supplier_management.risk.title') }}</span>
      <div class="d-flex gap-2">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-calculator" :loading="assessForm.processing" @click="reassess">
          {{ t('supplier_management.risk.reassess') }}
        </VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-adjustments" @click="openTier">
          {{ t('supplier_management.risk.set_tier') }}
        </VBtn>
      </div>
    </VCardTitle>

    <VCardText>
      <div v-if="!risk.score" class="text-caption text-medium-emphasis">
        {{ t('supplier_management.risk.never_assessed') }}
      </div>

      <template v-else>
        <!-- The headline: the number, what it demands, and where it has been -->
        <div class="d-flex align-center flex-wrap gap-4 mb-4">
          <div>
            <div class="d-flex align-center gap-2">
              <span class="text-h4" style="font-variant-numeric: tabular-nums">{{ risk.score.total.toFixed(1) }}</span>
              <VChip size="small" variant="tonal" :color="bandMeta[risk.score.band]?.colour" :prepend-icon="bandMeta[risk.score.band]?.icon">
                {{ risk.score.band_label }}
              </VChip>
              <VChip v-if="risk.score.movement" size="x-small" variant="tonal"
                     :color="risk.score.movement > 0 ? 'error' : 'success'"
                     :prepend-icon="risk.score.movement > 0 ? 'tabler-trending-up' : 'tabler-trending-down'">
                {{ risk.score.movement > 0 ? '+' : '' }}{{ risk.score.movement }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('supplier_management.risk.assessed_at', { at: risk.score.calculated_at, trigger: risk.score.trigger }) }}
            </div>
          </div>

          <svg v-if="spark" :width="spark.w" :height="spark.h" role="img"
               :aria-label="t('supplier_management.risk.trend_alt', { n: risk.history.length })" class="ms-auto">
            <path :d="spark.path" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.7" />
            <circle :cx="spark.w" :cy="spark.h - (spark.last.total / 100) * spark.h" r="3" fill="currentColor" />
          </svg>
        </div>

        <!-- What the score authorises. Shown even when nothing is wrong: "nothing is blocking this"
             is a different statement from "we did not check". -->
        <VAlert v-if="risk.score.auto_approvable" type="success" variant="tonal" density="compact" class="mb-3" prepend-icon="tabler-circle-check">
          {{ t('supplier_management.risk.gates_pass') }}
        </VAlert>
        <template v-else>
          <div class="text-subtitle-2 mb-1">{{ t('supplier_management.risk.blocking') }}</div>
          <VList density="compact" class="py-0 mb-3">
            <VListItem v-for="(blocker, i) in risk.score.blockers" :key="i" class="px-0">
              <template #prepend><VIcon icon="tabler-alert-triangle" size="16" color="error" class="me-2" /></template>
              <VListItemTitle class="text-body-2" style="white-space: normal">{{ blocker }}</VListItemTitle>
            </VListItem>
          </VList>
        </template>

        <VAlert v-if="risk.score.edd_triggers.length" type="warning" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-zoom-check">
          <div class="font-weight-medium">{{ t('supplier_management.risk.edd_required') }}</div>
          <ul class="ps-4 mb-0">
            <li v-for="(trigger, i) in risk.score.edd_triggers" :key="i" class="text-caption">{{ trigger }}</li>
          </ul>
        </VAlert>

        <!-- The breakdown, ordered by what is actually driving the number -->
        <div class="text-subtitle-2 mb-2">{{ t('supplier_management.risk.breakdown') }}</div>
        <div style="overflow-x: auto">
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('supplier_management.risk.component') }}</th>
                <th style="min-width: 120px">{{ t('supplier_management.risk.score') }}</th>
                <th class="text-right">{{ t('supplier_management.risk.weight') }}</th>
                <th>{{ t('supplier_management.risk.why') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in sortedComponents" :key="c.key">
                <td>
                  <div class="font-weight-medium">{{ c.label }}</div>
                  <VChip v-if="!c.covered" size="x-small" variant="tonal" color="warning" class="mt-1">
                    {{ t('supplier_management.risk.not_assessed') }}
                  </VChip>
                </td>
                <td>
                  <div class="d-flex align-center gap-2">
                    <VProgressLinear :model-value="c.score" height="6" rounded
                                     :color="c.score >= 61 ? 'error' : (c.score >= 41 ? 'warning' : 'success')" style="min-width: 60px" />
                    <span class="text-caption" style="font-variant-numeric: tabular-nums">{{ c.score }}</span>
                  </div>
                </td>
                <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">{{ c.weight }}%</td>
                <td class="text-caption text-medium-emphasis" style="max-width: 44ch">
                  {{ c.reasons.join(' ') || '—' }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </template>

      <!-- Tier: how hard we should be looking -->
      <VDivider class="my-4" />
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
        <div class="text-subtitle-2">
          {{ t('supplier_management.risk.tier') }}
          <VChip v-if="risk.tier.current" size="x-small" variant="tonal" class="ms-2">
            {{ t('supplier_management.risk.tier_n', { n: risk.tier.current }) }} — {{ risk.tier.label }}
          </VChip>
          <span v-else class="text-caption text-medium-emphasis ms-2">{{ t('supplier_management.risk.tier_unset') }}</span>
        </div>
      </div>
      <div v-if="risk.tier.outstanding.length" class="text-caption text-medium-emphasis mb-2">
        {{ t('supplier_management.risk.outstanding', { checks: risk.tier.outstanding.map(c => c.label).join(', ') }) }}
      </div>

      <!-- Screening -->
      <VDivider class="my-4" />
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
        <div class="text-subtitle-2">
          {{ t('supplier_management.risk.screening') }}
          <VChip v-if="openHits.length" size="x-small" color="warning" variant="tonal" class="ms-2">
            {{ t('supplier_management.risk.awaiting', { n: openHits.length }) }}
          </VChip>
        </div>
        <div class="d-flex gap-2">
          <VBtn size="x-small" variant="tonal" @click="screen('sanctions')">{{ t('supplier_management.risk.screen_sanctions') }}</VBtn>
          <VBtn size="x-small" variant="tonal" @click="screen('pep')">{{ t('supplier_management.risk.screen_pep') }}</VBtn>
          <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="hitDialog = true">
            {{ t('supplier_management.risk.record_hit') }}
          </VBtn>
        </div>
      </div>

      <div v-if="!risk.hits.length" class="text-caption text-medium-emphasis">
        {{ t('supplier_management.risk.no_hits') }}
      </div>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('supplier_management.risk.subject') }}</th>
              <th>{{ t('supplier_management.risk.matched') }}</th>
              <th class="text-right">{{ t('supplier_management.risk.confidence') }}</th>
              <th>{{ t('supplier_management.risk.decision') }}</th>
              <th class="text-right"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="hit in risk.hits" :key="hit.id">
              <td>
                <div class="font-weight-medium">{{ hit.subject }}</div>
                <div class="text-caption text-medium-emphasis text-uppercase">{{ hit.list_type.replace('_', ' ') }}</div>
              </td>
              <td>
                <div>{{ hit.matched_name }}</div>
                <div class="text-caption text-medium-emphasis">{{ hit.list_name }}</div>
                <!-- Why it matched, so an adjudicator checks reasoning rather than trusting a number -->
                <div v-for="(basis, i) in hit.match_basis" :key="i" class="text-caption text-medium-emphasis">
                  <VIcon :icon="basis.effect === 'contradicts' ? 'tabler-x' : (basis.effect === 'confirms' ? 'tabler-check' : 'tabler-minus')" size="12" />
                  {{ basis.detail }}
                </div>
              </td>
              <td class="text-right" style="font-variant-numeric: tabular-nums">{{ hit.match_score }}</td>
              <td>
                <VChip size="x-small" variant="tonal"
                       :color="hit.status === 'confirmed' ? 'error' : (hit.status === 'open' ? 'warning' : 'default')">
                  {{ t(`supplier_management.risk.status.${hit.status}`) }}
                </VChip>
                <div v-if="hit.note" class="text-caption text-medium-emphasis" style="max-width: 34ch">{{ hit.note }}</div>
                <div v-if="hit.times_seen > 1" class="text-caption text-medium-emphasis">
                  {{ t('supplier_management.risk.seen_times', { n: hit.times_seen }) }}
                </div>
              </td>
              <td class="text-right">
                <VBtn v-if="hit.status === 'open'" size="x-small" variant="tonal" @click="openAdjudicate(hit)">
                  {{ t('supplier_management.risk.adjudicate') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <!-- Credit -->
      <VDivider class="my-4" />
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
        <div class="text-subtitle-2">{{ t('supplier_management.risk.credit') }}</div>
        <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="creditDialog = true">
          {{ t('supplier_management.risk.record_credit') }}
        </VBtn>
      </div>
      <div v-if="!risk.credit.length" class="text-caption text-medium-emphasis">
        {{ t('supplier_management.risk.no_credit') }}
      </div>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('supplier_management.risk.report_date') }}</th>
              <th class="text-right">{{ t('supplier_management.risk.bureau_score') }}</th>
              <th>{{ t('supplier_management.risk.behaviour') }}</th>
              <th class="text-right">{{ t('supplier_management.risk.judgments') }}</th>
              <th>{{ t('supplier_management.risk.flags') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in risk.credit" :key="r.id">
              <td class="text-caption">{{ r.report_date }}<div class="text-medium-emphasis">{{ r.provider }}</div></td>
              <td class="text-right" style="font-variant-numeric: tabular-nums">
                <span v-if="r.score !== null">{{ r.score }} / {{ r.score_max }}</span><span v-else>—</span>
              </td>
              <td class="text-caption text-capitalize">{{ (r.payment_behaviour || '—').replace('_', ' ') }}</td>
              <td class="text-right" style="font-variant-numeric: tabular-nums">{{ r.judgments }}</td>
              <td>
                <VChip v-if="r.insolvency" size="x-small" color="error" variant="tonal">{{ t('supplier_management.risk.insolvency') }}</VChip>
                <VChip v-if="r.business_rescue" size="x-small" color="error" variant="tonal">{{ t('supplier_management.risk.business_rescue') }}</VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCardText>

    <!-- Tier dialog -->
    <VDialog v-model="tierDialog" max-width="560" scrollable>
      <VCard>
        <VCardTitle>{{ t('supplier_management.risk.set_tier') }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.risk.tier_help') }}
          </VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="tierForm.annual_spend" type="number" min="0" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.annual_spend')" />
            <VTextField v-model.number="tierForm.contract_value" type="number" min="0" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.contract_value')" />
          </div>
          <VCheckbox v-for="f in risk.tier.available" :key="f.key" v-model="(tierForm as any)[f.key]"
                     density="compact" hide-details :label="f.label" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="tierDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="tierForm.processing" @click="saveTier">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Adjudicate -->
    <VDialog :model-value="!!adjudicating" max-width="520" @update:model-value="v => { if (!v) adjudicating = null }">
      <VCard v-if="adjudicating">
        <VCardTitle>{{ t('supplier_management.risk.adjudicate_title') }}</VCardTitle>
        <VCardText>
          <div class="mb-3">
            <div class="font-weight-medium">{{ adjudicating.subject }} → {{ adjudicating.matched_name }}</div>
            <div class="text-caption text-medium-emphasis">{{ adjudicating.list_name }} · {{ adjudicating.match_score }}%</div>
          </div>
          <VSelect v-model="adjudicateForm.status" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.risk.decision')"
                   :items="[
                     { title: t('supplier_management.risk.status.confirmed'), value: 'confirmed' },
                     { title: t('supplier_management.risk.status.false_positive'), value: 'false_positive' },
                     { title: t('supplier_management.risk.status.dismissed'), value: 'dismissed' },
                   ]" />
          <VTextarea v-model="adjudicateForm.note" rows="3" density="comfortable" variant="outlined"
                     :label="t('supplier_management.risk.reason')"
                     :hint="t('supplier_management.risk.reason_help')" persistent-hint
                     :error-messages="adjudicateForm.errors.note" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="adjudicating = null">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="adjudicateForm.processing" @click="saveAdjudication">
            {{ t('supplier_management.risk.record_decision') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Record a hit -->
    <VDialog v-model="hitDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('supplier_management.risk.record_hit') }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.risk.record_hit_help') }}
          </VAlert>
          <VSelect v-model="hitForm.list_type" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.risk.list_type')"
                   :items="[
                     { title: t('supplier_management.risk.sanctions'), value: 'sanctions' },
                     { title: t('supplier_management.risk.pep'), value: 'pep' },
                     { title: t('supplier_management.risk.adverse'), value: 'adverse_media' },
                   ]" />
          <VSelect v-if="people.length" v-model="hitForm.person_id" clearable density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.risk.about_person')"
                   :items="people.map(p => ({ title: `${p.name} (${p.role})`, value: p.id }))" />
          <VTextField v-model="hitForm.matched_name" density="comfortable" variant="outlined" class="mb-3"
                      :label="t('supplier_management.risk.matched_name')" :error-messages="hitForm.errors.matched_name" />
          <VTextField v-model="hitForm.list_name" density="comfortable" variant="outlined" class="mb-3"
                      :label="t('supplier_management.risk.list_name')" placeholder="FIC Targeted Financial Sanctions" />
          <VTextarea v-model="hitForm.note" rows="2" density="comfortable" variant="outlined" :label="t('supplier_management.risk.note')" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="hitDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="hitForm.processing" @click="saveHit">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Record a credit report -->
    <VDialog v-model="creditDialog" max-width="560" scrollable>
      <VCard>
        <VCardTitle>{{ t('supplier_management.risk.record_credit') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="creditForm.score" type="number" min="0" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.bureau_score')" />
            <VTextField v-model.number="creditForm.score_max" type="number" min="1" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.score_out_of')" />
          </div>
          <VSelect v-model="creditForm.payment_behaviour" clearable density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.risk.behaviour')"
                   :items="[
                     { title: t('supplier_management.risk.on_time'), value: 'on_time' },
                     { title: t('supplier_management.risk.slow'), value: 'slow' },
                     { title: t('supplier_management.risk.delinquent'), value: 'delinquent' },
                   ]" />
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="creditForm.defaults_count" type="number" min="0" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.defaults')" />
            <VTextField v-model.number="creditForm.judgments_count" type="number" min="0" density="comfortable" variant="outlined"
                        :label="t('supplier_management.risk.judgments')" />
          </div>
          <VCheckbox v-model="creditForm.insolvency" density="compact" hide-details :label="t('supplier_management.risk.insolvency')" />
          <VCheckbox v-model="creditForm.business_rescue" density="compact" hide-details :label="t('supplier_management.risk.business_rescue')" class="mb-3" />
          <VTextField v-model="creditForm.report_date" type="date" density="comfortable" variant="outlined"
                      :label="t('supplier_management.risk.report_date')" :error-messages="creditForm.errors.report_date" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="creditDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="creditForm.processing" @click="saveCredit">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
