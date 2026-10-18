<script setup lang="ts">
import { ref, computed } from 'vue'
import { hoursToDay } from './utils/leaveTime'
import { useI18n } from 'vue-i18n'

type Balance = {
  leave_type_id: number; leave_type_name: string
  balance_hours: number; balance_days: number
  actual_balance_hours: number; actual_balance_days: number
  future_committed_hours: number; future_committed_days: number
}
type Transaction = {
  date: string; type: string; hours: number; balance: number; description: string | null
}
type Breakdown = {
  opening: number; allocated: number; carryover: number
  consumed: number; reserved: number; payout: number; forfeiture: number; adjusted: number
}
type Cycle = {
  name: string; start_date: string; end_date: string; progress: number
}

const props = defineProps<{
  balances:     Balance[]
  transactions: Record<number, Transaction[]>
  breakdowns:   Record<number, Breakdown>
  cycle:        Cycle | null
  hasNegative:  boolean
}>()

const tab          = ref<'balances' | 'history'>('balances')
const expandedType = ref<number | null>(null)

const negativeBalances = computed(() => props.balances.filter(b => b.balance_hours < 0))

const balanceColor = (hours: number) => {
  if (hours < 0)  return 'error'
  if (hours < 16) return 'warning'
  return 'success'
}

const hoursColor = (h: number) => h > 0 ? 'text-success' : h < 0 ? 'text-error' : 'text-medium-emphasis'

const cycleProgressColor = (p: number) => {
  if (p >= 90) return 'error'
  if (p >= 70) return 'warning'
  return 'primary'
}

const breakdownItems = (id: number) => {
  const b = props.breakdowns[id]
  if (!b) return []
  return [
    { label: 'Opening balance', hours: b.opening,   color: 'secondary' },
    { label: 'Allocated',       hours: b.allocated, color: 'success' },
    { label: 'Carry-over',      hours: b.carryover, color: 'info' },
    { label: 'Consumed',        hours: -b.consumed, color: 'error' },
    { label: 'Reserved',        hours: -b.reserved, color: 'warning' },
    { label: 'Paid out',        hours: -b.payout,   color: 'error' },
    { label: 'Forfeited',       hours: -b.forfeiture, color: 'error' },
    { label: 'Adjustments',     hours: b.adjusted,  color: 'secondary' },
  ].filter(i => i.hours !== 0)
}

const { t } = useI18n()

const allTransactions = computed(() => {
  const rows: (Transaction & { leave_type_name: string })[] = []
  for (const b of props.balances) {
    const txns = props.transactions[b.leave_type_id] ?? []
    for (const t of txns) {
      rows.push({ ...t, leave_type_name: b.leave_type_name })
    }
  }
  return rows.sort((a, b) => (b.date > a.date ? 1 : -1))
})
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="py-5">
            <div class="d-flex flex-wrap align-center justify-space-between gap-3">
              <div>
                <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
                <h4 class="text-h4">{{ t('leave.my_leave_balance') }}</h4>
                <p v-if="cycle" class="mb-0 text-medium-emphasis text-body-2">
                  Cycle: <strong>{{ cycle.name }}</strong>
                  &nbsp;·&nbsp; {{ cycle.start_date }} – {{ cycle.end_date }}
                </p>
              </div>
              <VTabs v-model="tab" density="compact" color="primary">
                <VTab value="balances" prepend-icon="tabler-scale-balance">{{ t('leave.my_balances') }}</VTab>
                <VTab value="history"  prepend-icon="tabler-history">{{ t('leave.history') }}</VTab>
              </VTabs>
            </div>

            <!-- Cycle progress bar -->
            <template v-if="cycle">
              <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-4 mb-1">
                <span>{{ t('leave.cycle_start') }}</span>
                <span>{{ cycle.progress }}% {{ t('leave.elapsed') }}</span>
                <span>{{ t('leave.cycle_end') }}</span>
              </div>
              <VProgressLinear
                :model-value="cycle.progress"
                :color="cycleProgressColor(cycle.progress)"
                rounded height="8"
              />
            </template>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Negative balance alert -->
      <VCol v-if="hasNegative" cols="12">
        <VAlert type="error" variant="tonal" density="compact" icon="tabler-alert-circle" class="mb-0">
          <strong>Negative leave balance detected</strong> on
          {{ negativeBalances.map(b => b.leave_type_name).join(', ') }}.
          Contact HR to discuss repayment or adjust your upcoming requests.
        </VAlert>
      </VCol>

      <VTabsWindow v-model="tab" class="w-100">
        <!-- ── Balances tab ── -->
        <VTabsWindowItem value="balances">
          <VRow class="mt-0">
            <!-- Balance cards -->
            <template v-if="balances.length">
              <VCol v-for="b in balances" :key="b.leave_type_id" cols="12" sm="6" md="4">
                <VCard
                  :class="['cursor-pointer h-100', expandedType === b.leave_type_id ? 'border border-primary' : '']"
                  :variant="expandedType === b.leave_type_id ? 'outlined' : 'elevated'"
                  @click="expandedType = expandedType === b.leave_type_id ? null : b.leave_type_id"
                >
                  <VCardText class="pa-4">
                    <div class="d-flex justify-space-between align-start mb-3">
                      <div class="text-subtitle-1 font-weight-medium">{{ b.leave_type_name }}</div>
                      <VChip :color="balanceColor(b.balance_hours)" size="small" label>
                        {{ b.balance_hours < 0 ? '−' : '' }}{{ Math.abs(b.balance_days) }}d
                      </VChip>
                    </div>

                    <!-- Current Balance (primary) -->
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('leave.current_balance') }}</div>
                    <div class="d-flex align-end gap-1 mb-1">
                      <span
                        class="text-h4 font-weight-bold"
                        :class="b.balance_hours < 0 ? 'text-error' : 'text-high-emphasis'"
                      >
                        {{ b.balance_hours < 0 ? '−' : '' }}{{ Math.abs(b.balance_days) }}
                      </span>
                      <span class="text-medium-emphasis mb-1">{{ t('leave.days_label') }}</span>
                    </div>

                    <!-- Actual Balance (shows when there are future committed leaves) -->
                    <div
                      v-if="b.future_committed_hours > 0"
                      class="d-flex align-center gap-2 mb-3"
                    >
                      <div class="text-caption text-medium-emphasis">{{ t('leave.actual_balance') }}:</div>
                      <VChip color="info" size="x-small" label>
                        {{ b.actual_balance_days }}d
                      </VChip>
                      <div class="text-caption text-medium-emphasis">
                        (includes {{ b.future_committed_days }}d future leave)
                      </div>
                    </div>
                    <div v-else class="mb-3" />

                    <VProgressLinear
                      :model-value="Math.max(0, Math.min(100, (b.balance_days / 20) * 100))"
                      :color="balanceColor(b.balance_hours)"
                      rounded height="4"
                    />

                    <div class="d-flex align-center gap-1 mt-2 text-caption text-medium-emphasis">
                      <VIcon icon="tabler-chevron-down" size="14" />
                      {{ t('leave.allocation_breakdown') }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </template>

            <VCol v-else cols="12">
              <VCard>
                <VCardText class="text-center py-10 text-medium-emphasis">
                  {{ t('leave.no_balance_data') }}
                </VCardText>
              </VCard>
            </VCol>

            <!-- Allocation breakdown + transaction drawer -->
            <VCol v-if="expandedType" cols="12">
              <VCard>
                <VCardTitle class="pa-4 pb-2 text-subtitle-1 d-flex align-center gap-2">
                  <VIcon icon="tabler-chart-pie" />
                  {{ balances.find(b => b.leave_type_id === expandedType)?.leave_type_name }}
                  — {{ t('leave.allocation_breakdown') }}
                  <VSpacer />
                  <VBtn icon size="small" variant="text" @click="expandedType = null">
                    <VIcon icon="tabler-close" />
                  </VBtn>
                </VCardTitle>
                <VDivider />

                <VCardText class="pa-4">
                  <VRow>
                    <!-- Breakdown chips -->
                    <VCol cols="12" md="5">
                      <div class="text-caption text-medium-emphasis mb-3 font-weight-medium text-uppercase">
                        This Cycle
                      </div>
                      <div class="d-flex flex-column gap-2">
                        <div
                          v-for="item in breakdownItems(expandedType)"
                          :key="item.label"
                          class="d-flex justify-space-between align-center"
                        >
                          <span class="text-body-2">{{ item.label }}</span>
                          <VChip
                            :color="item.color"
                            size="small"
                            label
                            variant="tonal"
                          >
                            {{ item.hours > 0 ? '+' : '' }}{{ hoursToDay(item.hours) }}d
                          </VChip>
                        </div>
                      </div>
                    </VCol>

                    <VDivider vertical class="d-none d-md-block" />

                    <!-- Recent transactions -->
                    <VCol cols="12" md="7">
                      <div class="text-caption text-medium-emphasis mb-3 font-weight-medium text-uppercase">
                        Recent Transactions
                      </div>
                      <VTable density="compact" hover>
                        <thead>
                          <tr>
                            <th>{{ t('leave.date_label') }}</th>
                            <th>{{ t('leave.transaction_type') }}</th>
                            <th>{{ t('leave.days') }}</th>
                            <th>{{ t('leave.balance_label') }}</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-if="!(transactions[expandedType]?.length)">
                            <td colspan="4" class="text-center py-4 text-medium-emphasis">{{ t('leave.no_transactions') }}</td>
                          </tr>
                          <tr v-for="(tx, i) in transactions[expandedType]?.slice(0, 8)" :key="i">
                            <td class="text-caption text-no-wrap">{{ tx.date }}</td>
                            <td>
                              <VChip size="x-small" :color="tx.hours > 0 ? 'success' : 'error'" label>
                                {{ tx.type }}
                              </VChip>
                            </td>
                            <td :class="hoursColor(tx.hours)" class="font-weight-medium text-caption">
                              {{ tx.hours > 0 ? '+' : '' }}{{ hoursToDay(tx.hours) }}d
                            </td>
                            <td class="text-caption font-weight-medium">{{ hoursToDay(tx.balance) }}d</td>
                          </tr>
                        </tbody>
                      </VTable>
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VTabsWindowItem>

        <!-- ── History tab ── -->
        <VTabsWindowItem value="history">
          <VCard class="mt-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="tabler-history" class="me-2" />{{ t('leave.history') }}
            </VCardTitle>
            <VDivider />
            <VTable hover density="comfortable">
              <thead>
                <tr>
                  <th>{{ t('leave.date_label') }}</th>
                  <th>{{ t('leave.leave_type_label') }}</th>
                  <th>{{ t('leave.transaction_type') }}</th>
                  <th>{{ t('leave.days') }}</th>
                  <th>{{ t('leave.balance_label') }}</th>
                  <th>{{ t('leave.description') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!allTransactions.length">
                  <td colspan="6" class="text-center py-10 text-medium-emphasis">{{ t('leave.no_transactions') }}</td>
                </tr>
                <tr v-for="(tx, i) in allTransactions" :key="i">
                  <td class="text-caption text-no-wrap">{{ tx.date }}</td>
                  <td class="text-caption">{{ tx.leave_type_name }}</td>
                  <td>
                    <VChip size="x-small" :color="tx.hours > 0 ? 'success' : 'error'" label>
                      {{ tx.type }}
                    </VChip>
                  </td>
                  <td :class="hoursColor(tx.hours)" class="font-weight-medium text-caption">
                    {{ tx.hours > 0 ? '+' : '' }}{{ hoursToDay(tx.hours) }}d
                  </td>
                  <td class="text-caption font-weight-medium">{{ hoursToDay(tx.balance) }}d</td>
                  <td class="text-caption text-medium-emphasis" style="max-width:200px">
                    {{ tx.description ?? '—' }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VTabsWindowItem>
      </VTabsWindow>
    </VRow>
  </VContainer>
</template>
