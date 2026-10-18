<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const confirmRef = ref()

interface Pay { ref: string; purpose: string; amount: number; status: string; gateway: string; created_at: string; paid_at: string | null }
const props = defineProps<{
  company: { id: number; name: string; status: string }
  total: number
  gateway: { code: string; label: string; mode: string } | null
  payments: Pay[]
}>()

const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { paid: 'success', pending: 'warning', failed: 'error', cancelled: 'secondary' }

function payNow() {
  confirmRef.value.open(
    { title: 'Proceed to payment?', message: `You'll be taken to ${props.gateway?.label} to pay ${money(props.total)} securely.`, confirmText: 'Continue to payment', confirmColor: 'primary', icon: 'tabler-credit-card' },
    () => router.post(route('tenancy.pay.checkout'), { purpose: 'subscription', amount: props.total }, { preserveScroll: true }),
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 900px">
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h3 class="text-h5 font-weight-bold mb-1">Payment</h3>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ props.company.name }} · <span class="text-capitalize">{{ props.company.status }}</span></p>
      </div>
      <Link :href="route('tenancy.subscription.index')"><VBtn variant="tonal" prepend-icon="tabler-arrow-left">Back to subscription</VBtn></Link>
    </div>

    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="pa-6 text-center">
        <div class="text-caption text-medium-emphasis mb-1">Monthly subscription total</div>
        <div class="text-h3 font-weight-bold text-primary mb-4">{{ money(props.total) }}</div>
        <template v-if="props.gateway">
          <VBtn color="primary" size="large" prepend-icon="tabler-credit-card" @click="payNow">Pay {{ money(props.total) }} with {{ props.gateway.label }}</VBtn>
          <div class="text-caption text-medium-emphasis mt-2">
            Secure payment via {{ props.gateway.label }} ({{ props.gateway.mode }}) — card &amp; Instant EFT
          </div>
        </template>
        <VAlert v-else type="warning" variant="tonal" class="text-start">No payment gateway is active yet. Mkhaya needs to configure a gateway before you can pay online.</VAlert>
      </VCardText>
    </VCard>

    <VCard elevation="0" border rounded="lg">
      <VCardItem><VCardTitle class="text-subtitle-1">Payment history</VCardTitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>Reference</th><th>Purpose</th><th class="text-end">Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
          <tr v-for="p in props.payments" :key="p.ref">
            <td class="font-weight-medium">{{ p.ref }}</td>
            <td>{{ p.purpose }}</td>
            <td class="text-end">{{ money(p.amount) }}</td>
            <td>{{ p.gateway }}</td>
            <td><VChip size="small" :color="statusColor[p.status] ?? 'secondary'" variant="tonal" label>{{ p.status }}</VChip></td>
            <td class="text-caption">{{ p.paid_at || p.created_at }}</td>
          </tr>
          <tr v-if="!props.payments.length"><td colspan="6" class="text-center py-8 text-medium-emphasis">No payments yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmRef" />
  </VContainer>
</template>
