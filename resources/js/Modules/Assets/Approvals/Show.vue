<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Step = { step_number: number; approver_id: number; approver_name?: string; decision?: string; decided_at?: string; comments?: string }
type Comment = { id: number; user_id: number; user_name?: string; body: string; created_at: string }

const props = defineProps<{
  approval: {
    id: number
    uuid: string
    type: string
    status: { value: string; label: string; color: string }
    current_step: number
    total_steps: number
    amount: number | null
    requester: { id: number; name: string } | null
    requested_by: number
    steps: Step[]
    comments: Comment[]
    created_at: string
  }
  canAct: boolean
}>()

const decision = ref<'approve' | 'reject' | ''>('')
const comments = ref('')

function submit() {
  if (!decision.value) return
  router.post(route('assets.approvals.act', props.approval.uuid), {
    decision: decision.value,
    comments: comments.value || undefined,
  }, { preserveScroll: true, onSuccess: () => { decision.value = ''; comments.value = '' } })
}

const isPending = computed(() => props.approval.status.value === 'pending' || props.approval.status.value === 'in_review')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <h1 class="text-h4 font-weight-bold">Approval #{{ props.approval.id }}</h1>
      <VSpacer />
      <VChip :color="props.approval.status.color" size="large">{{ props.approval.status.label }}</VChip>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard>
          <VCardItem>
            <VCardTitle>Approval Chain</VCardTitle>
            <VTimeline density="compact" align="start" side="end">
              <VTimelineItem v-for="s in props.approval.steps" :key="s.step_number"
                :dot-color="s.decision === 'approved' ? 'success' : s.decision === 'rejected' ? 'error' : 'grey'">
                <div class="font-weight-bold">Step {{ s.step_number }} — {{ s.approver_name ?? 'Approver #' + s.approver_id }}</div>
                <div class="text-caption">{{ s.decision ?? 'pending' }} {{ s.decided_at ? '· ' + new Date(s.decided_at).toLocaleString() : '' }}</div>
                <div v-if="s.comments" class="text-body-2 mt-1">{{ s.comments }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardItem>
        </VCard>

        <VCard v-if="props.approval.comments?.length" class="mt-4">
          <VCardItem>
            <VCardTitle>Comments</VCardTitle>
            <div v-for="c in props.approval.comments" :key="c.id" class="mb-3 pa-2" style="border-left: 3px solid #ccc">
              <div class="text-caption">{{ c.user_name ?? 'User #' + c.user_id }} · {{ new Date(c.created_at).toLocaleString() }}</div>
              <div>{{ c.body }}</div>
            </div>
          </VCardItem>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard>
          <VCardItem>
            <VCardTitle>Details</VCardTitle>
            <div class="my-2"><strong>Type:</strong> {{ props.approval.type }}</div>
            <div class="my-2"><strong>Step:</strong> {{ props.approval.current_step }} / {{ props.approval.total_steps }}</div>
            <div class="my-2" v-if="props.approval.amount != null"><strong>Amount:</strong> R {{ Number(props.approval.amount).toLocaleString('en-ZA', { minimumFractionDigits: 2 }) }}</div>
            <div class="my-2"><strong>Requested by:</strong> {{ props.approval.requester?.name ?? '—' }}</div>
            <div class="my-2"><strong>Submitted:</strong> {{ new Date(props.approval.created_at).toLocaleString() }}</div>
          </VCardItem>
        </VCard>

        <VCard v-if="props.canAct && isPending" class="mt-4">
          <VCardItem>
            <VCardTitle>Decision</VCardTitle>
            <VTextarea v-model="comments" label="Comments" rows="3" density="compact" class="mb-2" />
            <div class="d-flex gap-2">
              <VBtn color="success" @click="decision = 'approve'; submit()" :loading="false">Approve</VBtn>
              <VBtn color="error" variant="tonal" @click="decision = 'reject'; submit()">Reject</VBtn>
            </div>
          </VCardItem>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
