<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Basis { factor: string; effect: string; detail: string }
interface Finding {
  id: number; channel: string; platform: string | null; source: string | null; url: string | null
  title: string | null; excerpt: string | null; published_at: string | null
  category: string | null; category_label: string; severity: string | null; sentiment: string | null
  is_positive: boolean; ai_generated: boolean; analysis_confidence: number | null
  analysis_note: string | null; entity_confidence: number; entity_basis: Basis[]
  corroborations: number; status: string; note: string | null
}

const props = defineProps<{
  /** The supplier's uuid — suppliers are addressed by it, never by the row id. */
  supplierUuid: string
  reputation: {
    score: {
      score: number; band: string; findings: number; negative: number; positive: number
      unreviewed: number; coverage: boolean
      drivers: { id: number; title: string; category: string; severity: string; published_at: string | null; contribution: number }[]
    }
    findings: Finding[]
    handles: { id: number; kind: string; platform: string | null; value: string; source: string }[]
    runs: { id: number; scope: string; trigger: string; at: string; sources: number; unavailable: number; new: number; updated: number; needs_review: number; movement: number | null }[]
    sources_available: boolean
  }
}>()

const bandMeta: Record<string, { colour: string; icon: string }> = {
  excellent: { colour: 'success', icon: 'tabler-mood-check' },
  low: { colour: 'success', icon: 'tabler-mood-check' },
  moderate: { colour: 'warning', icon: 'tabler-mood-neutral' },
  high: { colour: 'error', icon: 'tabler-mood-sad' },
  critical: { colour: 'error', icon: 'tabler-alert-octagon' },
  unknown: { colour: 'default', icon: 'tabler-help-circle' },
}

const severityColour = (severity: string | null, positive: boolean) =>
  positive ? 'success' : ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' }[severity ?? ''] ?? 'default')

// The timeline shows everything; the filter is for working a queue rather than reading a history.
const filter = ref<'all' | 'unreviewed' | 'negative' | 'review'>('all')
const visible = computed(() => props.reputation.findings.filter((f) => {
  if (filter.value === 'unreviewed') return f.status === 'open' || f.status === 'needs_review'
  if (filter.value === 'negative') return !f.is_positive && f.category !== 'neutral'
  if (filter.value === 'review') return f.status === 'needs_review'
  return true
}))

const needsReview = computed(() => props.reputation.findings.filter(f => f.status === 'needs_review').length)

// ── Run a pass ───────────────────────────────────────────────────────────────
const runForm = useForm<any>({ scope: 'full' })
function runNow(scope: string) {
  runForm.scope = scope
  runForm.post(route('suppliers.reputation.run', props.supplierUuid), { preserveScroll: true })
}

// ── Adjudicate ───────────────────────────────────────────────────────────────
const active = ref<Finding | null>(null)
const adjudicateForm = useForm<any>({ status: 'confirmed', note: '', category: null, severity: null })
function open(finding: Finding) {
  active.value = finding
  adjudicateForm.clearErrors()
  adjudicateForm.status = 'confirmed'
  adjudicateForm.note = ''
  adjudicateForm.category = finding.category
  adjudicateForm.severity = finding.severity
}
function save() {
  if (!active.value) return
  adjudicateForm.patch(route('suppliers.reputation.findings.adjudicate', [props.supplierUuid, active.value.id]), {
    preserveScroll: true,
    onSuccess: () => { active.value = null },
  })
}

// ── What is watched ──────────────────────────────────────────────────────────
const handleDialog = ref(false)
const handleForm = useForm<any>({ kind: 'brand', platform: null, value: '' })
function saveHandle() {
  handleForm.post(route('suppliers.reputation.handles.store', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { handleDialog.value = false; handleForm.reset() },
  })
}
function removeHandle(id: number) {
  useForm({}).delete(route('suppliers.reputation.handles.destroy', [props.supplierUuid, id]), { preserveScroll: true })
}

const categories = [
  'fraud', 'corruption', 'bribery', 'criminal', 'sanctions', 'regulatory', 'insolvency',
  'financial_distress', 'litigation', 'labour', 'safety', 'environmental', 'cyber',
  'data_breach', 'customer_complaints', 'product_failure', 'reputation', 'positive', 'neutral',
]
</script>

<template>
  <VCard border>
    <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
      <span class="text-subtitle-1">{{ t('supplier_management.reputation.title') }}</span>
      <div class="d-flex gap-2">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-radar" :loading="runForm.processing" @click="runNow('full')">
          {{ t('supplier_management.reputation.run_now') }}
        </VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-eye-search" @click="handleDialog = true">
          {{ t('supplier_management.reputation.watch') }}
        </VBtn>
      </div>
    </VCardTitle>

    <VCardText>
      <VAlert v-if="!reputation.sources_available" type="info" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-plug-off">
        {{ t('supplier_management.reputation.no_source_notice') }}
      </VAlert>

      <!-- Coverage before score: no findings is not a clean record -->
      <div v-if="!reputation.score.coverage" class="text-caption text-medium-emphasis mb-4">
        {{ t('supplier_management.reputation.no_coverage') }}
      </div>

      <div v-else class="d-flex align-center flex-wrap gap-4 mb-4">
        <div class="d-flex align-center gap-2">
          <span class="text-h4" style="font-variant-numeric: tabular-nums">{{ reputation.score.score.toFixed(0) }}</span>
          <VChip size="small" variant="tonal" :color="bandMeta[reputation.score.band]?.colour" :prepend-icon="bandMeta[reputation.score.band]?.icon">
            {{ t(`supplier_management.reputation.bands.${reputation.score.band}`) }}
          </VChip>
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ t('supplier_management.reputation.counts', {
            total: reputation.score.findings,
            negative: reputation.score.negative,
            positive: reputation.score.positive,
          }) }}
        </div>
        <VChip v-if="reputation.score.unreviewed" size="x-small" color="warning" variant="tonal">
          {{ t('supplier_management.reputation.unreviewed', { n: reputation.score.unreviewed }) }}
        </VChip>
      </div>

      <!-- What is driving the number, so it can be argued with -->
      <div v-if="reputation.score.drivers.length" class="mb-4">
        <div class="text-caption text-medium-emphasis mb-1">{{ t('supplier_management.reputation.driving') }}</div>
        <div v-for="d in reputation.score.drivers" :key="d.id" class="text-caption d-flex gap-2 align-center">
          <VChip size="x-small" variant="tonal" :color="severityColour(d.severity, false)">{{ d.category }}</VChip>
          <span class="text-truncate" style="max-width: 46ch">{{ d.title }}</span>
          <span class="text-medium-emphasis" style="font-variant-numeric: tabular-nums">+{{ d.contribution }}</span>
        </div>
      </div>

      <VAlert v-if="needsReview" type="warning" variant="tonal" density="compact" class="mb-3" prepend-icon="tabler-help-octagon">
        {{ t('supplier_management.reputation.needs_review_notice', { n: needsReview }) }}
      </VAlert>

      <!-- Timeline -->
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
        <div class="text-subtitle-2">{{ t('supplier_management.reputation.timeline') }}</div>
        <VBtnToggle v-model="filter" mandatory density="compact" variant="outlined" divided>
          <VBtn value="all" size="small">{{ t('supplier_management.reputation.filters.all') }}</VBtn>
          <VBtn value="unreviewed" size="small">{{ t('supplier_management.reputation.filters.unreviewed') }}</VBtn>
          <VBtn value="negative" size="small">{{ t('supplier_management.reputation.filters.negative') }}</VBtn>
          <VBtn value="review" size="small">{{ t('supplier_management.reputation.filters.review') }}</VBtn>
        </VBtnToggle>
      </div>

      <div v-if="!visible.length" class="text-caption text-medium-emphasis">
        {{ t('supplier_management.reputation.nothing_here') }}
      </div>

      <VTimeline v-else side="end" density="compact" truncate-line="both" class="mt-2">
        <VTimelineItem
          v-for="f in visible" :key="f.id" size="x-small"
          :dot-color="severityColour(f.severity, f.is_positive)"
        >
          <div class="d-flex flex-wrap align-center gap-2">
            <span class="font-weight-medium">{{ f.title || t('supplier_management.reputation.untitled') }}</span>
            <VChip size="x-small" variant="tonal" :color="severityColour(f.severity, f.is_positive)">
              {{ f.category_label }}
            </VChip>
            <VChip v-if="f.status !== 'open'" size="x-small" variant="tonal"
                   :color="f.status === 'confirmed' ? 'error' : (f.status === 'needs_review' ? 'warning' : 'default')">
              {{ t(`supplier_management.reputation.status.${f.status}`) }}
            </VChip>
            <span class="text-caption text-medium-emphasis">{{ f.published_at }}</span>
          </div>

          <div v-if="f.excerpt" class="text-caption mt-1" style="max-width: 70ch">{{ f.excerpt }}</div>

          <div class="text-caption text-medium-emphasis mt-1">
            {{ f.source || f.platform || f.channel }}
            <span v-if="f.corroborations"> · {{ t('supplier_management.reputation.corroborated', { n: f.corroborations }) }}</span>
            <a v-if="f.url" :href="f.url" target="_blank" rel="noopener noreferrer" class="ms-2">
              {{ t('supplier_management.reputation.open_source') }}
            </a>
          </div>

          <!-- Why it was attributed here at all — the reader can check the reasoning -->
          <div class="text-caption text-medium-emphasis mt-1">
            <VIcon :icon="f.entity_confidence >= 85 ? 'tabler-target-arrow' : 'tabler-target'" size="12" />
            {{ t('supplier_management.reputation.attributed', { n: f.entity_confidence }) }}
            <span v-for="(b, i) in f.entity_basis.slice(0, 2)" :key="i"> · {{ b.detail }}</span>
          </div>

          <!-- Provenance of the reading, said plainly -->
          <div v-if="f.analysis_note" class="text-caption text-medium-emphasis">
            <VIcon :icon="f.ai_generated ? 'tabler-robot' : 'tabler-user-check'" size="12" />
            {{ f.ai_generated
              ? t('supplier_management.reputation.ai_read', { n: f.analysis_confidence ?? 0 })
              : t('supplier_management.reputation.human_read') }}
            — {{ f.analysis_note }}
          </div>

          <div v-if="f.note" class="text-caption mt-1">“{{ f.note }}”</div>

          <VBtn v-if="f.status === 'open' || f.status === 'needs_review'" size="x-small" variant="tonal" class="mt-2" @click="open(f)">
            {{ t('supplier_management.reputation.decide') }}
          </VBtn>
        </VTimelineItem>
      </VTimeline>

      <!-- What is watched -->
      <VDivider class="my-4" />
      <div class="text-subtitle-2 mb-1">{{ t('supplier_management.reputation.watching') }}</div>
      <div class="text-caption text-medium-emphasis mb-2" style="max-width: 68ch">
        {{ t('supplier_management.reputation.watching_help') }}
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VChip v-for="h in reputation.handles" :key="h.id" size="small" variant="tonal" closable @click:close="removeHandle(h.id)">
          <VIcon :icon="h.kind === 'handle' ? 'tabler-at' : (h.kind === 'website' ? 'tabler-world' : 'tabler-tag')" size="14" start />
          {{ h.value }}<span v-if="h.platform" class="text-medium-emphasis"> · {{ h.platform }}</span>
        </VChip>
        <span v-if="!reputation.handles.length" class="text-caption text-medium-emphasis">
          {{ t('supplier_management.reputation.nothing_watched') }}
        </span>
      </div>

      <!-- The passes themselves, including the empty ones -->
      <template v-if="reputation.runs.length">
        <VDivider class="my-4" />
        <VExpansionPanels variant="accordion">
          <VExpansionPanel>
            <VExpansionPanelTitle>{{ t('supplier_management.reputation.runs', { n: reputation.runs.length }) }}</VExpansionPanelTitle>
            <VExpansionPanelText>
              <div style="overflow-x: auto">
                <VTable density="compact">
                  <thead>
                    <tr>
                      <th>{{ t('supplier_management.reputation.when') }}</th>
                      <th>{{ t('supplier_management.reputation.scope') }}</th>
                      <th class="text-right">{{ t('supplier_management.reputation.sources') }}</th>
                      <th class="text-right">{{ t('supplier_management.reputation.found') }}</th>
                      <th class="text-right">{{ t('supplier_management.reputation.movement') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in reputation.runs" :key="r.id">
                      <td class="text-caption">{{ r.at }}<div class="text-medium-emphasis">{{ r.trigger }}</div></td>
                      <td class="text-caption text-capitalize">{{ r.scope }}</td>
                      <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">
                        {{ r.sources }}<span v-if="r.unavailable" class="text-warning"> (+{{ r.unavailable }} {{ t('supplier_management.reputation.unavailable') }})</span>
                      </td>
                      <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">
                        {{ r.new }} / {{ r.updated }}<span v-if="r.needs_review"> / {{ r.needs_review }}</span>
                      </td>
                      <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">
                        <span v-if="r.movement" :class="r.movement > 0 ? 'text-error' : 'text-success'">
                          {{ r.movement > 0 ? '+' : '' }}{{ r.movement }}
                        </span>
                        <span v-else class="text-medium-emphasis">—</span>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
      </template>
    </VCardText>

    <!-- Decide on a finding -->
    <VDialog :model-value="!!active" max-width="620" scrollable @update:model-value="v => { if (!v) active = null }">
      <VCard v-if="active">
        <VCardTitle>{{ t('supplier_management.reputation.decide_title') }}</VCardTitle>
        <VCardText>
          <div class="mb-3">
            <div class="font-weight-medium">{{ active.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ active.source }} · {{ active.published_at }}</div>
          </div>

          <VAlert v-if="active.status === 'needs_review'" type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.reputation.attribution_warning', { n: active.entity_confidence }) }}
          </VAlert>

          <VSelect v-model="adjudicateForm.status" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.reputation.decision')"
                   :items="[
                     { title: t('supplier_management.reputation.status.confirmed'), value: 'confirmed' },
                     { title: t('supplier_management.reputation.status.false_positive'), value: 'false_positive' },
                     { title: t('supplier_management.reputation.status.dismissed'), value: 'dismissed' },
                   ]" />

          <!-- A person may correct the machine's reading as well as its verdict -->
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="adjudicateForm.category" clearable density="comfortable" variant="outlined"
                     :label="t('supplier_management.reputation.category')" :items="categories" />
            <VSelect v-model="adjudicateForm.severity" clearable density="comfortable" variant="outlined"
                     :label="t('supplier_management.reputation.severity')"
                     :items="['none', 'low', 'medium', 'high', 'critical']" />
          </div>

          <VTextarea v-model="adjudicateForm.note" rows="3" density="comfortable" variant="outlined"
                     :label="t('supplier_management.reputation.reason')"
                     :hint="t('supplier_management.reputation.reason_help')" persistent-hint
                     :error-messages="adjudicateForm.errors.note" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="active = null">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="adjudicateForm.processing" @click="save">
            {{ t('supplier_management.reputation.record_decision') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add something to watch -->
    <VDialog v-model="handleDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('supplier_management.reputation.watch') }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.reputation.watch_help') }}
          </VAlert>
          <VSelect v-model="handleForm.kind" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.reputation.kind')"
                   :items="[
                     { title: t('supplier_management.reputation.kinds.brand'), value: 'brand' },
                     { title: t('supplier_management.reputation.kinds.website'), value: 'website' },
                     { title: t('supplier_management.reputation.kinds.handle'), value: 'handle' },
                   ]" />
          <VSelect v-if="handleForm.kind === 'handle'" v-model="handleForm.platform" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.reputation.platform')"
                   :items="['linkedin', 'x', 'facebook', 'instagram', 'youtube', 'tiktok', 'reddit']" />
          <VTextField v-model="handleForm.value" density="comfortable" variant="outlined"
                      :label="t('supplier_management.reputation.value')" :error-messages="handleForm.errors.value" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="handleDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="handleForm.processing" @click="saveHandle">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
