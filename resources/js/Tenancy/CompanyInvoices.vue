<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
interface Inv { id: number; period: string; amount: number; status: string; due_date: string | null; paid_at: string | null }
defineProps<{ company: { id: number; name: string }; invoices: Inv[] }>()
const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const statusColor: Record<string, string> = { open: 'info', paid: 'success', overdue: 'error', void: 'default' }
function markPaid(i: Inv) { useForm({}).post(route('tenancy.invoices.paid', i.id), { preserveScroll: true }) }
</script>
<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.visit(route('tenancy.companies.index'))">Companies</VBtn>
    <h2 class="text-h5 mb-3">{{ company.name }} — invoices</h2>
    <VCard border>
      <VTable density="compact">
        <thead><tr><th>Period</th><th class="text-right">Amount</th><th>Status</th><th>Due</th><th>Paid</th><th></th></tr></thead>
        <tbody>
          <tr v-for="i in invoices" :key="i.id">
            <td>{{ i.period }}</td>
            <td class="text-right font-weight-medium">{{ money(i.amount) }}</td>
            <td><VChip size="x-small" :color="statusColor[i.status]" variant="tonal" class="text-capitalize">{{ i.status }}</VChip></td>
            <td class="text-caption">{{ i.due_date }}</td>
            <td class="text-caption">{{ i.paid_at || '—' }}</td>
            <td class="text-right"><VBtn v-if="i.status !== 'paid'" size="x-small" color="success" variant="tonal" @click="markPaid(i)">Mark paid</VBtn></td>
          </tr>
          <tr v-if="!invoices.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No invoices yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
