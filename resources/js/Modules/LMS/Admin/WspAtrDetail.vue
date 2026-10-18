<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

const { t } = useI18n()
const route = ziggyRoute

type DemographicKey = 'african_male'|'african_female'|'coloured_male'|'coloured_female'|'indian_male'|'indian_female'|'white_male'|'white_female'|'disabled'|'foreign'

type WspItem = {
  id: number; training_type: string; training_description: string | null
  nqf_level: number | null; credits: number | null
  ofo_code: string | null; saqa_id: string | null; target_group: string | null
  planned_beneficiaries: number; planned_cost_per_person: number; total_planned_cost: number
  course: { id: number; title: string } | null
  learnership: { id: number; title: string } | null
  demographics: { demographic_key: DemographicKey; beneficiary_count: number }[]
}

type AtrItem = {
  id: number; training_type: string; training_provider: string | null
  start_date: string | null; end_date: string | null
  nqf_level: number | null; credits: number | null
  cost_per_person: number; result: string
  ofo_code: string | null; saqa_id: string | null
  race: string | null; gender: string | null; has_disability: boolean
  hr_person: { first_name: string; surname: string; employee_number: string | null } | null
}

type Plan = {
  id: number; uuid: string; plan_year: number; status: string
  seta: { acronym: string; name: string } | null
  sdf: { user: { person: { first_name: string; surname: string } | null } | null } | null
  total_levy_paid: number; total_planned_spend: number; planned_spend_pct: number
  due_date: string | null; submission_date: string | null; submission_reference: string | null
  items: WspItem[]
  atr_report: { id: number; status: string; total_actual_spend: number; period_start: string; period_end: string; items: AtrItem[] } | null
}

const props = defineProps<{
  plan: Plan
  courses: { id: number; title: string; nqf_level: number | null; credits: number | null; ofo_code: string | null; saqa_id: string | null }[]
  learnerships: { id: number; title: string; nqf_level: number | null; total_credits: number | null; ofo_code: string | null; saqa_id: string | null }[]
}>()

const confirmDialog = ref<InstanceType<typeof ConfirmDialog>>()
const tab = ref('wsp')
const itemDialog = ref(false)

// ── Target group options ─────────────────────────────────────────────────
const targetGroupOptions = computed(() => [
  { value: 'african_male',    label: t('lms_wsp_atr_detail.demographic_groups.african_male') },
  { value: 'african_female',  label: t('lms_wsp_atr_detail.demographic_groups.african_female') },
  { value: 'coloured_male',   label: t('lms_wsp_atr_detail.demographic_groups.coloured_male') },
  { value: 'coloured_female', label: t('lms_wsp_atr_detail.demographic_groups.coloured_female') },
  { value: 'indian_male',     label: t('lms_wsp_atr_detail.demographic_groups.indian_male') },
  { value: 'indian_female',   label: t('lms_wsp_atr_detail.demographic_groups.indian_female') },
  { value: 'white_male',      label: t('lms_wsp_atr_detail.demographic_groups.white_male') },
  { value: 'white_female',    label: t('lms_wsp_atr_detail.demographic_groups.white_female') },
  { value: 'disabled',        label: t('lms_wsp_atr_detail.demographic_groups.disabled') },
  { value: 'foreign',         label: t('lms_wsp_atr_detail.demographic_groups.foreign') },
])

const trainingTypeOptions = computed(() => [
  { title: t('lms_wsp_atr_detail.training_types.skills_programme'), value: 'skills_programme' },
  { title: t('lms_wsp_atr_detail.training_types.learnership'), value: 'learnership' },
  { title: t('lms_wsp_atr_detail.training_types.bursary'), value: 'bursary' },
  { title: t('lms_wsp_atr_detail.training_types.short_course'), value: 'short_course' },
  { title: t('lms_wsp_atr_detail.training_types.internship'), value: 'internship' },
])

const emptyDemographics = (): Record<DemographicKey, number> => ({
  african_male: 0, african_female: 0,
  coloured_male: 0, coloured_female: 0,
  indian_male: 0, indian_female: 0,
  white_male: 0, white_female: 0,
  disabled: 0, foreign: 0,
})

const itemForm = useForm({
  course_id:               null as number | null,
  learnership_id:          null as number | null,
  training_type:           'skills_programme',
  training_description:    '',
  nqf_level:               null as number | null,
  credits:                 null as number | null,
  ofo_code:                '',
  saqa_id:                 '',
  planned_cost_per_person: 0,
  demographics:            emptyDemographics(),
})

function openAddItem() {
  itemForm.reset()
  itemForm.training_type = 'skills_programme'
  itemForm.demographics  = emptyDemographics()
  itemDialog.value = true
}

const totalDemoCount = computed(() =>
  Object.values(itemForm.demographics).reduce((s: number, v) => s + (Number(v) || 0), 0)
)

function onCourseSelect(id: number | null) {
  const c = props.courses.find(c => c.id === id)
  if (!c) return
  itemForm.training_description = c.title
  itemForm.nqf_level = c.nqf_level
  itemForm.credits = c.credits
  itemForm.ofo_code = c.ofo_code ?? ''
  itemForm.saqa_id = c.saqa_id ?? ''
}

function onLearnershipSelect(id: number | null) {
  const l = props.learnerships.find(l => l.id === id)
  if (!l) return
  itemForm.training_description = l.title
  itemForm.nqf_level = l.nqf_level
  itemForm.credits = l.total_credits
  itemForm.ofo_code = l.ofo_code ?? ''
  itemForm.saqa_id = l.saqa_id ?? ''
  itemForm.training_type = 'learnership'
}

function saveItem() {
  itemForm.post(route('lms.admin.qcto.wsp.items.store', { wspPlan: props.plan.id }), {
    onSuccess: () => { itemDialog.value = false },
  })
}

function removeItem(itemId: number) {
  confirmDialog.value?.open(
    {
      title: t('lms_wsp_atr_detail.confirm_delete.title'),
      message: t('lms_wsp_atr_detail.confirm_delete.message'),
      confirmText: t('lms_wsp_atr_detail.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { useForm({}).delete(route('lms.admin.qcto.wsp.items.destroy', { item: itemId })) },
  )
}

const statusColor: Record<string, string> = {
  draft: 'default', submitted: 'info', approved: 'success', rejected: 'error', resubmit: 'warning',
}

const totalPlanned = props.plan.items.reduce((s, i) => s + i.total_planned_cost, 0)

function formatCurrency(amount: number): string {
  return amount.toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <ConfirmDialog ref="confirmDialog" />
    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-5 flex-wrap">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" @click="router.visit(route('lms.admin.qcto.wsp-atr'))">
        {{ t('lms_wsp_atr_detail.back') }}
      </VBtn>
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">WSP {{ plan.plan_year }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ plan.seta?.acronym ?? t('lms_wsp_atr_detail.no_seta') }}
          <span v-if="plan.sdf?.user?.person">
            · {{ t('lms_wsp_atr_detail.sdf_label') }}: {{ plan.sdf.user.person.first_name }} {{ plan.sdf.user.person.surname }}
          </span>
          <span v-if="plan.due_date"> · {{ t('lms_wsp_atr_detail.due_label') }}: {{ plan.due_date }}</span>
        </p>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip :color="statusColor[plan.status]" variant="tonal" class="text-capitalize">
          {{ t(`lms_wsp_atr_detail.statuses.${plan.status}`) }}
        </VChip>
        <VBtn size="small" variant="tonal" :href="route('lms.admin.qcto.wsp.export', { wspPlan: plan.id })" prepend-icon="tabler-download">
          WSP CSV
        </VBtn>
        <VBtn v-if="plan.atr_report" size="small" variant="tonal"
          :href="route('lms.admin.qcto.atr.export', { atrReport: plan.atr_report.id })"
          prepend-icon="tabler-download">
          {{ t('lms_wsp_atr_detail.tabs.atr_items', { count: plan.atr_report.items.length }) }}
        </VBtn>
      </div>
    </div>

    <!-- Summary cards -->
    <VRow class="mb-4">
      <VCol cols="6" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-3 text-center">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr_detail.summary.levy_paid') }}</p>
            <p class="text-body-1 font-weight-bold mb-0">R{{ formatCurrency(plan.total_levy_paid) }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-3 text-center">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr_detail.summary.planned_spend') }}</p>
            <p class="text-body-1 font-weight-bold mb-0">R{{ formatCurrency(totalPlanned) }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-3 text-center">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr_detail.summary.wsp_items') }}</p>
            <p class="text-body-1 font-weight-bold mb-0">{{ plan.items.length }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-3 text-center">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_wsp_atr_detail.summary.atr_actual_spend') }}</p>
            <p class="text-body-1 font-weight-bold mb-0">
              {{ plan.atr_report ? 'R' + formatCurrency(plan.atr_report.total_actual_spend) : '—' }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="wsp">{{ t('lms_wsp_atr_detail.tabs.wsp_items', { count: plan.items.length }) }}</VTab>
      <VTab value="atr" :disabled="!plan.atr_report">
        {{ t('lms_wsp_atr_detail.tabs.atr_items', { count: plan.atr_report?.items.length ?? 0 }) }}
      </VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- WSP Items -->
      <VWindowItem value="wsp">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" size="small" @click="openAddItem">
            {{ t('lms_wsp_atr_detail.dialog_add_item.add') }}
          </VBtn>
        </div>
        <VCard rounded="lg" elevation="0" border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('lms_wsp_atr_detail.wsp_table.training') }}</th>
                <th>{{ t('lms_wsp_atr_detail.wsp_table.type') }}</th>
                <th>{{ t('lms_wsp_atr_detail.wsp_table.nqf') }}</th>
                <th>{{ t('lms_wsp_atr_detail.wsp_table.ofo_code') }}</th>
                <th>{{ t('lms_wsp_atr_detail.wsp_table.demographic_breakdown') }}</th>
                <th class="text-right">{{ t('lms_wsp_atr_detail.wsp_table.total') }}</th>
                <th class="text-right">{{ t('lms_wsp_atr_detail.wsp_table.cost_per_person') }}</th>
                <th class="text-right">{{ t('lms_wsp_atr_detail.wsp_table.total_cost') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in plan.items" :key="item.id">
                <td>
                  <p class="text-body-2 font-weight-medium mb-0">
                    {{ item.training_description ?? item.course?.title ?? item.learnership?.title ?? '—' }}
                  </p>
                  <p v-if="item.saqa_id" class="text-caption text-medium-emphasis mb-0">
                    {{ t('lms_wsp_atr_detail.wsp_table.saqa_id', { id: item.saqa_id }) }}
                  </p>
                </td>
                <td>
                  <VChip size="x-small" variant="tonal" class="text-capitalize">
                    {{ t(`lms_wsp_atr_detail.training_types.${item.training_type}`) }}
                  </VChip>
                </td>
                <td>{{ item.nqf_level ?? '—' }}</td>
                <td class="text-caption">{{ item.ofo_code ?? '—' }}</td>
                <td class="text-caption" style="max-width:220px">
                  <template v-if="item.demographics?.length">
                    <span v-for="d in item.demographics.filter(d => d.beneficiary_count > 0)" :key="d.demographic_key" class="me-2 text-no-wrap">
                      {{ targetGroupOptions.find(o => o.value === d.demographic_key)?.label ?? d.demographic_key }}: <strong>{{ d.beneficiary_count }}</strong>
                    </span>
                  </template>
                  <span v-else class="text-medium-emphasis">
                    {{ targetGroupOptions.find(o => o.value === item.target_group)?.label ?? item.target_group ?? '—' }}
                  </span>
                </td>
                <td class="text-right text-body-2">{{ item.planned_beneficiaries }}</td>
                <td class="text-right text-body-2">R{{ formatCurrency(item.planned_cost_per_person) }}</td>
                <td class="text-right text-body-2 font-weight-semibold">R{{ formatCurrency(item.total_planned_cost) }}</td>
                <td>
                  <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="removeItem(item.id)" />
                </td>
              </tr>
              <tr v-if="!plan.items.length">
                <td colspan="9" class="text-center text-medium-emphasis pa-6">
                  {{ t('lms_wsp_atr_detail.wsp_table.no_items') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- ATR Items -->
      <VWindowItem value="atr">
        <VCard v-if="plan.atr_report" rounded="lg" elevation="0" border>
          <VCardText class="pa-3 d-flex align-center gap-3">
        <span class="text-body-2 font-weight-semibold">
  ATR {{ plan.plan_year }} —
  {{ t('lms_wsp_atr_detail.atr_table.period_range', {
    start: formatDate(plan.atr_report.period_start),
    end: formatDate(plan.atr_report.period_end)
  }) }}
</span>
            <VChip :color="statusColor[plan.atr_report.status]" size="x-small" variant="tonal" class="text-capitalize">
              {{ t(`lms_wsp_atr_detail.statuses.${plan.atr_report.status}`) }}
            </VChip>
          </VCardText>
          <VDivider />
          <VTable>
            <thead>
              <tr>
                <th>{{ t('lms_wsp_atr_detail.atr_table.employee') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.training_type') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.period') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.nqf') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.race') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.gender') }}</th>
                <th>{{ t('lms_wsp_atr_detail.atr_table.result') }}</th>
                <th class="text-right">{{ t('lms_wsp_atr_detail.atr_table.cost') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in plan.atr_report.items" :key="item.id">
                <td>
                  <p class="text-body-2 font-weight-medium mb-0">
                    {{ item.hr_person ? `${item.hr_person.first_name} ${item.hr_person.surname}` : '—' }}
                  </p>
                  <p v-if="item.hr_person?.employee_number" class="text-caption text-medium-emphasis mb-0">
                    {{ item.hr_person.employee_number }}
                  </p>
                </td>
                <td>
                  <VChip size="x-small" variant="tonal" class="text-capitalize">
                    {{ t(`lms_wsp_atr_detail.training_types.${item.training_type}`) }}
                  </VChip>
                </td>
                <td class="text-caption">
                  {{ item.start_date ?? '—' }}<br>{{ item.end_date ?? '' }}
                </td>
                <td>{{ item.nqf_level ?? '—' }}</td>
                <td>{{ item.race ?? '—' }}</td>
                <td class="text-capitalize">{{ item.gender ?? '—' }}</td>
                <td>
                  <VChip :color="item.result === 'completed' ? 'success' : 'error'" size="x-small" variant="tonal" class="text-capitalize">
                    {{ item.result }}
                  </VChip>
                </td>
                <td class="text-right text-body-2">R{{ formatCurrency(item.cost_per_person) }}</td>
              </tr>
              <tr v-if="!plan.atr_report.items.length">
                <td colspan="8" class="text-center text-medium-emphasis pa-6">
                  {{ t('lms_wsp_atr_detail.atr_table.no_items') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Add item dialog -->
    <VDialog v-model="itemDialog" max-width="580" persistent>
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_wsp_atr_detail.dialog_add_item.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="itemForm.training_type"
                :label="t('lms_wsp_atr_detail.dialog_add_item.training_type_label')"
                :items="trainingTypeOptions"
                :error-messages="itemForm.errors.training_type"
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="itemForm.course_id"
                :label="t('lms_wsp_atr_detail.dialog_add_item.course_label')"
                :items="courses.map(c => ({ title: c.title, value: c.id }))"
                clearable
                @update:model-value="onCourseSelect"
                :error-messages="itemForm.errors.course_id"
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="itemForm.learnership_id"
                :label="t('lms_wsp_atr_detail.dialog_add_item.learnership_label')"
                :items="learnerships.map(l => ({ title: l.title, value: l.id }))"
                clearable
                @update:model-value="onLearnershipSelect"
                :error-messages="itemForm.errors.learnership_id"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="itemForm.training_description"
                :label="t('lms_wsp_atr_detail.dialog_add_item.description_label')"
                :error-messages="itemForm.errors.training_description"
              />
            </VCol>
            <VCol cols="4">
              <VTextField
                v-model="itemForm.nqf_level"
                :label="t('lms_wsp_atr_detail.dialog_add_item.nqf_level_label')"
                type="number"
                min="1"
                max="10"
                :error-messages="itemForm.errors.nqf_level"
              />
            </VCol>
            <VCol cols="4">
              <VTextField
                v-model="itemForm.credits"
                :label="t('lms_wsp_atr_detail.dialog_add_item.credits_label')"
                type="number"
                min="0"
                :error-messages="itemForm.errors.credits"
              />
            </VCol>
            <VCol cols="4">
              <VTextField
                v-model="itemForm.planned_cost_per_person"
                :label="t('lms_wsp_atr_detail.dialog_add_item.cost_per_person_label')"
                type="number"
                min="0"
                :error-messages="itemForm.errors.planned_cost_per_person"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="itemForm.ofo_code"
                :label="t('lms_wsp_atr_detail.dialog_add_item.ofo_code_label')"
                :hint="t('lms_wsp_atr_detail.dialog_add_item.ofo_code_hint')"
                persistent-hint
                :error-messages="itemForm.errors.ofo_code"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="itemForm.saqa_id"
                :label="t('lms_wsp_atr_detail.dialog_add_item.saqa_id_label')"
                :error-messages="itemForm.errors.saqa_id"
              />
            </VCol>

            <!-- Per-demographic beneficiary counts -->
            <VCol cols="12" class="pt-1 pb-0">
              <p class="text-caption text-medium-emphasis mb-1">
                {{ t('lms_wsp_atr_detail.dialog_add_item.demographic_title') }}
                <span class="font-weight-semibold text-primary ms-2">
                  {{ t('lms_wsp_atr_detail.dialog_add_item.total_beneficiaries', { count: totalDemoCount }) }}
                </span>
              </p>
            </VCol>
            <VCol v-for="group in targetGroupOptions" :key="group.value" cols="6" class="py-1">
              <VTextField
                v-model.number="itemForm.demographics[group.value as DemographicKey]"
                :label="group.label"
                type="number"
                min="0"
                density="compact"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="itemDialog = false">{{ t('lms_wsp_atr_detail.dialog_add_item.cancel') }}</VBtn>
          <VBtn color="primary" :loading="itemForm.processing" @click="saveItem">
            {{ t('lms_wsp_atr_detail.dialog_add_item.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
