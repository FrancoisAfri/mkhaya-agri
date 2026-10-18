<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  request: {
    id: number; request_number: string; request_title: string; status: string
    contract_length: string | null; contract_length_unit: string | null
    criticality: number | null; contract_value: number | null
    summary: string | null; motivation: string | null
    recommendation: string | null; rejection_reason: string | null; type: string | null
  }
}>()

const approveDialog = ref(false)
const rejectDialog = ref(false)
const recommendation = ref('')
const rejectionReason = ref('')

function approve() {
  router.post(route('contracts.requests.approve', props.request.id), { recommendation: recommendation.value }, {
    preserveScroll: true, onSuccess: () => (approveDialog.value = false),
  })
}
function reject() {
  router.post(route('contracts.requests.reject', props.request.id), { rejection_reason: rejectionReason.value }, {
    preserveScroll: true, onSuccess: () => (rejectDialog.value = false),
  })
}
function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(v)
}
const statusColor: Record<string, string> = { pending: 'warning', approved: 'success', rejected: 'error' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.requests.index'))" />
        <div>
          <h4 class="text-h4 mb-0">{{ request.request_title }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ request.request_number }}</p>
        </div>
        <VChip :color="statusColor[request.status]" variant="tonal">{{ request.status }}</VChip>
      </div>
      <div v-if="request.status === 'pending'" class="d-flex gap-2">
        <VBtn color="success" variant="tonal" @click="approveDialog = true">
          {{ t('contracts_requests_show.approve') }}
        </VBtn>
        <VBtn color="error" variant="tonal" @click="rejectDialog = true">
          {{ t('contracts_requests_show.reject') }}
        </VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText>
        <VRow>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.type') }}</div>
            {{ request.type ?? '—' }}
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.length') }}</div>
            {{ request.contract_length ?? '—' }} {{ request.contract_length_unit ?? '' }}
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.criticality') }}</div>
            {{ request.criticality ?? '—' }}
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.value') }}</div>
            {{ money(request.contract_value) }}
          </VCol>
        </VRow>
        <div class="mt-4">
          <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.summary') }}</div>
          {{ request.summary ?? '—' }}
        </div>
        <div class="mt-4">
          <div class="text-caption text-medium-emphasis">{{ t('contracts_requests_show.details.motivation') }}</div>
          {{ request.motivation ?? '—' }}
        </div>
        <div v-if="request.recommendation" class="mt-4 text-success">
          <div class="text-caption">{{ t('contracts_requests_show.details.recommendation') }}</div>
          {{ request.recommendation }}
        </div>
        <div v-if="request.rejection_reason" class="mt-4 text-error">
          <div class="text-caption">{{ t('contracts_requests_show.details.rejection_reason') }}</div>
          {{ request.rejection_reason }}
        </div>
      </VCardText>
    </VCard>

    <VDialog v-model="approveDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('contracts_requests_show.dialog.approve_title') }}</VCardTitle>
        <VCardText>
          <VTextarea
            v-model="recommendation"
            :label="t('contracts_requests_show.dialog.recommendation')"
            variant="outlined"
            rows="3"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">
            {{ t('contracts_requests_show.dialog.cancel') }}
          </VBtn>
          <VBtn color="success" @click="approve">
            {{ t('contracts_requests_show.dialog.approve_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('contracts_requests_show.dialog.reject_title') }}</VCardTitle>
        <VCardText>
          <VTextarea
            v-model="rejectionReason"
            :label="t('contracts_requests_show.dialog.reason')"
            variant="outlined"
            rows="3"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">
            {{ t('contracts_requests_show.dialog.cancel') }}
          </VBtn>
          <VBtn color="error" @click="reject">
            {{ t('contracts_requests_show.dialog.reject_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
