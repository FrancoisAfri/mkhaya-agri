<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface FloatData {
  id: number; uuid: string; name: string; description: string | null
  custodian: { id: number; name: string }; current_balance: number; float_limit: number
  opening_balance: number; replenishment_threshold: number | null
  currency: string; status: string; utilization_pct: number
  needs_replenishment: boolean; last_reconciled_at: string | null; notes: string | null; created_at: string
}

interface Transaction {
  id: number; type: string; amount: number; balance_before: number
  balance_after: number; description: string | null; performed_by: string | null; performed_at: string
}

interface PendingRequest {
  id: number; uuid: string; reference_number: string; requestor: string | null
  purpose: string; amount: number; status: string; requested_at: string | null
}

const props = defineProps<{
  float: FloatData
  recentTransactions: Transaction[]
  pendingRequests: PendingRequest[]
  custodians: { id: number; name: string }[]
}>()

const replenishDialog = ref(false)
const replenishForm = useForm({ amount: null as number | null, reference: '' })
const replenish = () => replenishForm.post(route('petty-cash.floats.replenish', props.float.uuid), {
  onSuccess: () => { replenishDialog.value = false; replenishForm.reset() },
})

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const txTypeColor = (type: string) => ({
  issue: 'error', replenishment: 'success', adjustment: 'info', reconciliation_variance: 'warning',
})[type] ?? 'grey'

const txIcon = (type: string) => ({
  issue: 'tabler-cash-off', replenishment: 'tabler-cash', adjustment: 'tabler-cash', reconciliation_variance: 'tabler-scale',
})[type] ?? 'tabler-cash'
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="replenishForm.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.floats.index')" class="mb-3" :disabled="replenishForm.processing">
      {{ t('petty_cash_floats_show.back') }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ float.name }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_floats_show.custodian', { name: float.custodian.name }) }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn v-if="float.needs_replenishment" color="warning" prepend-icon="tabler-cash" :disabled="replenishForm.processing" @click="replenishDialog = true">
          {{ t('petty_cash_floats_show.replenish') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-cash" :disabled="replenishForm.processing" @click="replenishDialog = true">
          {{ t('petty_cash_floats_show.replenish_float') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-clipboard-check" :href="route('petty-cash.reconciliations.create')" :disabled="replenishForm.processing">
          {{ t('petty_cash_floats_show.reconcile') }}
        </VBtn>
      </div>
    </div>

    <!-- Float Metrics -->
    <VRow class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border :color="float.needs_replenishment ? 'error' : ''">
          <VCardText class="pa-4 text-center">
            <VIcon size="36" :color="float.needs_replenishment ? 'white' : 'success'" class="mb-1">tabler-safe</VIcon>
            <div class="text-h5 font-weight-bold">{{ fmt(float.current_balance) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('petty_cash_floats_show.metrics.current_balance') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <VIcon size="36" color="primary" class="mb-1">tabler-cash</VIcon>
            <div class="text-h5 font-weight-bold">{{ fmt(float.float_limit) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('petty_cash_floats_show.metrics.float_limit') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <VIcon size="36" color="warning" class="mb-1">tabler-gauge</VIcon>
            <div class="text-h5 font-weight-bold">{{ float.utilization_pct }}%</div>
            <div class="text-caption text-medium-emphasis">{{ t('petty_cash_floats_show.metrics.utilization') }}</div>
            <VProgressLinear :model-value="float.utilization_pct" :color="float.utilization_pct >= 90 ? 'error' : float.utilization_pct >= 70 ? 'warning' : 'success'" height="6" rounded class="mt-1" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <VIcon size="36" color="info" class="mb-1">tabler-clipboard-check</VIcon>
            <div class="text-h5 font-weight-bold">{{ float.last_reconciled_at ?? t('petty_cash_floats_show.metrics.never') }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('petty_cash_floats_show.metrics.last_reconciled') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Pending Requests -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex justify-space-between align-center">
            <span>{{ t('petty_cash_floats_show.pending_requests.title') }}</span>
            <VBtn size="small" variant="text" :href="route('petty-cash.requests.index') + '?float_id=' + float.id">
              {{ t('petty_cash_floats_show.pending_requests.view_all') }}
            </VBtn>
          </VCardTitle>
          <VDivider />
          <VList lines="two" density="compact">
            <VListItem v-for="request in pendingRequests" :key="request.id" :href="route('petty-cash.requests.show', request.uuid)">
              <VListItemTitle>{{ request.reference_number }}</VListItemTitle>
              <VListItemSubtitle>{{ request.requestor }} — {{ request.purpose }}</VListItemSubtitle>
              <template #append>
                <span class="text-body-2 font-weight-semibold">R{{ request.amount }}</span>
              </template>
            </VListItem>
            <VListItem v-if="!pendingRequests.length">
              <VListItemSubtitle class="text-caption">{{ t('petty_cash_floats_show.pending_requests.no_pending') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Transaction Log -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            {{ t('petty_cash_floats_show.transaction_log.title') }}
          </VCardTitle>
          <VDivider />
          <VList lines="two" density="compact">
            <VListItem v-for="transaction in recentTransactions" :key="transaction.id">
              <template #prepend>
                <VIcon :color="txTypeColor(transaction.type)" size="20" class="mr-2">{{ txIcon(transaction.type) }}</VIcon>
              </template>
              <VListItemTitle class="text-body-2">{{ transaction.description ?? transaction.type }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ transaction.performed_by }} · {{ transaction.performed_at }}</VListItemSubtitle>
              <template #append>
                <div class="text-right">
                  <div class="text-body-2 font-weight-bold" :class="['issue'].includes(transaction.type) ? 'text-error' : 'text-success'">
                    {{ ['issue'].includes(transaction.type) ? '-' : '+' }}{{ fmt(transaction.amount) }}
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ t('petty_cash_floats_show.transaction_log.balance', { balance: fmt(transaction.balance_after) }) }}</div>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Replenish Dialog -->
    <VDialog v-model="replenishDialog" max-width="400">
      <VCard :loading="replenishForm.processing" :disabled="replenishForm.processing">
        <VCardTitle class="pa-4">{{ t('petty_cash_floats_show.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextField
            v-model="replenishForm.amount"
            :label="t('petty_cash_floats_show.dialog.amount')"
            type="number"
            min="0.01"
            variant="outlined"
            density="comfortable"
            :error-messages="replenishForm.errors.amount"
            :disabled="replenishForm.processing"
          />
          <VTextField
            v-model="replenishForm.reference"
            :label="t('petty_cash_floats_show.dialog.reference')"
            variant="outlined"
            density="comfortable"
            :error-messages="replenishForm.errors.reference"
            :disabled="replenishForm.processing"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" :disabled="replenishForm.processing" @click="replenishDialog = false">
            {{ t('petty_cash_floats_show.dialog.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="replenishForm.processing" :disabled="replenishForm.processing" @click="replenish">
            {{ t('petty_cash_floats_show.dialog.replenish') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
