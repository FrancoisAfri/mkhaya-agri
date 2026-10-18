<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type LeaveType = {
  id: number
  name: string
  statutory_type: string | null
  bcea_minimum_days: number | null
  is_paid: boolean
  carry_over_enabled: boolean
  carry_over_max_hours: number | null
  accrual_rate_hours_per_month: number | null
  require_probation_period_days: number | null
  max_consecutive_hours: number | null
  gender_restriction: string | null
  requires_medical_certificate: boolean
  doc_required: boolean
  notes_required: boolean
  prevent_deletion: boolean
  leave_requests_count: number
}

const props = defineProps<{ leaveTypes: LeaveType[] }>()

const drawer        = ref(false)
const deleteDialog  = ref(false)
const editTarget    = ref<LeaveType | null>(null)
const deleteTarget  = ref<LeaveType | null>(null)
const search        = ref('')

const H = 8  // hours per day

const emptyForm = () => ({
  name: '',
  statutory_type: null as string | null,
  bcea_minimum_days: null as number | null,
  is_paid: true,
  carry_over_enabled: false,
  carry_over_max_days: null as number | null,
  accrual_rate_days_per_month: null as number | null,
  require_probation_period_days: null as number | null,
  max_consecutive_days: null as number | null,
  gender_restriction: null as string | null,
  requires_medical_certificate: false,
  doc_required: false,
  notes_required: false,
})

const form = useForm(emptyForm())

const isEdit = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? t('leave.edit_leave_type') : t('leave.add_leave_type'))

const openCreate = () => {
  editTarget.value = null
  form.reset()
  Object.assign(form, emptyForm())
  drawer.value = true
}

const openEdit = (lt: LeaveType) => {
  editTarget.value = lt
  form.name = lt.name
  form.statutory_type = lt.statutory_type
  form.bcea_minimum_days = lt.bcea_minimum_days
  form.is_paid = lt.is_paid
  form.carry_over_enabled = lt.carry_over_enabled
  form.carry_over_max_days = lt.carry_over_max_hours !== null ? Math.round(lt.carry_over_max_hours / H * 100) / 100 : null
  form.accrual_rate_days_per_month = lt.accrual_rate_hours_per_month !== null ? Math.round(lt.accrual_rate_hours_per_month / H * 100) / 100 : null
  form.require_probation_period_days = lt.require_probation_period_days
  form.max_consecutive_days = lt.max_consecutive_hours !== null ? Math.round(lt.max_consecutive_hours / H * 100) / 100 : null
  form.gender_restriction = lt.gender_restriction
  form.requires_medical_certificate = lt.requires_medical_certificate
  form.doc_required = lt.doc_required
  form.notes_required = lt.notes_required
  drawer.value = true
}

const submitForm = () => {
  const transformed = form.transform(d => ({
    name:                          d.name,
    statutory_type:                d.statutory_type,
    bcea_minimum_days:             d.bcea_minimum_days,
    is_paid:                       d.is_paid,
    carry_over_enabled:            d.carry_over_enabled,
    carry_over_max_hours:          d.carry_over_max_days !== null ? d.carry_over_max_days * H : null,
    accrual_rate_hours_per_month:  d.accrual_rate_days_per_month !== null ? d.accrual_rate_days_per_month * H : null,
    require_probation_period_days: d.require_probation_period_days,
    max_consecutive_hours:         d.max_consecutive_days !== null ? d.max_consecutive_days * H : null,
    gender_restriction:            d.gender_restriction,
    requires_medical_certificate:  d.requires_medical_certificate,
    doc_required:                  d.doc_required,
    notes_required:                d.notes_required,
  }))
  if (isEdit.value && editTarget.value) {
    transformed.patch(`/leave-management/setup/leave-types/${editTarget.value.id}`, {
      onSuccess: () => { drawer.value = false; form.reset() },
    })
  } else {
    transformed.post('/leave-management/setup/leave-types', {
      onSuccess: () => { drawer.value = false; form.reset() },
    })
  }
}

const confirmDelete = (lt: LeaveType) => { deleteTarget.value = lt; deleteDialog.value = true }
const submitDelete = () => {
  if (!deleteTarget.value) return
  useForm({}).delete(`/leave-management/setup/leave-types/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false },
  })
}

const statutoryOptions = computed(() => [
  { title: t('leave_type_page.statutory_none'), value: null },
  { title: t('leave_type_page.statutory_annual'), value: 'annual' },
  { title: t('leave_type_page.statutory_sick'), value: 'sick' },
  { title: t('leave_type_page.statutory_family'), value: 'family_responsibility' },
  { title: t('leave_type_page.statutory_maternity'), value: 'maternity' },
  { title: t('leave_type_page.statutory_parental'), value: 'parental' },
  { title: t('leave_type_page.statutory_adoption'), value: 'adoption' },
  { title: t('leave_type_page.statutory_commissioning'), value: 'commissioning' },
])

const genderOptions = computed(() => [
  { title: t('leave_type_page.gender_any'), value: null },
  { title: t('leave_type_page.gender_female'), value: 'female' },
  { title: t('leave_type_page.gender_male'), value: 'male' },
])

const statutoryColor = (t: string | null) => ({
  annual: 'success', sick: 'warning', maternity: 'pink', parental: 'purple',
  family_responsibility: 'orange', adoption: 'teal', commissioning: 'cyan',
}[t ?? ''] ?? 'secondary')

const filtered = computed(() =>
  search.value
    ? props.leaveTypes.filter(t => t.name.toLowerCase().includes(search.value.toLowerCase()))
    : props.leaveTypes,
)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.setup_label') }}</div>
              <h4 class="text-h4">{{ t('leave.leave_types_setup') }}</h4>
            </div>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('leave.add_leave_type') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Search -->
      <VCol cols="12" sm="5" md="4">
        <VTextField
          v-model="search"
          :label="t('leave_type_page.search')"
          variant="outlined"
          density="comfortable"
          clearable
          prepend-inner-icon="tabler-magnify"
          hide-details
        />
      </VCol>

      <!-- Table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave_type_page.table_name') }}</th>
                <th>{{ t('leave_type_page.table_statutory_type') }}</th>
                <th>{{ t('leave_type_page.table_paid') }}</th>
                <th>{{ t('leave_type_page.table_carry_over') }}</th>
                <th>{{ t('leave_type_page.table_accrual') }}</th>
                <th>{{ t('leave_type_page.table_med_cert') }}</th>
                <th>{{ t('leave_type_page.table_requests') }}</th>
                <th>{{ t('leave_type_page.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!filtered.length">
                <td colspan="8" class="text-center py-8 text-medium-emphasis">{{ t('leave_type_page.no_leave_types') }}</td>
              </tr>
              <tr v-for="lt in filtered" :key="lt.id">
                <td class="font-weight-medium">{{ lt.name }}</td>
                <td>
                  <VChip v-if="lt.statutory_type" size="x-small" :color="statutoryColor(lt.statutory_type)" label>
                    {{ lt.statutory_type }}
                  </VChip>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>
                  <VIcon :icon="lt.is_paid ? 'tabler-check-circle' : 'tabler-close-circle'" :color="lt.is_paid ? 'success' : 'error'" size="18" />
                </td>
                <td>
                  <span v-if="lt.carry_over_enabled">
                    {{ lt.carry_over_max_hours !== null
                      ? t('leave_type_page.carry_over_max', { days: Math.round(lt.carry_over_max_hours / 8 * 10) / 10 })
                      : t('leave_type_page.carry_over_unlimited') }}
                  </span>
                  <span v-else class="text-medium-emphasis">{{ t('leave_type_page.carry_over_no') }}</span>
                </td>
                <td>
                  {{ lt.accrual_rate_hours_per_month !== null
                    ? t('leave_type_page.accrual_days_per_month', { days: Math.round(lt.accrual_rate_hours_per_month / 8 * 100) / 100 })
                    : '—' }}
                </td>
                <td>
                  <VIcon :icon="lt.requires_medical_certificate ? 'tabler-check-circle' : 'tabler-close-circle'" :color="lt.requires_medical_certificate ? 'warning' : 'secondary'" size="18" />
                </td>
                <td>{{ lt.leave_requests_count }}</td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn icon size="x-small" variant="text" color="primary" @click="openEdit(lt)">
                      <i class="fa-solid fa-pen-to-square" style="font-size:13px" />
                    </VBtn>
                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      color="error"
                      :disabled="lt.prevent_deletion || lt.leave_requests_count > 0"
                      @click="confirmDelete(lt)"
                    >
                      <i class="fa-solid fa-trash" style="font-size:13px" />
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Create/Edit drawer -->
    <VNavigationDrawer v-model="drawer" temporary location="right" width="480">
      <div class="pa-4 d-flex align-center justify-space-between border-b">
        <span class="text-subtitle-1 font-weight-medium">{{ drawerTitle }}</span>
        <VBtn icon variant="text" size="small" @click="drawer = false">
          <i class="fa-solid fa-xmark" />
        </VBtn>
      </div>
      <div class="pa-4 overflow-y-auto" style="height: calc(100% - 65px)">
        <VForm @submit.prevent="submitForm">
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="form.name"
                :label="t('leave_type_page.field_name')"
                :error-messages="form.errors.name"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.statutory_type"
                :items="statutoryOptions"
                :label="t('leave_type_page.field_statutory_type')"
                :error-messages="form.errors.statutory_type"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="form.bcea_minimum_days"
                :label="t('leave_type_page.field_bcea_min_days')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="form.accrual_rate_days_per_month"
                :label="t('leave_type_page.field_accrual_days')"
                type="number"
                step="0.5"
                min="0"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="form.max_consecutive_days"
                :label="t('leave_type_page.field_max_consecutive_days')"
                type="number"
                min="0"
                step="0.5"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="form.require_probation_period_days"
                :label="t('leave_type_page.field_min_service_days')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.gender_restriction"
                :items="genderOptions"
                :label="t('leave_type_page.field_gender_restriction')"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VDivider class="my-2" />
              <p class="text-caption text-medium-emphasis mb-2">{{ t('leave_type_page.section_carry_over') }}</p>
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="form.carry_over_enabled"
                :label="t('leave_type_page.enable_carry_over')"
                color="primary"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol v-if="form.carry_over_enabled" cols="12">
              <VTextField
                v-model.number="form.carry_over_max_days"
                :label="t('leave_type_page.max_carry_over_days')"
                type="number"
                min="0"
                step="0.5"
                variant="outlined"
                density="comfortable"
                class="mt-2"
              />
            </VCol>
            <VCol cols="12">
              <VDivider class="my-2" />
              <p class="text-caption text-medium-emphasis mb-2">{{ t('leave_type_page.section_flags') }}</p>
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="form.is_paid" :label="t('leave_type_page.paid_leave')" color="success" density="compact" hide-details class="mb-2" />
              <VSwitch v-model="form.requires_medical_certificate" :label="t('leave_type_page.requires_medical_certificate')" color="warning" density="compact" hide-details class="mb-2" />
              <VSwitch v-model="form.doc_required" :label="t('leave_type_page.document_required')" color="primary" density="compact" hide-details class="mb-2" />
              <VSwitch v-model="form.notes_required" :label="t('leave_type_page.notes_required')" color="primary" density="compact" hide-details />
            </VCol>
            <VCol cols="12" class="mt-4">
              <VAlert
                v-if="Object.keys(form.errors).length"
                type="error"
                variant="tonal"
                density="compact"
                class="mb-3"
              >
                <div v-for="(msg, field) in form.errors" :key="field">{{ msg }}</div>
              </VAlert>
              <div class="d-flex gap-3 justify-end">
                <VBtn variant="tonal" @click="drawer = false">{{ t('leave.cancel') }}</VBtn>
                <VBtn color="primary" :loading="form.processing" @click="submitForm">{{ t('leave.save') }}</VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Delete confirm -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.delete') }} {{ t('leave.leave_types_setup') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          {{ t('leave_type_page.delete_confirm', { name: deleteTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="deleteDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="error" @click="submitDelete">{{ t('leave.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
