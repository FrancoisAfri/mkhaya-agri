<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Allocation = {
  id: number
  leave_type_id: number
  leave_type_name: string
  default_hours: number
  min_hours: number
  max_hours: number
  carry_over_max_hours: number | null
  accrual_rate_hours_per_month: number | null
  allow_negative_balance: boolean
}

type LeaveProfile = {
  id: number
  name: string
  description: string | null
  status: boolean
  employees_count: number
  allocations: Allocation[]
}

type LeaveType = { id: number; name: string }

const props = defineProps<{ profiles: LeaveProfile[]; leaveTypes: LeaveType[] }>()

const confirmDialog = ref()

const profileDrawer     = ref(false)
const allocationDrawer  = ref(false)
const editProfile       = ref<LeaveProfile | null>(null)
const activeProfile     = ref<LeaveProfile | null>(null)
const editAllocation    = ref<Allocation | null>(null)

const profileForm = useForm({ name: '', description: '', status: true })
const allocForm   = useForm({
  leave_type_id:              null as number | null,
  default_days:               10,
  min_days:                   0,
  max_days:                   20,
  carry_over_max_days:        null as number | null,
  accrual_rate_days_per_month: null as number | null,
  allow_negative_balance:     false,
})

const openCreateProfile = () => {
  editProfile.value = null
  profileForm.reset()
  profileDrawer.value = true
}

const openEditProfile = (p: LeaveProfile) => {
  editProfile.value = p
  profileForm.name        = p.name
  profileForm.description = p.description ?? ''
  profileForm.status      = p.status
  profileDrawer.value = true
}

const submitProfile = () => {
  if (editProfile.value) {
    profileForm.patch(`/leave-management/setup/profiles/${editProfile.value.id}`, {
      onSuccess: () => { profileDrawer.value = false; profileForm.reset() },
    })
  } else {
    profileForm.post('/leave-management/setup/profiles', {
      onSuccess: () => { profileDrawer.value = false; profileForm.reset() },
    })
  }
}

const openAllocations = (p: LeaveProfile) => { activeProfile.value = p }

const H = 8  // hours per day

const openAddAllocation = () => {
  editAllocation.value = null
  allocForm.reset()
  allocForm.default_days = 10
  allocForm.min_days = 0
  allocForm.max_days = 20
  allocationDrawer.value = true
}

const openEditAllocation = (a: Allocation) => {
  editAllocation.value = a
  allocForm.leave_type_id              = a.leave_type_id
  allocForm.default_days               = Math.round(a.default_hours / H * 100) / 100
  allocForm.min_days                   = Math.round(a.min_hours / H * 100) / 100
  allocForm.max_days                   = Math.round(a.max_hours / H * 100) / 100
  allocForm.carry_over_max_days        = a.carry_over_max_hours !== null ? Math.round(a.carry_over_max_hours / H * 100) / 100 : null
  allocForm.accrual_rate_days_per_month = a.accrual_rate_hours_per_month !== null ? Math.round(a.accrual_rate_hours_per_month / H * 100) / 100 : null
  allocForm.allow_negative_balance     = a.allow_negative_balance
  allocationDrawer.value = true
}

const submitAllocation = () => {
  if (!activeProfile.value) return
  const transformed = allocForm.transform(d => ({
    leave_type_id:                d.leave_type_id,
    default_hours:                (d.default_days ?? 0) * H,
    min_hours:                    (d.min_days ?? 0) * H,
    max_hours:                    (d.max_days ?? 0) * H,
    carry_over_max_hours:         d.carry_over_max_days !== null ? d.carry_over_max_days * H : null,
    accrual_rate_hours_per_month: d.accrual_rate_days_per_month !== null ? d.accrual_rate_days_per_month * H : null,
    allow_negative_balance:       d.allow_negative_balance,
  }))
  if (editAllocation.value) {
    transformed.patch(`/leave-management/setup/profiles/${activeProfile.value.id}/allocations/${editAllocation.value.id}`, {
      onSuccess: () => { allocationDrawer.value = false; allocForm.reset() },
    })
  } else {
    transformed.post(`/leave-management/setup/profiles/${activeProfile.value.id}/allocations`, {
      onSuccess: () => { allocationDrawer.value = false; allocForm.reset() },
    })
  }
}

const deleteAllocation = (a: Allocation) => {
  if (!activeProfile.value) return
  confirmDialog.value.open(
    { title: 'Remove allocation?', message: `Remove ${a.leave_type_name} from this profile?`, confirmText: 'Remove', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      useForm({}).delete(`/leave-management/setup/profiles/${activeProfile.value!.id}/allocations/${a.id}`)
    },
  )
}

const toDays = (h: number) => (h / 8).toFixed(0)

const availableLeaveTypes = computed(() => {
  if (!activeProfile.value) return props.leaveTypes
  const usedIds = activeProfile.value.allocations.map(a => a.leave_type_id)
  return props.leaveTypes.filter(lt =>
    !usedIds.includes(lt.id) || (editAllocation.value?.leave_type_id === lt.id),
  )
})
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
              <h4 class="text-h4">{{ t('leave.leave_profiles') }}</h4>
            </div>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreateProfile">{{ t('leave.new_profile') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Profiles list -->
      <VCol cols="12" md="activeProfile ? 5 : 12">
        <VRow>
          <VCol
            v-for="p in profiles"
            :key="p.id"
            :cols="12"
            :md="activeProfile ? 12 : 6"
            :lg="activeProfile ? 12 : 4"
          >
            <VCard
              :class="['cursor-pointer', activeProfile?.id === p.id ? 'border-primary' : '']"
              @click="openAllocations(p)"
            >
              <VCardText class="pa-4">
                <div class="d-flex justify-space-between align-start mb-2">
                  <div class="font-weight-medium">{{ p.name }}</div>
                  <VChip :color="p.status ? 'success' : 'secondary'" size="x-small" label>
                    {{ p.status ? 'Active' : 'Inactive' }}
                  </VChip>
                </div>
                <p v-if="p.description" class="text-caption text-medium-emphasis mb-2">{{ p.description }}</p>
                <div class="d-flex gap-3 text-caption text-medium-emphasis">
                  <span><VIcon icon="tabler-account-multiple" size="14" /> {{ p.employees_count }} employees</span>
                  <span><VIcon icon="tabler-format-list-checks" size="14" /> {{ p.allocations.length }} leave types</span>
                </div>
              </VCardText>
              <VCardActions class="pa-3 pt-0">
                <VBtn size="x-small" variant="text" color="primary" @click.stop="openEditProfile(p)">{{ t('leave.edit_profile') }}</VBtn>
              </VCardActions>
            </VCard>
          </VCol>
          <VCol v-if="!profiles.length" cols="12">
            <VCard>
              <VCardText class="text-center py-10 text-medium-emphasis">No profiles yet.</VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCol>

      <!-- Allocations panel -->
      <VCol v-if="activeProfile" cols="12" md="7">
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-subtitle-1">{{ activeProfile.name }} — Allocations</span>
            <div class="d-flex gap-1">
              <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openAddAllocation">
                Add
              </VBtn>
              <VBtn icon size="small" variant="text" @click="activeProfile = null">
                <i class="fa-solid fa-xmark" />
              </VBtn>
            </div>
          </VCardTitle>
          <VDivider />
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>Leave Type</th>
                <th>Default</th>
                <th>Min</th>
                <th>Max</th>
                <th>Carry Over</th>
                <th>Neg Bal</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!activeProfile.allocations.length">
                <td colspan="7" class="text-center py-8 text-medium-emphasis">No allocations. Add a leave type above.</td>
              </tr>
              <tr v-for="a in activeProfile.allocations" :key="a.id">
                <td class="font-weight-medium">{{ a.leave_type_name }}</td>
                <td>{{ toDays(a.default_hours) }}d</td>
                <td>{{ toDays(a.min_hours) }}d</td>
                <td>{{ toDays(a.max_hours) }}d</td>
                <td>{{ a.carry_over_max_hours ? `${toDays(a.carry_over_max_hours)}d` : '—' }}</td>
                <td>
                  <VIcon
                    :icon="a.allow_negative_balance ? 'tabler-check-circle' : 'tabler-close-circle'"
                    :color="a.allow_negative_balance ? 'warning' : 'secondary'"
                    size="16"
                  />
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn icon size="x-small" variant="text" @click="openEditAllocation(a)">
                      <i class="fa-solid fa-pen-to-square" style="font-size:12px" />
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="deleteAllocation(a)">
                      <i class="fa-solid fa-trash" style="font-size:12px" />
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Profile create/edit drawer -->
    <VNavigationDrawer v-model="profileDrawer" temporary location="right" width="400">
      <div class="pa-4 d-flex align-center justify-space-between border-b">
        <span class="text-subtitle-1 font-weight-medium">{{ editProfile ? t('leave.edit_profile') : t('leave.new_profile') }}</span>
        <VBtn icon variant="text" size="small" @click="profileDrawer = false">
          <i class="fa-solid fa-xmark" />
        </VBtn>
      </div>
      <div class="pa-4">
        <VTextField v-model="profileForm.name" :label=" t('name') " :error-messages="profileForm.errors.name" variant="outlined" density="comfortable" class="mb-4" />
        <VTextarea v-model="profileForm.description" :label="t('description')" variant="outlined" rows="2" density="comfortable" class="mb-4" />
        <VSwitch v-model="profileForm.status" label="Active" color="success" density="compact" hide-details class="mb-6" />
        <div class="d-flex gap-3 justify-end">
          <VBtn variant="tonal" @click="profileDrawer = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="primary" :loading="profileForm.processing" @click="submitProfile">{{ t('leave.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Allocation create/edit drawer -->
    <VNavigationDrawer v-model="allocationDrawer" temporary location="right" width="420">
      <div class="pa-4 d-flex align-center justify-space-between border-b">
        <span class="text-subtitle-1 font-weight-medium">{{ editAllocation ? 'Edit Allocation' : 'Add Allocation' }}</span>
        <VBtn icon variant="text" size="small" @click="allocationDrawer = false">
          <i class="fa-solid fa-xmark" />
        </VBtn>
      </div>
      <div class="pa-4">
        <VSelect
            v-model="allocForm.leave_type_id"
            :items="availableLeaveTypes"
            item-title="name"
            item-value="id"
            label="Leave Type *"
            :disabled="!!editAllocation"
            :error-messages="allocForm.errors.leave_type_id"
            variant="outlined"
            density="comfortable"
            class="mb-4"
          />
          <VRow dense>
            <VCol cols="4">
              <VTextField v-model.number="allocForm.default_days" label="Default (days)" type="number" min="0" step="0.5" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model.number="allocForm.min_days" label="Min (days)" type="number" min="0" step="0.5" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model.number="allocForm.max_days" label="Max (days)" type="number" min="0" step="0.5" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="allocForm.carry_over_max_days" label="Carry Over Max (days)" type="number" min="0" step="0.5" variant="outlined" density="comfortable" clearable />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="allocForm.accrual_rate_days_per_month" label="Accrual (days/mo)" type="number" step="0.5" min="0" variant="outlined" density="comfortable" clearable />
            </VCol>
          </VRow>
          <VSwitch v-model="allocForm.allow_negative_balance" label="Allow Negative Balance" color="warning" density="compact" hide-details class="mb-6" />
          <div class="d-flex gap-3 justify-end">
            <VBtn variant="tonal" @click="allocationDrawer = false">{{ t('leave.cancel') }}</VBtn>
            <VBtn color="primary" :loading="allocForm.processing" :disabled="!allocForm.leave_type_id" @click="submitAllocation">{{ t('leave.save') }}</VBtn>
          </div>
      </div>
    </VNavigationDrawer>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
