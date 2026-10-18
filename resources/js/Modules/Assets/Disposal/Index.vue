<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Disposal = {
  id: number
  uuid: string
  asset: { uuid: string; name: string; asset_number: string }
  disposal_method: string
  proceeds: number | null
  book_value_at_disposal: number | null
  write_off_loss: number | null
  status: { value: string; label: string; color: string }
  created_at: string
}

const props = defineProps<{ disposals: { data: Disposal[]; links: any[]; meta: any } }>()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-6">
      <h1 class="text-h4 font-weight-bold">Asset Disposals</h1>
      <VSpacer />
      <VBtn color="primary" :to="route('assets.disposal.create')">New Disposal</VBtn>
    </div>

    <VCard>
      <VCardItem>
        <VTable>
          <thead>
            <tr><th>Asset</th><th>Method</th><th>Proceeds</th><th>Book Value</th><th>Loss</th><th>Status</th><th>Requested</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="d in props.disposals.data" :key="d.id">
              <td>{{ d.asset.name }} ({{ d.asset.asset_number }})</td>
              <td><VChip size="small">{{ d.disposal_method }}</VChip></td>
              <td>{{ d.proceeds != null ? `R ${Number(d.proceeds).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }}</td>
              <td>{{ d.book_value_at_disposal != null ? `R ${Number(d.book_value_at_disposal).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }}</td>
              <td>{{ d.write_off_loss != null ? `R ${Number(d.write_off_loss).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }}</td>
              <td><VChip :color="d.status.color" size="small">{{ d.status.label }}</VChip></td>
              <td>{{ new Date(d.created_at).toLocaleDateString() }}</td>
              <td><VBtn size="small" variant="text" :to="route('assets.disposal.show', d.uuid)">View</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
