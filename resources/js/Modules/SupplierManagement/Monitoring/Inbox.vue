<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  changes: any[]
  findings: any[]
  hits: any[]
  summary: { changes: number; changes_serious: number; findings: number; hits: number; oldest_days: number | null }
  due: any[]
  cases: any[]
  filters: { severity: string | null }
}>()

const severityColour = (s: string | null) =>
  ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' }[s ?? ''] ?? 'default')

const tab = ref<'changes' | 'findings' | 'hits' | 'due' | 'cases'>('changes')
const severity = ref<string | null>(props.filters.severity)

function applyFilter(value: string | null) {
  severity.value = value
  router.get(route('suppliers.monitoring.index'), value ? { severity: value } : {}, {
    preserveState: true, preserveScroll: true, replace: true,
  })
}

// ── Decide on a change ───────────────────────────────────────────────────────
const active = ref<any | null>(null)
const reviewForm = useForm<any>({ status: 'acknowledged', note: '' })
function open(change: any) {
  active.value = change
  reviewForm.clearErrors()
  reviewForm.status = 'acknowledged'
  reviewForm.note = ''
}
function save() {
  if (!active.value) return
  reviewForm.patch(route('suppliers.monitoring.changes.review', active.value.id), {
    preserveScroll: true,
    onSuccess: () => { active.value = null },
  })
}

// A supplier is addressed by uuid; this button was still handing over the row id and 404'd.
function sweep(supplierUuid: string) {
  useForm({}).post(route('suppliers.monitoring.sweep', supplierUuid), { preserveScroll: true })
}

const totalOutstanding = computed(() => props.summary.changes + props.summary.findings + props.summary.hits)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('supplier_management.monitoring.eyebrow') }}</div>
        <h2 class="text-h5 mb-0">{{ t('supplier_management.monitoring.title') }}</h2>
        <div class="text-caption text-medium-emphasis" style="max-width: 70ch">
          {{ t('supplier_management.monitoring.subtitle') }}
        </div>
      </div>
      <VBtn variant="tonal" size="small" prepend-icon="tabler-report"
            @click="router.visit(route('suppliers.monitoring.report'))">
        {{ t('supplier_management.monitoring.monthly_report') }}
      </VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.outstanding') }}</div>
          <div class="text-h6">{{ totalOutstanding }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.serious') }}</div>
          <div class="text-h6" :class="summary.changes_serious ? 'text-error' : ''">{{ summary.changes_serious }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <!-- The failure mode of a queue is the item that quietly ages at the bottom -->
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.oldest') }}</div>
          <div class="text-h6" :class="(summary.oldest_days ?? 0) > 14 ? 'text-warning' : ''">
            {{ summary.oldest_days !== null ? t('supplier_management.monitoring.days', { n: summary.oldest_days }) : '—' }}
          </div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.due_now') }}</div>
          <div class="text-h6">{{ due.length }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VTabs v-model="tab" density="compact">
        <VTab value="changes">{{ t('supplier_management.monitoring.tabs.changes') }} ({{ summary.changes }})</VTab>
        <VTab value="findings">{{ t('supplier_management.monitoring.tabs.findings') }} ({{ summary.findings }})</VTab>
        <VTab value="hits">{{ t('supplier_management.monitoring.tabs.hits') }} ({{ summary.hits }})</VTab>
        <VTab value="due">{{ t('supplier_management.monitoring.tabs.due') }} ({{ due.length }})</VTab>
        <VTab value="cases">{{ t('supplier_management.monitoring.tabs.cases') }} ({{ cases.length }})</VTab>
      </VTabs>

      <VCardText>
        <!-- A chip group rather than loose chips: a bare VChip renders a span, so a keyboard user
             cannot reach the filter at all. The group gives it roving focus and Enter/Space. -->
        <VChipGroup
          :model-value="severity" filter mandatory="force" class="mb-3"
          :aria-label="t('supplier_management.monitoring.filter_by_severity')"
          @update:model-value="applyFilter"
        >
          <VChip :value="null" size="small">{{ t('supplier_management.monitoring.all') }}</VChip>
          <VChip v-for="s in ['critical', 'high', 'medium', 'low']" :key="s" :value="s" size="small"
                 :color="severity === s ? severityColour(s) : undefined">
            {{ t(`supplier_management.monitoring.severities.${s}`) }}
          </VChip>
        </VChipGroup>

        <VWindow v-model="tab">
          <!-- Changes -->
          <VWindowItem value="changes">
            <div v-if="!changes.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.nothing_outstanding') }}
            </div>
            <div v-else style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('supplier_management.monitoring.supplier') }}</th>
                    <th>{{ t('supplier_management.monitoring.change') }}</th>
                    <th>{{ t('supplier_management.monitoring.detected') }}</th>
                    <th class="text-right"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in changes" :key="c.id">
                    <td>
                      <a :href="`/suppliers/${c.supplier_id}/passport`" class="font-weight-medium">{{ c.supplier }}</a>
                      <div v-if="c.risk_band" class="text-caption text-medium-emphasis">{{ c.risk_band }}</div>
                    </td>
                    <td style="min-width: 280px">
                      <div class="d-flex align-center gap-2">
                        <VChip size="x-small" variant="tonal" :color="severityColour(c.severity)">{{ c.category }}</VChip>
                        <VChip v-if="c.detected_by === 'edit'" size="x-small" variant="tonal">
                          {{ t('supplier_management.monitoring.by_edit') }}
                        </VChip>
                      </div>
                      <div class="text-caption mt-1" style="max-width: 60ch">{{ c.summary }}</div>
                      <div v-if="c.risk_movement" class="text-caption" :class="c.risk_movement > 0 ? 'text-error' : 'text-success'">
                        {{ t('supplier_management.monitoring.risk_moved', { n: c.risk_movement > 0 ? `+${c.risk_movement}` : c.risk_movement }) }}
                      </div>
                    </td>
                    <td class="text-caption text-medium-emphasis" style="white-space: nowrap">
                      {{ t('supplier_management.monitoring.days_ago', { n: c.age_days }) }}
                    </td>
                    <td class="text-right">
                      <VBtn size="x-small" variant="tonal" @click="open(c)">
                        {{ t('supplier_management.monitoring.decide') }}
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VWindowItem>

          <!-- Findings -->
          <VWindowItem value="findings">
            <div v-if="!findings.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.nothing_outstanding') }}
            </div>
            <div v-else style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('supplier_management.monitoring.supplier') }}</th>
                    <th>{{ t('supplier_management.monitoring.finding') }}</th>
                    <th class="text-right">{{ t('supplier_management.monitoring.confidence') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="f in findings" :key="f.id">
                    <td><a :href="`/suppliers/${f.supplier_id}`" class="font-weight-medium">{{ f.supplier }}</a></td>
                    <td style="min-width: 300px">
                      <div class="d-flex align-center gap-2">
                        <VChip size="x-small" variant="tonal" :color="severityColour(f.severity)">{{ f.category }}</VChip>
                        <span v-if="f.status === 'needs_review'" class="text-caption text-warning">
                          {{ t('supplier_management.monitoring.not_attributed') }}
                        </span>
                      </div>
                      <div class="text-caption mt-1" style="max-width: 60ch">{{ f.title }}</div>
                      <div class="text-caption text-medium-emphasis">{{ f.source }} · {{ f.published_at }}</div>
                    </td>
                    <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">{{ f.entity_confidence }}%</td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VWindowItem>

          <!-- Screening -->
          <VWindowItem value="hits">
            <div v-if="!hits.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.nothing_outstanding') }}
            </div>
            <div v-else style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('supplier_management.monitoring.supplier') }}</th>
                    <th>{{ t('supplier_management.monitoring.matched') }}</th>
                    <th class="text-right">{{ t('supplier_management.monitoring.confidence') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="h in hits" :key="h.id">
                    <td><a :href="`/suppliers/${h.supplier_id}`" class="font-weight-medium">{{ h.supplier }}</a></td>
                    <td>
                      <VChip size="x-small" variant="tonal" :color="h.list_type === 'sanctions' ? 'error' : 'warning'">
                        {{ h.list_type }}
                      </VChip>
                      <div class="text-caption mt-1">{{ h.subject }} → {{ h.matched_name }}</div>
                      <div class="text-caption text-medium-emphasis">{{ h.list_name }}</div>
                    </td>
                    <td class="text-right text-caption" style="font-variant-numeric: tabular-nums">{{ h.match_score }}</td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VWindowItem>

          <!-- Due a sweep: the work nobody has been alerted to yet -->
          <!--
            The case queue. A case used to be reachable only from the passport of the supplier it
            belonged to, so it could be found only by somebody who already knew to look there — and
            reaching somebody who does not is the entire point of opening one.
          -->
          <VWindowItem value="cases">
            <div class="text-caption text-medium-emphasis mb-2" style="max-width: 70ch">
              {{ t('supplier_management.monitoring.cases_help') }}
            </div>
            <div v-if="!cases.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.no_cases') }}
            </div>
            <div v-else style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('supplier_management.monitoring.supplier') }}</th>
                    <th>{{ t('supplier_management.monitoring.case_reference') }}</th>
                    <th>{{ t('supplier_management.monitoring.case_state') }}</th>
                    <th>{{ t('supplier_management.monitoring.case_opened') }}</th>
                    <th>{{ t('supplier_management.monitoring.case_due') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="c in cases" :key="c.id">
                    <td>
                      <a v-if="c.supplier" :href="`/suppliers/${c.supplier.uuid}/passport`" class="font-weight-medium">{{ c.supplier.name }}</a>
                      <span v-else class="text-medium-emphasis">—</span>
                      <div v-if="c.risk_band" class="text-caption text-medium-emphasis">{{ c.risk_band }}</div>
                    </td>
                    <td class="text-caption">{{ c.reference }}</td>
                    <td><VChip size="x-small" variant="tonal">{{ c.state }}</VChip></td>
                    <td class="text-caption text-medium-emphasis">{{ c.opened_at }}</td>
                    <td class="text-caption" :class="c.overdue ? 'text-error' : 'text-medium-emphasis'">
                      {{ c.due_at ?? '—' }}
                      <VChip v-if="c.overdue" size="x-small" color="error" variant="tonal" class="ms-1">
                        {{ t('supplier_management.monitoring.overdue') }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VWindowItem>

          <VWindowItem value="due">
            <div class="text-caption text-medium-emphasis mb-2" style="max-width: 70ch">
              {{ t('supplier_management.monitoring.due_help') }}
            </div>
            <div v-if="!due.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.nothing_due') }}
            </div>
            <div v-else style="overflow-x: auto">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('supplier_management.monitoring.supplier') }}</th>
                    <th>{{ t('supplier_management.monitoring.last_swept') }}</th>
                    <th class="text-right">{{ t('supplier_management.monitoring.checks_due') }}</th>
                    <th class="text-right"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in due" :key="s.id">
                    <td>
                      <a :href="`/suppliers/${s.uuid}/passport`" class="font-weight-medium">{{ s.name }}</a>
                      <div v-if="s.risk_band" class="text-caption text-medium-emphasis">{{ s.risk_band }}</div>
                    </td>
                    <td class="text-caption" :class="s.never ? 'text-warning' : 'text-medium-emphasis'">
                      {{ s.never ? t('supplier_management.monitoring.never_swept') : s.last_monitored_at }}
                    </td>
                    <td class="text-right" style="font-variant-numeric: tabular-nums">{{ s.due_checks }}</td>
                    <td class="text-right">
                      <VBtn size="x-small" variant="tonal" @click="sweep(s.uuid)">
                        {{ t('supplier_management.monitoring.sweep_now') }}
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>

    <!-- Decide on a change -->
    <VDialog :model-value="!!active" max-width="560" @update:model-value="v => { if (!v) active = null }">
      <VCard v-if="active">
        <VCardTitle>{{ t('supplier_management.monitoring.decide_title') }}</VCardTitle>
        <VCardText>
          <div class="mb-3">
            <div class="font-weight-medium">{{ active.supplier }}</div>
            <div class="text-caption">{{ active.summary }}</div>
          </div>
          <div v-if="active.previous || active.current" class="mb-3 text-caption">
            <div><span class="text-medium-emphasis">{{ t('supplier_management.monitoring.was') }}:</span> {{ active.previous || '—' }}</div>
            <div><span class="text-medium-emphasis">{{ t('supplier_management.monitoring.now') }}:</span> {{ active.current || '—' }}</div>
          </div>

          <VSelect v-model="reviewForm.status" density="comfortable" variant="outlined" class="mb-3"
                   :label="t('supplier_management.monitoring.decision')"
                   :items="[
                     { title: t('supplier_management.monitoring.statuses.acknowledged'), value: 'acknowledged' },
                     { title: t('supplier_management.monitoring.statuses.actioned'), value: 'actioned' },
                     { title: t('supplier_management.monitoring.statuses.dismissed'), value: 'dismissed' },
                   ]" />
          <VTextarea v-model="reviewForm.note" rows="3" density="comfortable" variant="outlined"
                     :label="t('supplier_management.monitoring.note')"
                     :hint="t('supplier_management.monitoring.note_help')" persistent-hint
                     :error-messages="reviewForm.errors.note" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="active = null">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="reviewForm.processing" @click="save">
            {{ t('supplier_management.monitoring.record_decision') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
