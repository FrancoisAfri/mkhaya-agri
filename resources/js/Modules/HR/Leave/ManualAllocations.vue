<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Employee = {
  id: number
  name: string
  employee_number: string | null
  department: string | null
}

type Balance = {
  leave_type_id: number
  leave_type_name: string
  balance_hours: number | null
  balance_days: number | null
}

const props = defineProps<{
  filters: { q?: string }
}>()

const searchQuery   = ref(props.filters.q ?? '')
const searchResults = ref<Employee[]>([])
const searching     = ref(false)
const selected      = ref<Employee | null>(null)
const balances      = ref<Balance[]>([])
const loadingBalances = ref(false)

const adjustDialog  = ref(false)
const adjustTarget  = ref<Balance | null>(null)
const adjustHours   = ref(0)
const adjustNote    = ref('')
const adjusting     = ref(false)

let searchTimer: ReturnType<typeof setTimeout>

async function doSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    if (!searchQuery.value.trim()) { searchResults.value = []; return }
    searching.value = true
    try {
      const res = await fetch(route('hr.leave.manual-allocations.search') + `?q=${encodeURIComponent(searchQuery.value)}`)
      searchResults.value = await res.json()
    } finally {
      searching.value = false
    }
  }, 350)
}

async function selectEmployee(emp: Employee) {
  selected.value = emp
  searchResults.value = []
  searchQuery.value = emp.name
  loadingBalances.value = true
  try {
    const res = await fetch(route('hr.leave.manual-allocations.balances', { hrPerson: emp.id }))
    balances.value = await res.json()
  } finally {
    loadingBalances.value = false
  }
}

function openAdjust(b: Balance) {
  adjustTarget.value = b
  adjustHours.value  = 0
  adjustNote.value   = ''
  adjustDialog.value = true
}

async function submitAdjust() {
  if (!selected.value || !adjustTarget.value) return
  adjusting.value = true
  try {
    await fetch(route('hr.leave.manual-allocations.adjust'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content ?? '' },
      body: JSON.stringify({
        hr_person_id:   selected.value.id,
        leave_type_id:  adjustTarget.value.leave_type_id,
        hours:          adjustHours.value,
        note:           adjustNote.value,
      }),
    })
    await selectEmployee(selected.value)
    adjustDialog.value = false
  } finally {
    adjusting.value = false
  }
}

function formatDays(hours: number | null) {
  if (hours === null) return '—'
  return (hours / 8).toFixed(1) + ' d'
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 900px;">

    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
      <div>
        <h4 class="text-h4">Manual Leave Allocations</h4>
        <p class="text-medium-emphasis mb-0">Adjust individual employee leave balances</p>
      </div>
    </div>

    <VAlert type="info" variant="tonal" class="mb-6">
      All manual adjustments are recorded in the ledger and attributed to your account.
      Use positive values to credit leave, negative to debit.
    </VAlert>

    <!-- Employee Search -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VTextField
          v-model="searchQuery"
          label="Search employee by name or number…"
          prepend-inner-icon="tabler-search"
          variant="outlined"
          density="comfortable"
          hide-details
          :loading="searching"
          @update:model-value="doSearch"
        />

        <!-- Search Results Dropdown -->
        <VList v-if="searchResults.length" class="mt-2" elevation="0" border style="position: relative; z-index: 10;">
          <VListItem
            v-for="emp in searchResults"
            :key="emp.id"
            :title="emp.name"
            :subtitle="`${emp.employee_number ?? ''} · ${emp.department ?? ''}`"
            class="cursor-pointer"
            @click="selectEmployee(emp)"
          />
        </VList>
      </VCardText>
    </VCard>

    <!-- Selected Employee Balances -->
    <template v-if="selected">
      <VCard elevation="0" border class="mb-2">
        <VCardText class="pa-4 d-flex align-center gap-3">
          <VAvatar color="primary" variant="tonal" size="48" rounded>
            <VIcon icon="tabler-user" size="24" />
          </VAvatar>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ selected.name }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ selected.employee_number }} · {{ selected.department }}</p>
          </div>
        </VCardText>
      </VCard>

      <VCard elevation="0" border>
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Leave Balances</VCardTitle>
        <VDivider />
        <VProgressLinear v-if="loadingBalances" indeterminate color="primary" />
        <VList v-else-if="balances.length">
          <VListItem
            v-for="b in balances"
            :key="b.leave_type_id"
            :subtitle="b.leave_type_name"
          >
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ formatDays(b.balance_hours) }}</span>
            </template>
            <template #prepend>
              <VAvatar
                :color="(b.balance_days ?? 0) < 0 ? 'error' : (b.balance_days ?? 0) < 3 ? 'warning' : 'success'"
                variant="tonal"
                size="36"
                rounded
                class="me-3"
              >
                <VIcon icon="tabler-calendar" size="18" />
              </VAvatar>
            </template>
            <template #append>
              <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-edit" @click="openAdjust(b)">
                Adjust
              </VBtn>
            </template>
          </VListItem>
        </VList>
        <VCardText v-else class="text-center py-8 text-medium-emphasis">
          <p class="text-body-2">No balances found for this employee.</p>
        </VCardText>
      </VCard>
    </template>

    <!-- Adjust Dialog -->
    <VDialog v-model="adjustDialog" max-width="440" persistent>
      <VCard v-if="adjustTarget">
        <VCardTitle class="pa-4 pb-2 text-h6">Adjust Balance — {{ adjustTarget.leave_type_name }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            Current balance: <strong>{{ formatDays(adjustTarget.balance_hours) }}</strong>
          </VAlert>
          <VTextField
            v-model="adjustHours"
            label="Adjustment (hours, + credit / − debit)"
            type="number"
            variant="outlined"
            density="comfortable"
            :step="4"
            class="mb-3"
          />
          <VTextarea
            v-model="adjustNote"
            label="Reason *"
            variant="outlined"
            density="comfortable"
            rows="2"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="adjustDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="adjusting" :disabled="!adjustNote || !adjustHours" @click="submitAdjust">
            Apply Adjustment
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
