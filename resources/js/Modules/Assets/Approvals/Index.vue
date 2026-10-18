<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Approval = {
  id: number
  uuid: string
  type: string
  current_step: number
  total_steps: number
  status: { value: string; label: string; color: string }
  amount: number | null
  requester: { id: number; name: string } | null
  created_at: string
}

const props = defineProps<{
  approvals: { data: Approval[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { pending: number; approved: number; rejected: number; total: number }
  typeOptions: { value: string; label: string }[]
}>()

const status = ref<string>(props.filters?.status ?? '')
const type = ref<string>(props.filters?.type ?? '')

function applyFilters() {
  router.get(route('assets.approvals.index'), {
    status: status.value || undefined,
    type:   type.value   || undefined,
  }, { preserveState: true, replace: true })
}

watch([status, type], applyFilters)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold">Approvals</h1>
        <p class="text-subtitle-2 text-medium-emphasis">Track and act on asset approval requests</p>
      </div>
      <VSpacer />
      <VBtn color="primary" :to="route('assets.approvals.pending')">My Pending Queue</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" md="3"><VCard><VCardItem><div class="text-overline">Pending</div><div class="text-h4">{{ props.stats.pending }}</div></VCardItem></VCard></VCol>
      <VCol cols="12" md="3"><VCard><VCardItem><div class="text-overline">Approved</div><div class="text-h4">{{ props.stats.approved }}</div></VCardItem></VCard></VCol>
      <VCol cols="12" md="3"><VCard><VCardItem><div class="text-overline">Rejected</div><div class="text-h4">{{ props.stats.rejected }}</div></VCardItem></VCard></VCol>
      <VCol cols="12" md="3"><VCard><VCardItem><div class="text-overline">Total</div><div class="text-h4">{{ props.stats.total }}</div></VCardItem></VCard></VCol>
    </VRow>

    <VCard>
      <VCardItem>
        <VRow>
          <VCol cols="12" md="4">
            <VSelect v-model="status" :items="[{value:'',title:'All'},{value:'pending',title:'Pending'},{value:'approved',title:'Approved'},{value:'rejected',title:'Rejected'}]" item-title="title" item-value="value" label="Status" density="compact" />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect v-model="type" :items="[{value:'',label:'All Types'}, ...props.typeOptions]" item-title="label" item-value="value" label="Type" density="compact" />
          </VCol>
        </VRow>

        <VTable density="comfortable">
          <thead>
            <tr><th>#</th><th>Type</th><th>Requester</th><th>Step</th><th>Amount</th><th>Status</th><th>Requested</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="a in props.approvals.data" :key="a.id">
              <td>{{ a.id }}</td>
              <td>{{ a.type }}</td>
              <td>{{ a.requester?.name ?? '—' }}</td>
              <td>{{ a.current_step }}/{{ a.total_steps }}</td>
              <td>{{ a.amount != null ? `R ${Number(a.amount).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }}</td>
              <td><VChip :color="a.status.color" size="small">{{ a.status.label }}</VChip></td>
              <td>{{ new Date(a.created_at).toLocaleDateString() }}</td>
              <td><VBtn size="small" variant="text" :to="route('assets.approvals.show', a.uuid)">View</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
