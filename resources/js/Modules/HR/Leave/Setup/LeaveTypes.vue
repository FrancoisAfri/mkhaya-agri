<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type LeaveType = {
  id: number
  name: string
  statutory_type: string | null
  bcea_minimum_days: number | null
  is_paid: boolean
  accrual_rate_hours_per_month: number | null
  carry_over_enabled: boolean
  carry_over_max_days: number | null
  doc_required: number
  notes_required: number
  requires_medical_certificate: boolean
  is_birthday_leave: boolean
  prevent_deletion: boolean
  active: boolean
}

const props = defineProps<{ leaveTypes: LeaveType[] }>()

const dialog      = ref(false)
const submitting  = ref(false)
const editingType = ref<LeaveType | null>(null)

const form = reactive({
  name:                         '',
  statutory_type:               null as string | null,
  bcea_minimum_days:            null as number | null,
  is_paid:                      true,
  accrual_rate_hours_per_month: null as number | null,
  carry_over_enabled:           false,
  carry_over_max_days:          null as number | null,
  doc_required:                 0,
  notes_required:               0,
  requires_medical_certificate: false,
  is_birthday_leave:            false,
  active:                       true,
})

const statutoryOptions = [
  { title: 'Annual', value: 'annual' },
  { title: 'Sick', value: 'sick' },
  { title: 'Maternity', value: 'maternity' },
  { title: 'Paternity', value: 'paternity' },
  { title: 'Parental', value: 'parental' },
  { title: 'Family Responsibility', value: 'family_responsibility' },
  { title: 'Study', value: 'study' },
  { title: 'Unpaid', value: 'unpaid' },
  { title: 'Special', value: 'special' },
]

function openCreate() {
  editingType.value = null
  Object.assign(form, {
    name: '', statutory_type: null, bcea_minimum_days: null, is_paid: true,
    accrual_rate_hours_per_month: null, carry_over_enabled: false, carry_over_max_days: null,
    doc_required: 0, notes_required: 0, requires_medical_certificate: false,
    is_birthday_leave: false, active: true,
  })
  dialog.value = true
}

function openEdit(lt: LeaveType) {
  editingType.value = lt
  Object.assign(form, {
    name:                         lt.name,
    statutory_type:               lt.statutory_type,
    bcea_minimum_days:            lt.bcea_minimum_days,
    is_paid:                      lt.is_paid,
    accrual_rate_hours_per_month: lt.accrual_rate_hours_per_month,
    carry_over_enabled:           lt.carry_over_enabled,
    carry_over_max_days:          lt.carry_over_max_days,
    doc_required:                 lt.doc_required,
    notes_required:               lt.notes_required,
    requires_medical_certificate: lt.requires_medical_certificate,
    is_birthday_leave:            lt.is_birthday_leave,
    active:                       lt.active,
  })
  dialog.value = true
}

function submitForm() {
  submitting.value = true
  if (editingType.value) {
    router.patch(route('hr.leave.setup.leave-types.update', { leaveType: editingType.value.id }), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  } else {
    router.post(route('hr.leave.setup.leave-types.store'), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  }
}

const headers = [
  { title: 'Name',          key: 'name',            sortable: false },
  { title: 'Statutory',     key: 'statutory_type',  sortable: false },
  { title: 'Paid',          key: 'is_paid',         sortable: false, align: 'center' as const },
  { title: 'BCEA Min Days', key: 'bcea_minimum_days', sortable: false, align: 'center' as const },
  { title: 'Accrual/mo',   key: 'accrual_rate_hours_per_month', sortable: false, align: 'center' as const },
  { title: 'Carry Over',   key: 'carry_over_enabled', sortable: false, align: 'center' as const },
  { title: 'Active',        key: 'active',          sortable: false, align: 'center' as const },
  { title: '',              key: 'actions',         sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">Leave Types</h4>
          <p class="text-medium-emphasis mb-0">Configure leave types and BCEA statutory settings</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">Add Leave Type</VBtn>
    </div>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="leaveTypes" hover :items-per-page="50" hide-default-footer>
        <template #item.statutory_type="{ item }">
          <VChip v-if="item.statutory_type" color="info" size="x-small" variant="tonal" class="text-capitalize">
            {{ item.statutory_type.replace('_', ' ') }}
          </VChip>
          <span v-else class="text-caption text-medium-emphasis">custom</span>
        </template>
        <template #item.is_paid="{ item }">
          <VIcon :icon="item.is_paid ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.is_paid ? 'success' : 'error'" size="18" />
        </template>
        <template #item.bcea_minimum_days="{ item }">
          <span class="text-body-2">{{ item.bcea_minimum_days ?? '—' }}</span>
        </template>
        <template #item.accrual_rate_hours_per_month="{ item }">
          <span class="text-body-2">{{ item.accrual_rate_hours_per_month ? (item.accrual_rate_hours_per_month / 8).toFixed(2) + 'd' : '—' }}</span>
        </template>
        <template #item.carry_over_enabled="{ item }">
          <VIcon :icon="item.carry_over_enabled ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.carry_over_enabled ? 'success' : 'secondary'" size="18" />
        </template>
        <template #item.active="{ item }">
          <VChip :color="item.active ? 'success' : 'secondary'" size="x-small" variant="tonal">{{ item.active ? 'Active' : 'Inactive' }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-edit" size="small" variant="text" color="primary" :disabled="item.prevent_deletion" @click="openEdit(item)" />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-tags" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No leave types configured.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create/Edit Dialog -->
    <VDialog v-model="dialog" max-width="640" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingType ? 'Edit Leave Type' : 'New Leave Type' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.name" label="Name *" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.statutory_type"
                :items="statutoryOptions"
                item-title="title"
                item-value="value"
                label="Statutory Type"
                variant="outlined"
                density="compact"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.bcea_minimum_days" label="BCEA Min Days" type="number" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.accrual_rate_hours_per_month" label="Accrual (hrs/month)" type="number" variant="outlined" density="compact" :step="0.5" />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField v-model="form.carry_over_max_days" label="Max Carry-Over (days)" type="number" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" sm="3">
              <VCheckbox v-model="form.is_paid" label="Paid" hide-details density="compact" />
            </VCol>
            <VCol cols="6" sm="3">
              <VCheckbox v-model="form.carry_over_enabled" label="Carry Over" hide-details density="compact" />
            </VCol>
            <VCol cols="6" sm="3">
              <VCheckbox v-model="form.requires_medical_certificate" label="Needs Med. Cert." hide-details density="compact" />
            </VCol>
            <VCol cols="6" sm="3">
              <VCheckbox v-model="form.is_birthday_leave" label="Birthday Leave" hide-details density="compact" />
            </VCol>
            <VCol cols="6" sm="3">
              <VCheckbox v-model="form.active" label="Active" hide-details density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="submitting" :disabled="!form.name" @click="submitForm">
            {{ editingType ? 'Save Changes' : 'Create' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
