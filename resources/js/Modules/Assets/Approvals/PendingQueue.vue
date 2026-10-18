<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Approval = {
  uuid: string
  id: number
  type: string
  current_step: number
  total_steps: number
  amount: number | null
  requester: { id: number; name: string } | null
  created_at: string
}

const props = defineProps<{ pending: Approval[] }>()

function act(uuid: string, decision: 'approve' | 'reject') {
  router.post(route('assets.approvals.act', uuid), { decision }, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">My Pending Approvals</h1>

    <VCard v-if="!props.pending.length">
      <VCardItem><div class="text-center pa-6 text-medium-emphasis">Nothing waiting on you.</div></VCardItem>
    </VCard>

    <VCard v-for="a in props.pending" :key="a.id" class="mb-3">
      <VCardItem>
        <div class="d-flex align-center">
          <div class="flex-grow-1">
            <div class="font-weight-bold">#{{ a.id }} · {{ a.type }}</div>
            <div class="text-caption">Requested by {{ a.requester?.name ?? 'Unknown' }} · Step {{ a.current_step }}/{{ a.total_steps }} · {{ new Date(a.created_at).toLocaleDateString() }}</div>
            <div v-if="a.amount != null" class="text-body-2 mt-1">Amount: R {{ Number(a.amount).toLocaleString('en-ZA', { minimumFractionDigits: 2 }) }}</div>
          </div>
          <div class="d-flex gap-2">
            <VBtn size="small" variant="text" :to="route('assets.approvals.show', a.uuid)">View</VBtn>
            <VBtn size="small" color="success" @click="act(a.uuid, 'approve')">Approve</VBtn>
            <VBtn size="small" color="error" variant="tonal" @click="act(a.uuid, 'reject')">Reject</VBtn>
          </div>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
