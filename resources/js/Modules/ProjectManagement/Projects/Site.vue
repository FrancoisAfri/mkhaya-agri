<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

/**
 * The site: what happened, what was measured, what is wrong with it, and what is owed.
 *
 * Four tabs rather than four pages, because on a construction project these are one job. The diary
 * records the day, the bill records what was measured, the snag list records what has to be put
 * right, and the claim turns the second into money — less what the third is holding back.
 */
const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

const props = defineProps<{
  project: { id: string; code: string; name: string; currency: string }
  diaries: Array<Record<string, any>>
  snags: Array<Record<string, any>>
  snagStats: { open: number; critical: number; ncr: number; overdue: number }
  boq: Array<Record<string, any>>
  boqSummary: { total: number; measured: number; remaining: number; percent: number; provisional: number } | null
  claims: Array<Record<string, any>>
  retention: { held: number; released: number; outstanding: number; currency: string | null } | null
  tasks: Array<{ id: string; label: string }>
  milestones: Array<{ id: string; label: string; payment: number | null; claimed: boolean }>
  members: Array<{ id: string; label: string }>
  weather: string[]
  units: string[]
  snagKinds: string[]
  severities: string[]
  can: { record: boolean; costs: boolean; certify: boolean }
}>()

const tab = ref('diary')
const confirmer = ref()

const money = (v: number | null) => (v === null
  ? '—'
  : `${props.project.currency} ${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`)

const today = new Date().toISOString().slice(0, 10)

// ── Diary ────────────────────────────────────────────────────────────────────

const diaryDialog = ref(false)
const diaryForm = useForm({
  entry_date: today, weather: 'clear', temperature_c: null as number | null,
  rain_hours: null as number | null, workers_on_site: null as number | null,
  work_done: '', plant_on_site: '', deliveries: '', visitors: '',
  delays: '', instructions: '', safety_notes: '',
})

function openDiary(existing?: Record<string, any>) {
  diaryForm.reset()
  if (existing) {
    diaryForm.entry_date = existing.date
    diaryForm.weather = existing.weather ?? 'clear'
    diaryForm.rain_hours = existing.rain_hours
    diaryForm.workers_on_site = existing.workers
    diaryForm.work_done = existing.work_done ?? ''
    diaryForm.delays = existing.delays ?? ''
  }
  diaryDialog.value = true
}

function saveDiary() {
  diaryForm.post(route('project-management.site.diary.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => { diaryDialog.value = false; diaryForm.reset() },
  })
}

// ── Bill of quantities ───────────────────────────────────────────────────────

const boqDialog = ref(false)
const boqForm = useForm({
  item_no: '', section: '', description: '', unit: 'm3',
  quantity: null as number | null, rate: null as number | null,
  task_id: '', is_provisional: false,
})

function saveBoq() {
  boqForm.transform(d => ({ ...d, task_id: d.task_id || null }))
    .post(route('project-management.site.boq.store', props.project.id), {
      preserveScroll: true,
      onSuccess: () => { boqDialog.value = false; boqForm.reset() },
    })
}

const measureDialog = ref(false)
const measuring = ref<Record<string, any> | null>(null)
const measureForm = useForm({ measured_quantity: 0 })

function openMeasure(item: Record<string, any>) {
  measuring.value = item
  measureForm.reset()
  measureForm.measured_quantity = item.measured
  measureDialog.value = true
}

function saveMeasure() {
  measureForm.post(route('project-management.site.boq.measure', [props.project.id, measuring.value!.id]), {
    preserveScroll: true,
    onSuccess: () => { measureDialog.value = false },
  })
}

// ── Snags ────────────────────────────────────────────────────────────────────

const snagDialog = ref(false)
const snagForm = useForm({
  kind: 'snag', title: '', description: '', location: '',
  severity: 'minor', task_id: '', due_date: '', assigned_member_id: '',
})

function saveSnag() {
  snagForm.transform(d => ({
    ...d,
    task_id: d.task_id || null,
    due_date: d.due_date || null,
    assigned_member_id: d.assigned_member_id || null,
  })).post(route('project-management.site.snags.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => { snagDialog.value = false; snagForm.reset() },
  })
}

const closeDialog = ref(false)
const closing = ref<Record<string, any> | null>(null)
const closeForm = useForm({ closure_note: '' })

function openClose(snag: Record<string, any>) {
  closing.value = snag
  closeForm.reset()
  closeDialog.value = true
}

function saveClose() {
  closeForm.post(route('project-management.site.snags.close', [props.project.id, closing.value!.id]), {
    preserveScroll: true,
    onSuccess: () => { closeDialog.value = false },
  })
}

const severityColour: Record<string, string> = { critical: 'error', major: 'warning', minor: 'grey' }
const snagStatusColour: Record<string, string> = {
  open: 'error', in_progress: 'warning', ready: 'info', closed: 'success', rejected: 'grey',
}

// ── Claims ───────────────────────────────────────────────────────────────────

const claimDialog = ref(false)
const claimForm = useForm({
  period_start: new Date(new Date().setDate(1)).toISOString().slice(0, 10),
  period_end: today,
  work_to_date: null as number | null,
  materials_on_site: null as number | null,
  variations: null as number | null,
  retention_pct: 10,
  milestone_task_id: '',
  notes: '',
})

function saveClaim() {
  claimForm.transform(d => ({ ...d, milestone_task_id: d.milestone_task_id || null }))
    .post(route('project-management.site.claims.store', props.project.id), {
      preserveScroll: true,
      onSuccess: () => { claimDialog.value = false; claimForm.reset() },
    })
}

function submitClaim(claim: Record<string, any>) {
  router.post(route('project-management.site.claims.submit', [props.project.id, claim.id]), {}, { preserveScroll: true })
}

function certifyClaim(claim: Record<string, any>) {
  confirmer.value.open({
    title: t('project_management.site.confirm_certify_title'),
    message: t('project_management.site.confirm_certify_body', { number: claim.number, amount: money(claim.net_due) }),
    confirmText: t('project_management.site.certify'),
    confirmColor: 'success',
    icon: 'tabler-file-check',
  }, () => router.post(
    route('project-management.site.claims.certify', [props.project.id, claim.id]),
    {},
    { preserveScroll: true },
  ))
}

const claimStatusColour: Record<string, string> = {
  draft: 'grey', submitted: 'info', certified: 'success', paid: 'primary', rejected: 'error',
}

const disruptedDays = computed(() => props.diaries.filter(d => d.disrupted).length)
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <h4 class="text-h4 mb-1">{{ t('project_management.site.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('project_management.site.subtitle') }}</p>

    <VTabs v-model="tab" class="mb-4" show-arrows>
      <VTab value="diary" prepend-icon="tabler-notebook">
        {{ t('project_management.site.diary') }}
        <VChip v-if="disruptedDays" size="x-small" color="warning" class="ms-2" label>{{ disruptedDays }}</VChip>
      </VTab>
      <VTab value="snags" prepend-icon="tabler-alert-triangle">
        {{ t('project_management.site.snags') }}
        <VChip v-if="snagStats.open" size="x-small" color="error" class="ms-2" label>{{ snagStats.open }}</VChip>
      </VTab>
      <VTab v-if="can.costs" value="boq" prepend-icon="tabler-list-numbers">{{ t('project_management.site.boq') }}</VTab>
      <VTab v-if="can.costs" value="claims" prepend-icon="tabler-file-invoice">{{ t('project_management.site.claims') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- ── Diary ─────────────────────────────────────────────────────────── -->
      <VWindowItem value="diary">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.site.diary') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.site.diary_sub') }}</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.record" color="primary" size="small" prepend-icon="tabler-plus" @click="openDiary()">
                {{ t('project_management.site.record_day') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VList v-if="diaries.length" lines="two">
            <template v-for="(d, i) in diaries" :key="d.id">
              <VDivider v-if="i" />
              <VListItem>
                <template #prepend>
                  <VAvatar :color="d.disrupted ? 'warning' : 'primary'" variant="tonal" rounded size="40" class="me-3">
                    <VIcon :icon="d.disrupted ? 'tabler-cloud-storm' : 'tabler-sun'" />
                  </VAvatar>
                </template>
                <VListItemTitle class="d-flex align-center flex-wrap gap-2">
                  {{ d.date }}
                  <VChip v-if="d.weather" size="x-small" label>{{ t(`project_management.site.weather_${d.weather}`) }}</VChip>
                  <VChip v-if="d.workers" size="x-small" label>{{ t('project_management.site.workers', { count: d.workers }) }}</VChip>
                  <!-- Called out because these are the days an extension of time is argued from. -->
                  <VChip v-if="d.rain_hours" size="x-small" color="warning" label>
                    {{ t('project_management.site.rain_hours', { count: d.rain_hours }) }}
                  </VChip>
                </VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ d.work_done || '—' }}
                  <span v-if="d.delays" class="text-error"> · {{ d.delays }}</span>
                </VListItemSubtitle>
                <template #append>
                  <VBtn v-if="can.record" size="small" variant="text" icon="tabler-edit"
                        :title="t('project_management.actions.edit')" @click="openDiary(d)" />
                </template>
              </VListItem>
            </template>
          </VList>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            <p class="mb-2">{{ t('project_management.site.no_diary') }}</p>
            <p class="text-caption mb-4">{{ t('project_management.site.no_diary_hint') }}</p>
            <VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="openDiary()">
              {{ t('project_management.site.record_day') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Snags ─────────────────────────────────────────────────────────── -->
      <VWindowItem value="snags">
        <VRow class="mb-2">
          <VCol v-for="k in (['open', 'critical', 'ncr', 'overdue'] as const)" :key="k" cols="6" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t(`project_management.site.stat_${k}`) }}</div>
              <div class="text-h6" :class="snagStats[k] && k !== 'open' ? 'text-error' : ''">{{ snagStats[k] }}</div>
            </VCardText></VCard>
          </VCol>
        </VRow>

        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.site.snags') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.site.snags_sub') }}</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.record" color="primary" size="small" prepend-icon="tabler-plus" @click="snagDialog = true">
                {{ t('project_management.site.raise_snag') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VTable v-if="snags.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.site.col_reference') }}</th>
                <th>{{ t('project_management.site.col_what') }}</th>
                <th>{{ t('project_management.site.col_where') }}</th>
                <th>{{ t('project_management.site.col_severity') }}</th>
                <th>{{ t('project_management.site.col_assignee') }}</th>
                <th>{{ t('project_management.site.col_due') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in snags" :key="s.id">
                <td class="text-no-wrap">
                  <VChip size="x-small" label :color="s.kind === 'ncr' ? 'error' : undefined">{{ s.reference }}</VChip>
                </td>
                <td>
                  {{ s.title }}
                  <div v-if="s.closure" class="text-caption text-success">{{ s.closure }}</div>
                </td>
                <td class="text-caption">{{ s.location ?? '—' }}</td>
                <td>
                  <VChip size="x-small" label :color="severityColour[s.severity]">
                    {{ t(`project_management.site.severity_${s.severity}`) }}
                  </VChip>
                </td>
                <td class="text-caption">{{ s.assignee ?? '—' }}</td>
                <td class="text-caption" :class="s.overdue ? 'text-error' : ''">{{ s.due ?? '—' }}</td>
                <td class="text-right text-no-wrap">
                  <VChip size="x-small" label :color="snagStatusColour[s.status]" class="me-1">
                    {{ t(`project_management.site.snag_status_${s.status}`) }}
                  </VChip>
                  <VBtn
                    v-if="can.record && s.status !== 'closed' && s.status !== 'rejected'"
                    size="small" variant="text" icon="tabler-check" color="success"
                    :title="t('project_management.site.close_snag')" @click="openClose(s)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site.no_snags') }}
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Bill of quantities ────────────────────────────────────────────── -->
      <VWindowItem v-if="can.costs" value="boq">
        <VRow v-if="boqSummary" class="mb-2">
          <VCol cols="6" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.boq_total') }}</div>
              <div class="text-h6">{{ money(boqSummary.total) }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.boq_measured') }}</div>
              <div class="text-h6">{{ money(boqSummary.measured) }}</div>
              <div class="text-caption text-medium-emphasis">{{ boqSummary.percent }}%</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.boq_remaining') }}</div>
              <div class="text-h6">{{ money(boqSummary.remaining) }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.boq_provisional') }}</div>
              <div class="text-h6">{{ money(boqSummary.provisional) }}</div>
              <!-- Money in the bill that may never be spent, so a completion % that counts it reads high. -->
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.provisional_note') }}</div>
            </VCardText></VCard>
          </VCol>
        </VRow>

        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.site.boq') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.site.boq_sub') }}</VCardSubtitle>
            <template #append>
              <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="boqDialog = true">
                {{ t('project_management.site.add_item') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VTable v-if="boq.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.site.col_item') }}</th>
                <th>{{ t('project_management.site.col_description') }}</th>
                <th class="text-right">{{ t('project_management.site.col_quantity') }}</th>
                <th class="text-right">{{ t('project_management.site.col_rate') }}</th>
                <th class="text-right">{{ t('project_management.site.col_amount') }}</th>
                <th class="text-right">{{ t('project_management.site.col_measured') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in boq" :key="i.id">
                <td class="text-caption text-no-wrap">{{ i.item_no ?? '—' }}</td>
                <td>
                  {{ i.description }}
                  <VChip v-if="i.provisional" size="x-small" label class="ms-1">
                    {{ t('project_management.site.provisional') }}
                  </VChip>
                </td>
                <td class="text-right text-no-wrap">{{ i.quantity }} {{ i.unit }}</td>
                <td class="text-right">{{ money(i.rate) }}</td>
                <td class="text-right">{{ money(i.amount) }}</td>
                <td class="text-right">
                  {{ i.measured }} {{ i.unit }}
                  <div class="text-caption text-medium-emphasis">{{ i.percent }}% · {{ money(i.measured_value) }}</div>
                </td>
                <td class="text-right">
                  <VBtn v-if="can.record" size="small" variant="text" icon="tabler-ruler"
                        :title="t('project_management.site.measure')" @click="openMeasure(i)" />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site.no_boq') }}
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Claims ────────────────────────────────────────────────────────── -->
      <VWindowItem v-if="can.costs" value="claims">
        <VRow v-if="retention" class="mb-2">
          <VCol cols="12" md="4">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.retention_held') }}</div>
              <div class="text-h6">{{ money(retention.held) }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="4">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.retention_released') }}</div>
              <div class="text-h6">{{ money(retention.released) }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="4">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.retention_outstanding') }}</div>
              <div class="text-h6">{{ money(retention.outstanding) }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.site.retention_note') }}</div>
            </VCardText></VCard>
          </VCol>
        </VRow>

        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.site.claims') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.site.claims_sub') }}</VCardSubtitle>
            <template #append>
              <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="claimDialog = true">
                {{ t('project_management.site.draft_claim') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VTable v-if="claims.length" density="comfortable">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('project_management.site.col_period') }}</th>
                <th class="text-right">{{ t('project_management.site.col_gross') }}</th>
                <th class="text-right">{{ t('project_management.site.col_retention') }}</th>
                <th class="text-right">{{ t('project_management.site.col_previously') }}</th>
                <th class="text-right">{{ t('project_management.site.col_net_due') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in claims" :key="c.id">
                <td>{{ c.number }}</td>
                <td class="text-caption text-no-wrap">{{ c.from }} → {{ c.to }}</td>
                <td class="text-right">{{ money(c.gross) }}</td>
                <td class="text-right text-warning">−{{ money(c.retention) }}</td>
                <td class="text-right text-medium-emphasis">−{{ money(c.previously) }}</td>
                <td class="text-right font-weight-medium">{{ money(c.net_due) }}</td>
                <td class="text-right text-no-wrap">
                  <VChip size="x-small" label :color="claimStatusColour[c.status]" class="me-1">
                    {{ t(`project_management.site.claim_status_${c.status}`) }}
                  </VChip>
                  <VBtn
                    v-if="c.editable" size="small" variant="text" icon="tabler-send"
                    :title="t('project_management.site.submit_claim')" @click="submitClaim(c)"
                  />
                  <VBtn
                    v-if="can.certify && c.status === 'submitted'"
                    size="small" variant="text" icon="tabler-file-check" color="success"
                    :title="t('project_management.site.certify')" @click="certifyClaim(c)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            <p class="mb-2">{{ t('project_management.site.no_claims') }}</p>
            <p class="text-caption mb-0">{{ t('project_management.site.no_claims_hint') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Dialogs ───────────────────────────────────────────────────────────── -->

    <VDialog :fullscreen="smAndDown" v-model="diaryDialog" max-width="720" scrollable>
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site.record_day') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField v-model="diaryForm.entry_date" type="date" :max="today"
                          :label="t('project_management.site.col_date')"
                          :error-messages="diaryForm.errors.entry_date" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="diaryForm.weather"
                       :items="weather.map(w => ({ title: t(`project_management.site.weather_${w}`), value: w }))"
                       :label="t('project_management.site.weather')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model.number="diaryForm.rain_hours" type="number" min="0" max="24"
                          :label="t('project_management.site.rain')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model.number="diaryForm.workers_on_site" type="number" min="0"
                          :label="t('project_management.site.workers_label')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="diaryForm.work_done" rows="3"
                         :label="t('project_management.site.work_done')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="diaryForm.plant_on_site" rows="2"
                         :label="t('project_management.site.plant')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="diaryForm.deliveries" rows="2"
                         :label="t('project_management.site.deliveries')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="diaryForm.delays" rows="2"
                         :label="t('project_management.site.delays')"
                         :hint="t('project_management.site.delays_hint')" persistent-hint
                         variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea v-model="diaryForm.safety_notes" rows="2"
                         :label="t('project_management.site.safety')" variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="diaryDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="diaryForm.processing" @click="saveDiary">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="snagDialog" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site.raise_snag') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="snagForm.kind"
                       :items="snagKinds.map(k => ({ title: t(`project_management.site.kind_${k}`), value: k }))"
                       :label="t('project_management.site.kind')"
                       :hint="t('project_management.site.kind_hint')" persistent-hint
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="snagForm.severity"
                       :items="severities.map(s => ({ title: t(`project_management.site.severity_${s}`), value: s }))"
                       :label="t('project_management.site.col_severity')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="snagForm.title" :label="t('project_management.site.col_what')"
                          :error-messages="snagForm.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="snagForm.location" :label="t('project_management.site.col_where')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="snagForm.due_date" type="date" :label="t('project_management.site.col_due')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="snagForm.assigned_member_id"
                       :items="members.map(m => ({ title: m.label, value: m.id }))"
                       :label="t('project_management.site.col_assignee')"
                       variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="snagForm.task_id"
                       :items="tasks.map(x => ({ title: x.label, value: x.id }))"
                       :label="t('project_management.tasks.col_name')"
                       variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="snagForm.description" rows="2"
                         :label="t('project_management.site.col_description')" variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="snagDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="snagForm.processing" :disabled="!snagForm.title" @click="saveSnag">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="closeDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site.close_snag') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ closing?.reference }} · {{ closing?.title }}</p>
          <!-- An NCR closed with a blank note is a register nobody can stand behind at handover. -->
          <VAlert v-if="closing?.needs_evidence" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('project_management.site.close_needs_evidence') }}
          </VAlert>
          <VTextarea v-model="closeForm.closure_note" rows="3"
                     :label="t('project_management.site.closure_note')"
                     :error-messages="closeForm.errors.closure_note" variant="outlined" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="closeDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="success" :loading="closeForm.processing" @click="saveClose">
            {{ t('project_management.site.close_snag') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="boqDialog" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site.add_item') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField v-model="boqForm.item_no" :label="t('project_management.site.col_item')"
                          :hint="t('project_management.site.item_no_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField v-model="boqForm.section" :label="t('project_management.site.section')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="boqForm.description" :label="t('project_management.site.col_description')"
                          :error-messages="boqForm.errors.description" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="boqForm.unit" :items="units"
                       :label="t('project_management.site.col_unit')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="boqForm.quantity" inputmode="decimal"
                          :label="t('project_management.site.col_quantity')"
                          :error-messages="boqForm.errors.quantity" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="boqForm.rate" inputmode="decimal"
                          :label="t('project_management.site.col_rate')"
                          :error-messages="boqForm.errors.rate" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VAutocomplete v-model="boqForm.task_id" :items="tasks.map(x => ({ title: x.label, value: x.id }))"
                       :label="t('project_management.tasks.col_name')" variant="outlined" density="compact" clearable />
            </VCol>
            <VCol cols="12">
              <VCheckbox v-model="boqForm.is_provisional" density="compact" hide-details
                         :label="t('project_management.site.is_provisional')" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="boqDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="boqForm.processing" @click="saveBoq">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="measureDialog" max-width="480">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site.measure') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ measuring?.description }}</p>
          <VTextField
            v-model.number="measureForm.measured_quantity" inputmode="decimal" autofocus
            :label="t('project_management.site.measured_to_date')"
            :suffix="measuring?.unit"
            :hint="t('project_management.site.measure_hint', { quantity: measuring?.quantity, unit: measuring?.unit })"
            persistent-hint
            :error-messages="measureForm.errors.measured_quantity"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="measureDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="measureForm.processing" @click="saveMeasure">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="claimDialog" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site.draft_claim') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.site.draft_claim_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="claimForm.period_start" type="date"
                          :label="t('project_management.site.period_from')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="claimForm.period_end" type="date"
                          :label="t('project_management.site.period_to')"
                          :error-messages="claimForm.errors.period_end" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="claimForm.work_to_date" inputmode="decimal"
                          :label="t('project_management.site.work_to_date')"
                          :hint="t('project_management.site.work_to_date_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="claimForm.materials_on_site" inputmode="decimal"
                          :label="t('project_management.site.materials_on_site')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="claimForm.variations" inputmode="decimal"
                          :label="t('project_management.site.variations')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="claimForm.retention_pct" inputmode="decimal" suffix="%"
                          :label="t('project_management.site.retention_pct')"
                          :hint="t('project_management.site.retention_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol v-if="milestones.length" cols="12">
              <VSelect
                v-model="claimForm.milestone_task_id"
                :items="milestones.filter(m => !m.claimed).map(m => ({ title: m.label, value: m.id }))"
                :label="t('project_management.site.milestone_claim')"
                :hint="t('project_management.site.milestone_claim_hint')" persistent-hint
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="claimForm.notes" rows="2"
                         :label="t('project_management.site.notes')" variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="claimDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="claimForm.processing" @click="saveClaim">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
