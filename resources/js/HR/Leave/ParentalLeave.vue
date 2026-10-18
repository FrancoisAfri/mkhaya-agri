<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Pool = {
  id: number
  primary_parent_name: string
  secondary_parent_name: string | null
  child_dob: string
  total_pool_hours: number
  primary_hours_used: number
  secondary_hours_used: number
  primary_lock_until: string | null
  status: string
  legislation_version: string
  uif_registered: boolean
  uif_reference: string | null
  approved_by_name: string | null
  approved_at: string | null
}

type Employee = { id: number; full_name: string }

const props = defineProps<{
  pools: Pool[]
  employees: Employee[]
  canManage: boolean
  hrPersonId: number | null
}>()

const createDialog = ref(false)
const selectedPool = ref<Pool | null>(null)
const detailDialog = ref(false)

const createForm = useForm({
  primary_parent_id:   null as number | null,
  secondary_parent_id: null as number | null,
  child_dob:           '',
  primary_weeks:       10,
  secondary_weeks:     6,
  legislation_version: 'VanWyk2025',
  uif_registered:      false,
  uif_reference:       '',
})

const submitCreate = () => {
  createForm.post('/leave-management/parental-leave', {
    onSuccess: () => { createDialog.value = false; createForm.reset() },
  })
}

const openDetail = (pool: Pool) => { selectedPool.value = pool; detailDialog.value = true }

const hoursUsed = (pool: Pool) => pool.primary_hours_used + pool.secondary_hours_used
const hoursRemaining = (pool: Pool) => pool.total_pool_hours - hoursUsed(pool)
const progressPct = (pool: Pool) => Math.min(100, (hoursUsed(pool) / pool.total_pool_hours) * 100)
const progressColor = (pool: Pool) => {
  const pct = progressPct(pool)
  if (pct >= 90) return 'error'
  if (pct >= 70) return 'warning'
  return 'success'
}

const lockStatus = (pool: Pool) => {
  if (!pool.primary_lock_until) return null
  const today = new Date().toISOString().slice(0, 10)
  if (pool.primary_lock_until > today) return { locked: true, until: pool.primary_lock_until }
  return { locked: false, until: pool.primary_lock_until }
}

const statusColor = (s: string) => ({
  active: 'success', expired: 'secondary', cancelled: 'error', pending_approval: 'warning',
}[s] ?? 'secondary')

const approveLoading = ref(false)
const approvePool = (poolId: number) => {
  approveLoading.value = true
  router.post(`/leave-management/parental-leave/${poolId}/approve`, {}, {
    onFinish: () => { approveLoading.value = false },
    onSuccess: () => { detailDialog.value = false },
  })
}

const toDays = (h: number) => (h / 8).toFixed(1)

const { t } = useI18n()

const primaryWeeksHours  = computed(() => createForm.primary_weeks * 40)
const secondaryWeeksHours = computed(() => createForm.secondary_weeks * 40)
const totalPoolHours = computed(() => primaryWeeksHours.value + secondaryWeeksHours.value)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
              <h4 class="text-h4">{{ t('leave.parental_leave') }}</h4>
              <p class="text-medium-emphasis mb-0 text-caption">Van Wyk Constitutional Court 2025 — shared parental pool</p>
            </div>
            <VBtn v-if="canManage" color="primary" prepend-icon="ri-add-line" @click="createDialog = true">
              {{ t('leave.new_pool') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Info banner -->
      <VCol cols="12">
        <VAlert type="info" variant="tonal" icon="ri-scales-line">
          <strong>VanWyk2025 Ruling:</strong> Primary parent has a mandatory 6-week non-transferable lock period.
          Remaining pool weeks can be shared between both parents as agreed.
          UIF benefit offsets apply when registered.
        </VAlert>
      </VCol>

      <!-- Pool cards -->
      <VCol v-for="pool in pools" :key="pool.id" cols="12" md="6">
        <VCard class="cursor-pointer" @click="openDetail(pool)">
          <VCardText class="pa-4">
            <div class="d-flex justify-space-between align-start mb-3">
              <div>
                <div class="text-subtitle-1 font-weight-medium">{{ pool.primary_parent_name }}</div>
                <div v-if="pool.secondary_parent_name" class="text-caption text-medium-emphasis">
                  + {{ pool.secondary_parent_name }}
                </div>
              </div>
              <VChip :color="statusColor(pool.status)" size="small" label>{{ pool.status }}</VChip>
            </div>

            <!-- 6-week lock banner -->
            <VAlert
              v-if="lockStatus(pool)?.locked"
              type="warning"
              variant="tonal"
              density="compact"
              icon="ri-lock-line"
              class="mb-3"
            >
              Primary lock until {{ lockStatus(pool)!.until }}
            </VAlert>

            <div class="text-caption text-medium-emphasis mb-1">
              Child DOB: {{ pool.child_dob }} · Legislation: {{ pool.legislation_version }}
            </div>

            <!-- Usage bar -->
            <div class="d-flex justify-space-between text-caption text-medium-emphasis mb-1">
              <span>{{ toDays(hoursUsed(pool)) }} days used</span>
              <span>{{ toDays(hoursRemaining(pool)) }} days remaining</span>
            </div>
            <VProgressLinear
              :model-value="progressPct(pool)"
              :color="progressColor(pool)"
              rounded
              height="6"
              class="mb-3"
            />

            <!-- Split stats -->
            <div class="d-flex gap-4">
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Primary</div>
                <div class="text-body-2 font-weight-medium">{{ toDays(pool.primary_hours_used) }}d</div>
              </div>
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Secondary</div>
                <div class="text-body-2 font-weight-medium">{{ toDays(pool.secondary_hours_used) }}d</div>
              </div>
              <div class="text-center">
                <div class="text-caption text-medium-emphasis">Pool Total</div>
                <div class="text-body-2 font-weight-medium">{{ toDays(pool.total_pool_hours) }}d</div>
              </div>
              <div v-if="pool.uif_registered" class="text-center">
                <div class="text-caption text-medium-emphasis">UIF</div>
                <VIcon icon="ri-checkbox-circle-line" color="success" size="18" />
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!pools.length" cols="12">
        <VCard>
          <VCardText class="text-center py-10 text-medium-emphasis">
            <VIcon icon="ri-parent-line" size="40" class="mb-2 d-block" />
            No parental leave pools found.
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Detail dialog -->
    <VDialog v-model="detailDialog" max-width="560">
      <VCard v-if="selectedPool">
        <VCardTitle class="pa-4">Parental Leave Pool — {{ selectedPool.primary_parent_name }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTable density="compact">
            <tbody>
              <tr><td class="text-medium-emphasis w-50">{{ t('leave.primary_parent') }}</td><td class="font-weight-medium">{{ selectedPool.primary_parent_name }}</td></tr>
              <tr v-if="selectedPool.secondary_parent_name"><td class="text-medium-emphasis">{{ t('leave.secondary_parent') }}</td><td>{{ selectedPool.secondary_parent_name }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.child_dob') }}</td><td>{{ selectedPool.child_dob }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.legislation') }}</td><td>{{ selectedPool.legislation_version }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.primary_lock_until') }}</td><td>{{ selectedPool.primary_lock_until ?? '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.total_pool') }}</td><td>{{ toDays(selectedPool.total_pool_hours) }} days ({{ selectedPool.total_pool_hours }}h)</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.primary_used') }}</td><td>{{ toDays(selectedPool.primary_hours_used) }} days</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.secondary_used') }}</td><td>{{ toDays(selectedPool.secondary_hours_used) }} days</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.remaining') }}</td><td class="font-weight-medium">{{ toDays(hoursRemaining(selectedPool)) }} days</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave.uif_registered') }}</td><td>{{ selectedPool.uif_registered ? 'Yes' : 'No' }}</td></tr>
              <tr v-if="selectedPool.uif_reference"><td class="text-medium-emphasis">{{ t('leave.uif_reference') }}</td><td>{{ selectedPool.uif_reference }}</td></tr>
              <tr v-if="selectedPool.approved_by_name"><td class="text-medium-emphasis">{{ t('leave.approved_by') }}</td><td>{{ selectedPool.approved_by_name }} · {{ selectedPool.approved_at }}</td></tr>
            </tbody>
          </VTable>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="detailDialog = false">{{ t('leave.close') }}</VBtn>
          <VBtn
            v-if="canManage && selectedPool?.status === 'pending'"
            color="success"
            :loading="approveLoading"
            prepend-icon="ri-checkbox-circle-line"
            @click="approvePool(selectedPool!.id)"
          >
            {{ t('leave.approve_pool') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Create dialog -->
    <VDialog v-model="createDialog" max-width="600">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.create_pool') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            VanWyk2025: Primary receives 6-week exclusive lock. Pool total = primary + secondary weeks combined.
          </VAlert>
          <VRow>
            <VCol cols="12" sm="6">
              <VAutocomplete
                v-model="createForm.primary_parent_id"
                :items="employees"
                item-title="full_name"
                item-value="id"
                :label="t('leave.primary_parent') + ' *'"
                :error-messages="createForm.errors.primary_parent_id"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VAutocomplete
                v-model="createForm.secondary_parent_id"
                :items="employees"
                item-title="full_name"
                item-value="id"
                :label="t('leave.secondary_parent')"
                :error-messages="createForm.errors.secondary_parent_id"
                variant="outlined"
                density="comfortable"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.child_dob"
                :label="t('leave.child_dob') + ' *'"
                type="date"
                :error-messages="createForm.errors.child_dob"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField
                v-model.number="createForm.primary_weeks"
                :label="t('leave.primary_weeks')"
                type="number"
                min="6"
                max="52"
                :hint="`${primaryWeeksHours}h`"
                persistent-hint
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField
                v-model.number="createForm.secondary_weeks"
                :label="t('leave.secondary_weeks')"
                type="number"
                min="0"
                max="52"
                :hint="`${secondaryWeeksHours}h`"
                persistent-hint
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VAlert variant="tonal" density="compact" color="secondary">
                Total pool: <strong>{{ (totalPoolHours / 8).toFixed(0) }} days ({{ totalPoolHours }}h)</strong>
              </VAlert>
            </VCol>
            <VCol cols="12" sm="6">
              <VSwitch
                v-model="createForm.uif_registered"
                label="UIF Registered"
                color="primary"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol v-if="createForm.uif_registered" cols="12" sm="6">
              <VTextField
                v-model="createForm.uif_reference"
                label="UIF Reference"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="createDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="createForm.processing"
            :disabled="!createForm.primary_parent_id || !createForm.child_dob"
            @click="submitCreate"
          >
            {{ t('leave.create_pool') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
