<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Balance = {
  leave_type_id: number
  leave_type_name: string
  statutory_type: string | null
  balance_hours: number | null
  balance_days: number | null
  used_hours: number | null
  pending_hours: number | null
  accrued_hours: number | null
  allocated_hours: number | null
  actual_balance_hours: number | null
}

type Transaction = {
  id: number
  leave_type_name: string
  transaction_type: string
  hours: number
  description: string
  created_at: string
}

type Cycle = { name: string; start_date: string; end_date: string } | null

const props = defineProps<{
  balances: Balance[]
  transactions: Transaction[]
  hasNegative: boolean
  cycle: Cycle
}>()

const activeTab = ref('balances')

const typeColor: Record<string, string> = {
  annual: 'success', sick: 'error', maternity: 'purple', paternity: 'purple',
  parental: 'purple', study: 'info', family_responsibility: 'warning', unpaid: 'secondary',
}

function balanceColor(b: Balance) {
  const days = b.balance_days ?? 0
  if (days < 0) return 'error'
  if (days < 3) return 'warning'
  return typeColor[b.statutory_type ?? ''] ?? 'primary'
}

function formatDays(hours: number | null) {
  if (hours === null) return '—'
  return (hours / 8).toFixed(1) + ' d'
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function txColor(type: string) {
  if (type === 'credit' || type === 'accrual' || type === 'allocation') return 'success'
  if (type === 'debit' || type === 'used') return 'error'
  return 'default'
}

const txHeaders = [
  { title: 'Date', key: 'created_at', sortable: false },
  { title: 'Leave Type', key: 'leave_type_name', sortable: false },
  { title: 'Type', key: 'transaction_type', sortable: false },
  { title: 'Hours', key: 'hours', sortable: false, align: 'end' as const },
  { title: 'Description', key: 'description', sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">My Leave Balances</h4>
          <p class="text-medium-emphasis mb-0">
            {{ cycle ? `Cycle: ${cycle.name} · ${cycle.start_date} to ${cycle.end_date}` : 'No active cycle' }}
          </p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('hr.leave.apply'))">Apply for Leave</VBtn>
    </div>

    <VAlert v-if="hasNegative" type="error" variant="tonal" class="mb-5">
      <template #title>Negative Balance Detected</template>
      One or more leave types show a negative balance. Please contact HR to review your leave records.
    </VAlert>

    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="balances">Balances</VTab>
      <VTab value="transactions">Transaction History</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <!-- Balances Tab -->
      <VWindowItem value="balances">
        <VRow>
          <VCol
            v-for="b in balances"
            :key="b.leave_type_id"
            cols="12"
            sm="6"
            lg="4"
          >
            <VCard elevation="0" border>
              <VCardText class="pa-4">
                <div class="d-flex align-center gap-3 mb-3">
                  <VAvatar :color="balanceColor(b)" variant="tonal" size="44" rounded>
                    <span class="text-body-2 font-weight-bold">{{ (b.leave_type_name ?? '?')[0] }}</span>
                  </VAvatar>
                  <div>
                    <p class="text-body-2 font-weight-semibold mb-0">{{ b.leave_type_name }}</p>
                    <p class="text-caption text-medium-emphasis mb-0 text-capitalize">{{ b.statutory_type ?? 'custom' }}</p>
                  </div>
                </div>

                <div class="d-flex justify-space-between align-center mb-2">
                  <span class="text-caption text-medium-emphasis">Available</span>
                  <span class="text-body-1 font-weight-bold" :class="(b.balance_days ?? 0) < 0 ? 'text-error' : ''">
                    {{ formatDays(b.balance_hours) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="Math.max(0, b.balance_days ?? 0)"
                  :max="Math.max(b.balance_days ?? 0, b.used_hours ? (b.used_hours / 8) : 1, 1)"
                  :color="balanceColor(b)"
                  rounded
                  height="6"
                  class="mb-3"
                />

                <VDivider class="mb-3" />

                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span>Used: {{ formatDays(b.used_hours) }}</span>
                  <span v-if="b.pending_hours">Pending: {{ formatDays(b.pending_hours) }}</span>
                  <span v-if="b.accrued_hours">Accrued: {{ formatDays(b.accrued_hours) }}</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol v-if="!balances.length" cols="12">
            <VCard elevation="0" border>
              <VCardText class="text-center py-12">
                <VIcon icon="tabler-calendar-off" size="56" color="secondary" class="mb-3" />
                <p class="text-body-1 text-medium-emphasis">No leave balances found. Contact HR to set up your leave profile.</p>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- Transaction History Tab -->
      <VWindowItem value="transactions">
        <VCard elevation="0" border>
          <VDataTable
            :headers="txHeaders"
            :items="transactions"
            :items-per-page="25"
            hover
          >
            <template #item.created_at="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
            </template>
            <template #item.transaction_type="{ item }">
              <VChip :color="txColor(item.transaction_type)" size="x-small" variant="tonal" class="text-capitalize">
                {{ item.transaction_type }}
              </VChip>
            </template>
            <template #item.hours="{ item }">
              <span class="font-weight-medium" :class="item.hours >= 0 ? 'text-success' : 'text-error'">
                {{ item.hours >= 0 ? '+' : '' }}{{ (item.hours / 8).toFixed(1) }}d
              </span>
            </template>
            <template #no-data>
              <div class="text-center pa-10">
                <p class="text-body-2 text-medium-emphasis">No transactions recorded.</p>
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

  </VContainer>
</template>
