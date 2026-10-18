<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ────────────────────────────────────────────────────────────────
interface Seta {
  id: number
  name: string
  acronym: string
}

interface SdfUser {
  person: { first_name: string; surname: string } | null
}

interface Sdf {
  id: number
  user: SdfUser | null
}

interface AtrReport {
  id: number
  status: string
  total_actual_spend: number
}

interface WspPlan {
  id: number
  uuid: string
  plan_year: number
  seta: Seta | null
  seta_id: number | null
  sdf: Sdf | null
  sdf_id: number | null
  status: string
  items_count: number
  total_levy_paid: number
  total_planned_spend: number
  planned_spend_pct: number
  mandatory_grant_claimed: boolean
  discretionary_grant_applied: boolean
  submission_date: string | null
  submission_reference: string | null
  due_date: string | null
  notes: string | null
  sdl_number: string | null
  paye_reference: string | null
  sic_code: string | null
  atr_report: AtrReport | null
}

const props = defineProps<{
  wspPlans: WspPlan[]
  setas: Seta[]
  sdfs: Sdf[]
}>()

// ── State ────────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<WspPlan | null>(null)
const atrDialog = ref(false)
const activeWsp = ref<WspPlan | null>(null)

// ── Forms ────────────────────────────────────────────────────────────────
const form = useForm({
  plan_year: new Date().getFullYear(),
  seta_id: null as number | null,
  sdf_id: null as number | null,
  due_date: '',
  total_levy_paid: 0,
  mandatory_grant_claimed: false,
  discretionary_grant_applied: false,
  sdl_number: '',
  paye_reference: '',
  sic_code: '',
  notes: '',
})

const atrForm = useForm({
  seta_id: null as number | null,
  sdf_id: null as number | null,
  period_start: '',
  period_end: '',
  notes: '',
})

// ── Status colors ────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft: 'default',
  submitted: 'info',
  approved: 'success',
  rejected: 'error',
  resubmit: 'warning',
}

// ── Helper functions ─────────────────────────────────────────────────────
function sdfName(sdf: Sdf | null): string {
  const p = sdf?.user?.person
  return p ? `${p.first_name} ${p.surname}` : t('lms_wsp_atr.sdf_label') + ` #${sdf?.id ?? '?'}`
}

function formatCurrency(amount: number): string {
  return amount.toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

// ── Dialog methods ──────────────────────────────────────────────────────
function openNew() {
  editing.value = null
  form.reset()
  form.plan_year = new Date().getFullYear()
  form.due_date = `${new Date().getFullYear()}-04-30`
  dialog.value = true
}

function openEdit(p: WspPlan) {
  editing.value = p
  form.plan_year = p.plan_year
  form.seta_id = p.seta_id
  form.sdf_id = p.sdf_id
  form.due_date = p.due_date ?? ''
  form.total_levy_paid = p.total_levy_paid
  form.mandatory_grant_claimed = p.mandatory_grant_claimed
  form.discretionary_grant_applied = p.discretionary_grant_applied
  form.sdl_number = p.sdl_number ?? ''
  form.paye_reference = p.paye_reference ?? ''
  form.sic_code = p.sic_code ?? ''
  form.notes = p.notes ?? ''
  form.clearErrors()
  dialog.value = true
}

function save() {
  if (editing.value) {
    form.patch(route('lms.admin.qcto.wsp.update', { wspPlan: editing.value.id }), {
      onSuccess: () => { dialog.value = false }
    })
  } else {
    form.post(route('lms.admin.qcto.wsp.store'), {
      onSuccess: () => { dialog.value = false }
    })
  }
}

function openCreateAtr(plan: WspPlan) {
  activeWsp.value = plan
  atrForm.reset()
  atrForm.seta_id = plan.seta_id
  atrForm.sdf_id = plan.sdf_id
  atrForm.period_start = `${plan.plan_year - 1}-04-01`
  atrForm.period_end = `${plan.plan_year}-03-31`
  atrDialog.value = true
}

function saveAtr() {
  if (!activeWsp.value) return
  atrForm.post(route('lms.admin.qcto.atr.store', { wspPlan: activeWsp.value.id }), {
    onSuccess: () => { atrDialog.value = false },
  })
}

// ── Computed options ─────────────────────────────────────────────────────
const setaItems = computed(() =>
  props.setas.map(s => ({ title: s.acronym, value: s.id }))
)

const sdfItems = computed(() =>
  props.sdfs.map(s => ({ title: sdfName(s), value: s.id }))
)
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_wsp_atr.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr.page_description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">
        {{ t('lms_wsp_atr.new_wsp') }}
      </VBtn>
    </div>

    <VRow>
      <VCol v-for="plan in wspPlans" :key="plan.id" cols="12" md="6">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-start gap-3 mb-3">
              <VAvatar color="primary" variant="tonal" size="44" rounded>
                <VIcon icon="tabler-file-report" size="22" />
              </VAvatar>
              <div class="flex-1-1">
                <div class="d-flex align-center gap-2 mb-1">
                  <p class="text-body-1 font-weight-semibold mb-0">
                    {{ t('lms_wsp_atr.wsp_year', { year: plan.plan_year }) }}
                  </p>
                  <VChip :color="statusColor[plan.status]" size="x-small" variant="tonal" class="text-capitalize">
                    {{ t(`lms_wsp_atr.statuses.${plan.status}`) }}
                  </VChip>
                </div>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ plan.seta?.acronym ?? t('lms_wsp_atr.no_seta') }}
                  <span v-if="plan.sdf_id"> · {{ t('lms_wsp_atr.sdf_label') }}: {{ sdfName(plan.sdf) }}</span>
                </p>
              </div>
            </div>

            <!-- Financial summary -->
            <VRow dense class="mb-3">
              <VCol cols="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr.levy_paid') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">R{{ formatCurrency(plan.total_levy_paid) }}</p>
              </VCol>
              <VCol cols="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr.planned_spend') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">R{{ formatCurrency(plan.total_planned_spend) }}</p>
              </VCol>
              <VCol cols="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr.items') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ plan.items_count }}</p>
              </VCol>
            </VRow>

            <!-- ATR status -->
            <div v-if="plan.atr_report" class="pa-2 rounded mb-3 d-flex align-center gap-2"
              style="background:rgba(var(--v-theme-on-surface),0.04)">
              <VIcon icon="tabler-file-check" size="16" color="success" />
              <span class="text-caption">
                {{ t('lms_wsp_atr.atr_label', { year: plan.plan_year }) }}:
                R{{ formatCurrency(plan.atr_report.total_actual_spend) }}
              </span>
              <VChip :color="statusColor[plan.atr_report.status]" size="x-small" variant="tonal" class="ms-auto text-capitalize">
                {{ t(`lms_wsp_atr.statuses.${plan.atr_report.status}`) }}
              </VChip>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2 flex-wrap">
              <VBtn size="small" variant="tonal" color="primary"
                @click="router.visit(route('lms.admin.qcto.wsp-atr.show', { wspPlan: plan.id }))">
                {{ t('lms_wsp_atr.actions.view_items') }}
              </VBtn>
              <VBtn v-if="!plan.atr_report" size="small" variant="tonal" color="success" @click="openCreateAtr(plan)">
                {{ t('lms_wsp_atr.actions.generate_atr') }}
              </VBtn>
              <VBtn size="small" variant="tonal"
                :href="route('lms.admin.qcto.wsp.export', { wspPlan: plan.id })"
                prepend-icon="tabler-download">
                {{ t('lms_wsp_atr.actions.export_wsp') }}
              </VBtn>
              <VBtn v-if="plan.atr_report" size="small" variant="tonal"
                :href="route('lms.admin.qcto.atr.export', { atrReport: plan.atr_report.id })"
                prepend-icon="tabler-download">
                {{ t('lms_wsp_atr.actions.export_atr') }}
              </VBtn>
              <VBtn icon="tabler-edit" size="small" variant="text" @click="openEdit(plan)" />
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!wspPlans.length" cols="12">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="text-center pa-8 text-medium-emphasis">
            {{ t('lms_wsp_atr.no_wsp') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- WSP dialog -->
    <VDialog v-model="dialog" max-width="540">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ editing ? t('lms_wsp_atr.dialog_wsp.title_edit') : t('lms_wsp_atr.dialog_wsp.title_new') }}
        </VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="6">
              <VTextField
                v-model="form.plan_year"
                :label="t('lms_wsp_atr.dialog_wsp.plan_year_label')"
                type="number"
                min="2020"
                max="2040"
                :error-messages="form.errors.plan_year"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.seta_id"
                :label="t('lms_wsp_atr.dialog_wsp.seta_label')"
                :items="setaItems"
                clearable
                :error-messages="form.errors.seta_id"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.sdf_id"
                :label="t('lms_wsp_atr.dialog_wsp.sdf_label')"
                :items="sdfItems"
                clearable
                :error-messages="form.errors.sdf_id"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.due_date"
                :label="t('lms_wsp_atr.dialog_wsp.due_date_label')"
                type="date"
                :error-messages="form.errors.due_date"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.total_levy_paid"
                :label="t('lms_wsp_atr.dialog_wsp.levy_paid_label')"
                type="number"
                min="0"
                :hint="t('lms_wsp_atr.dialog_wsp.levy_paid_hint')"
                persistent-hint
                :error-messages="form.errors.total_levy_paid"
              />
            </VCol>
            <VCol cols="6">
              <VCheckbox
                v-model="form.mandatory_grant_claimed"
                :label="t('lms_wsp_atr.dialog_wsp.mandatory_grant_label')"
                hide-details
              />
            </VCol>
            <VCol cols="6">
              <VCheckbox
                v-model="form.discretionary_grant_applied"
                :label="t('lms_wsp_atr.dialog_wsp.discretionary_grant_label')"
                hide-details
              />
            </VCol>
            <VDivider class="my-2" />
            <VCol cols="12">
              <p class="text-caption text-medium-emphasis mb-2">
                {{ t('lms_wsp_atr.dialog_wsp.identifiers_title') }}
              </p>
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.sdl_number"
                :label="t('lms_wsp_atr.dialog_wsp.sdl_number_label')"
                :hint="t('lms_wsp_atr.dialog_wsp.sdl_number_hint')"
                persistent-hint
                :error-messages="form.errors.sdl_number"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.paye_reference"
                :label="t('lms_wsp_atr.dialog_wsp.paye_reference_label')"
                :hint="t('lms_wsp_atr.dialog_wsp.paye_reference_hint')"
                persistent-hint
                :error-messages="form.errors.paye_reference"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.sic_code"
                :label="t('lms_wsp_atr.dialog_wsp.sic_code_label')"
                :hint="t('lms_wsp_atr.dialog_wsp.sic_code_hint')"
                persistent-hint
                :error-messages="form.errors.sic_code"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('lms_wsp_atr.dialog_wsp.notes_label')"
                rows="2"
                auto-grow
                :error-messages="form.errors.notes"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="dialog = false">{{ t('lms_wsp_atr.dialog_wsp.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ t('lms_wsp_atr.dialog_wsp.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ATR dialog -->
    <VDialog v-model="atrDialog" max-width="500">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ t('lms_wsp_atr.dialog_atr.title', { year: activeWsp?.plan_year ?? '' }) }}
        </VCardTitle>
        <VCardText class="pa-5">
          <VAlert type="info" variant="tonal" class="mb-4" density="compact">
            {{ t('lms_wsp_atr.dialog_atr.info') }}
          </VAlert>
          <VRow dense>
            <VCol cols="6">
              <VTextField
                v-model="atrForm.period_start"
                :label="t('lms_wsp_atr.dialog_atr.period_start_label')"
                type="date"
                :error-messages="atrForm.errors.period_start"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="atrForm.period_end"
                :label="t('lms_wsp_atr.dialog_atr.period_end_label')"
                type="date"
                :error-messages="atrForm.errors.period_end"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="atrForm.seta_id"
                :label="t('lms_wsp_atr.dialog_atr.seta_label')"
                :items="setaItems"
                clearable
                :error-messages="atrForm.errors.seta_id"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="atrForm.sdf_id"
                :label="t('lms_wsp_atr.dialog_atr.sdf_label')"
                :items="sdfItems"
                clearable
                :error-messages="atrForm.errors.sdf_id"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="atrForm.notes"
                :label="t('lms_wsp_atr.dialog_atr.notes_label')"
                rows="2"
                auto-grow
                :error-messages="atrForm.errors.notes"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="atrDialog = false">{{ t('lms_wsp_atr.dialog_atr.cancel') }}</VBtn>
          <VBtn color="success" :loading="atrForm.processing" @click="saveAtr">
            {{ t('lms_wsp_atr.dialog_atr.generate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
