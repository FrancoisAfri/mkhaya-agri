<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Allocation = {
  id: number
  leave_type_id: number
  leave_type_name: string
  allocated_days: number
  accrual_type: string
}

type Profile = {
  id: number
  name: string
  description: string | null
  employee_count: number
  allocations: Allocation[]
}

type LeaveType = { id: number; name: string }

const props = defineProps<{
  profiles: Profile[]
  leaveTypes: LeaveType[]
}>()

const profileDialog    = ref(false)
const allocationDialog = ref(false)
const editingProfile   = ref<Profile | null>(null)
const selectedProfile  = ref<Profile | null>(null)
const editingAlloc     = ref<Allocation | null>(null)
const submitting       = ref(false)
const confirmDialog    = ref()

const profileForm = reactive({ name: '', description: '' })
const allocForm   = reactive({
  leave_type_id:  null as number | null,
  allocated_days: 15,
  accrual_type:   'fixed',
})

const accrualOptions = [
  { title: 'Fixed (grant at cycle start)', value: 'fixed' },
  { title: 'Monthly accrual', value: 'monthly' },
  { title: 'Anniversary grant', value: 'anniversary' },
]

function openCreateProfile() {
  editingProfile.value = null
  Object.assign(profileForm, { name: '', description: '' })
  profileDialog.value = true
}

function openEditProfile(p: Profile) {
  editingProfile.value = p
  Object.assign(profileForm, { name: p.name, description: p.description ?? '' })
  profileDialog.value = true
}

function submitProfile() {
  submitting.value = true
  if (editingProfile.value) {
    router.patch(route('hr.leave.setup.profiles.update', { profile: editingProfile.value.id }), profileForm, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { profileDialog.value = false },
    })
  } else {
    router.post(route('hr.leave.setup.profiles.store'), profileForm, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { profileDialog.value = false },
    })
  }
}

function openAddAllocation(profile: Profile) {
  selectedProfile.value = profile
  editingAlloc.value = null
  Object.assign(allocForm, { leave_type_id: null, allocated_days: 15, accrual_type: 'fixed' })
  allocationDialog.value = true
}

function openEditAllocation(profile: Profile, alloc: Allocation) {
  selectedProfile.value = profile
  editingAlloc.value    = alloc
  Object.assign(allocForm, {
    leave_type_id:  alloc.leave_type_id,
    allocated_days: alloc.allocated_days,
    accrual_type:   alloc.accrual_type,
  })
  allocationDialog.value = true
}

function submitAllocation() {
  if (!selectedProfile.value) return
  submitting.value = true
  const profileId = selectedProfile.value.id
  if (editingAlloc.value) {
    router.patch(route('hr.leave.setup.profiles.allocations.update', { profile: profileId, allocation: editingAlloc.value.id }), allocForm, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { allocationDialog.value = false },
    })
  } else {
    router.post(route('hr.leave.setup.profiles.allocations.store', { profile: profileId }), allocForm, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { allocationDialog.value = false },
    })
  }
}

function deleteAllocation(profile: Profile, alloc: Allocation) {
  confirmDialog.value.open(
    { title: 'Remove Allocation', message: `Remove ${alloc.leave_type_name} from this profile?`, confirmText: 'Remove', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('hr.leave.setup.profiles.allocations.destroy', { profile: profile.id, allocation: alloc.id }))
    },
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">Leave Profiles</h4>
          <p class="text-medium-emphasis mb-0">Define leave entitlement packages for employee groups</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreateProfile">New Profile</VBtn>
    </div>

    <!-- Profiles -->
    <VRow>
      <VCol
        v-for="profile in profiles"
        :key="profile.id"
        cols="12"
        lg="6"
      >
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <div>
              <p class="text-body-1 font-weight-semibold mb-0">{{ profile.name }}</p>
              <p v-if="profile.description" class="text-caption text-medium-emphasis mb-0">{{ profile.description }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ profile.employee_count }} employee(s) assigned</p>
            </div>
            <div class="d-flex gap-1">
              <VBtn icon="tabler-edit" size="small" variant="text" color="primary" @click="openEditProfile(profile)" />
              <VBtn icon="tabler-plus" size="small" variant="text" color="success" @click="openAddAllocation(profile)" />
            </div>
          </VCardTitle>
          <VDivider />
          <VList v-if="profile.allocations.length" density="compact">
            <VListItem
              v-for="alloc in profile.allocations"
              :key="alloc.id"
              :subtitle="`${alloc.accrual_type} · ${alloc.allocated_days} days`"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ alloc.leave_type_name }}</span>
              </template>
              <template #append>
                <div class="d-flex gap-1">
                  <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEditAllocation(profile, alloc)" />
                  <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteAllocation(profile, alloc)" />
                </div>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-5 text-medium-emphasis">
            <p class="text-body-2 mb-2">No allocations yet.</p>
            <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="openAddAllocation(profile)">Add Leave Type</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!profiles.length" cols="12">
        <VCard elevation="0" border>
          <VCardText class="text-center py-12">
            <VIcon icon="tabler-user-circle" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis mb-4">No profiles yet.</p>
            <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreateProfile">Create First Profile</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Profile Dialog -->
    <VDialog v-model="profileDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingProfile ? 'Edit Profile' : 'New Leave Profile' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="profileForm.name" label="Profile Name *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="profileForm.description" label="Description" variant="outlined" density="comfortable" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="profileDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="submitting" :disabled="!profileForm.name" @click="submitProfile">
            {{ editingProfile ? 'Save' : 'Create' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Allocation Dialog -->
    <VDialog v-model="allocationDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingAlloc ? 'Edit Allocation' : 'Add Leave Allocation' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="allocForm.leave_type_id"
                :items="leaveTypes"
                item-title="name"
                item-value="id"
                label="Leave Type *"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="allocForm.allocated_days"
                label="Days per Cycle"
                type="number"
                variant="outlined"
                density="comfortable"
                :min="0.5"
                :step="0.5"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="allocForm.accrual_type"
                :items="accrualOptions"
                item-title="title"
                item-value="value"
                label="Accrual Type"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="allocationDialog = false">Cancel</VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="submitting"
            :disabled="!allocForm.leave_type_id || !allocForm.allocated_days"
            @click="submitAllocation"
          >
            {{ editingAlloc ? 'Save' : 'Add' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />

  </VContainer>
</template>
