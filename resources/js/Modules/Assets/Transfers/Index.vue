<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Transfer = {
  id: number
  uuid: string
  asset: { uuid: string; name: string; asset_number: string }
  from_location: { id: number; name: string } | null
  to_location: { id: number; name: string } | null
  to_user: { id: number; name: string } | null
  status: { value: string; label: string; color: string }
  transfer_date: string
  created_at: string
}

const props = defineProps<{ transfers: { data: Transfer[]; links: any[]; meta: any } }>()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <h1 class="text-h4 font-weight-bold">Asset Transfers</h1>
      <VSpacer />
      <VBtn color="primary" :to="route('assets.transfers.create')">New Transfer</VBtn>
    </div>

    <VCard>
      <VCardItem>
        <VTable>
          <thead><tr><th>Asset</th><th>From</th><th>To</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="t in props.transfers.data" :key="t.id">
              <td>{{ t.asset.name }} ({{ t.asset.asset_number }})</td>
              <td>{{ t.from_location?.name ?? '—' }}</td>
              <td>{{ t.to_location?.name ?? t.to_user?.name ?? '—' }}</td>
              <td>{{ t.transfer_date }}</td>
              <td><VChip :color="t.status.color" size="small">{{ t.status.label }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
