<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  offer: { id: number }
  contract: {
    id: number
    status: string
    content: string | null
    sent_at: string | null
    signed_at: string | null
    candidate: { id: number; full_name: string; email: string } | null
    job: { id: number; title: string } | null
    signature_requests: Array<{
      id: number; signatory_email: string; status: string; signed_at: string | null
    }>
    created_at: string
  }
}>()

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn variant="text" icon size="small" @click="router.visit(route('offers.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div class="flex-grow-1">
        <h4 class="text-h4 font-weight-bold mb-1">Contract</h4>
        <p class="text-medium-emphasis mb-0">{{ contract.candidate?.full_name }} · {{ contract.job?.title }}</p>
      </div>
      <VChip variant="tonal" :color="contract.status === 'signed' ? 'success' : 'info'">
        {{ contract.status }}
      </VChip>
    </div>

    <VRow>
      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">Details</h6>
            <VList density="compact" class="pa-0">
              <VListItem prepend-icon="tabler-user"
                :title="contract.candidate?.full_name ?? '—'" subtitle="Candidate" />
              <VListItem prepend-icon="tabler-calendar"
                :title="formatDate(contract.sent_at)" subtitle="Sent" />
              <VListItem prepend-icon="tabler-circle-check"
                :title="formatDate(contract.signed_at)" subtitle="Fully Signed" />
            </VList>
          </VCardText>
        </VCard>

        <!-- Signatories -->
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">Signatories</h6>
            <div v-if="contract.signature_requests.length" class="d-flex flex-column gap-2">
              <div v-for="sig in contract.signature_requests" :key="sig.id"
                class="d-flex align-center justify-space-between pa-2 rounded"
                style="border: 1px solid rgba(0,0,0,0.08)">
                <span class="text-body-2">{{ sig.signatory_email }}</span>
                <VChip size="x-small" :color="sig.status === 'signed' ? 'success' : 'default'" variant="tonal">
                  {{ sig.status }}
                </VChip>
              </div>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis">No signatories.</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard elevation="0" border v-if="contract.content">
          <VCardText class="pa-5">
            <h6 class="text-h6 font-weight-semibold mb-3">Contract Content</h6>
            <div class="text-body-2" style="white-space: pre-wrap; line-height: 1.8;">
              {{ contract.content }}
            </div>
          </VCardText>
        </VCard>
        <VCard v-else elevation="0" border>
          <VCardText class="pa-5 text-center text-medium-emphasis">
            <VIcon icon="tabler-file" size="48" class="mb-2" />
            <p>No contract content available.</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
