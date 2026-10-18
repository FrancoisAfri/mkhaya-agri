<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type ApprovalLevel = { level: number; level_name: string; status: string; actioned_at: string | null; comment: string | null }
type Document = { id: number; document_type: string; filename: string; uploaded_at: string; view_url: string; download_url: string }
type LeaveRequest = {
  id: number; request_number: string; employee_name: string; leave_type: string
  start_date: string; end_date: string
  request_mode: 'daily' | 'hourly'; start_time: string | null; end_time: string | null
  working_days: number; working_hours: number
  status: string; status_label: string; status_color: string
  submitted_at: string | null; notes: string | null
  rejection_reason: string | null; cancellation_reason: string | null
  ai_explanation: string | null; bcea_validation: Record<string, unknown> | null
  approval_chain: ApprovalLevel[]
  documents: Document[]
}

const props = defineProps<{ request: LeaveRequest; isOwner: boolean }>()

const cancelDialog = ref(false)
const cancelForm = useForm({ reason: '' })
const uploadForm = useForm({ document: null as File | null, document_type: 'supporting_doc' })
const uploadDialog = ref(false)

const submitCancel = (id: number) => {
  cancelForm.post(`/leave-management/my-applications/${id}/cancel`, {
    onSuccess: () => { cancelDialog.value = false; cancelForm.reset() },
  })
}

const submitUpload = (id: number) => {
  uploadForm.post(`/leave-management/my-applications/${id}/documents`, {
    onSuccess: () => { uploadDialog.value = false; uploadForm.reset() },
  })
}

const approvalStatusColor = (s: string) => ({
  approved: 'success', rejected: 'error', pending: 'warning',
  skipped: 'secondary', escalated: 'orange', delegated: 'info',
}[s] ?? 'secondary')

const approvalIcon = (s: string) => ({
  approved: 'tabler-check-circle', rejected: 'tabler-close-circle',
  pending: 'tabler-clock-outline', escalated: 'tabler-alarm-light',
}[s] ?? 'tabler-dots-horizontal')

const bcea = computed(() => {
  const v = props.request.bcea_validation
  if (!v) return null
  return {
    certRequired: Boolean(v.certificate_required),
    certReasons:  Array.isArray(v.certificate_reasons) ? (v.certificate_reasons as string[]) : [],
    warnings:     Array.isArray(v.warnings)             ? (v.warnings as string[])             : [],
    errors:       Array.isArray(v.errors)               ? (v.errors as string[])               : [],
  }
})

const docTypes = [
  { title: 'Medical Certificate',  value: 'medical_certificate' },
  { title: 'Supporting Document',  value: 'supporting_doc' },
  { title: 'Birth Certificate',    value: 'birth_certificate' },
  { title: 'Other',                value: 'other' },
]

const canCancel = (s: string) => ['pending', 'pending_manager', 'pending_department', 'pending_hr', 'pending_payroll'].includes(s)

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="9">

        <!-- Header -->
        <div class="d-flex align-center gap-3 mb-6">
          <VBtn icon variant="text" @click="router.visit('/leave-management/my-applications')">
            <VIcon icon="tabler-arrow-left" />
          </VBtn>
          <div class="flex-grow-1">
            <h4 class="text-h4">{{ request.request_number }}</h4>
            <p class="text-medium-emphasis mb-0">{{ request.employee_name }}</p>
          </div>
          <VChip :color="request.status_color" label size="large">{{ request.status_label }}</VChip>
        </div>

        <VRow>
          <!-- Request details -->
          <VCol cols="12" md="7">
            <VCard class="mb-4">
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('leave.leave_details') }}</VCardTitle>
              <VDivider />
              <VCardText>
                <VTable density="compact">
                  <tbody>
                    <tr><td class="text-medium-emphasis w-50">{{ t('leave.leave_type') }}</td><td class="font-weight-medium">{{ request.leave_type }}</td></tr>
                    <tr><td class="text-medium-emphasis">{{ t('leave.start_date') }}</td><td>{{ request.start_date }}</td></tr>
                    <tr><td class="text-medium-emphasis">{{ t('leave.end_date') }}</td><td>{{ request.end_date }}</td></tr>
                    <tr v-if="request.request_mode === 'hourly'">
                      <td class="text-medium-emphasis">Time</td>
                      <td>{{ request.start_time }} – {{ request.end_time }}</td>
                    </tr>
                    <tr>
                      <td class="text-medium-emphasis">{{ t('leave.duration') }}</td>
                      <td v-if="request.request_mode === 'hourly'">
                        {{ request.working_hours }} hour{{ request.working_hours !== 1 ? 's' : '' }}
                      </td>
                      <td v-else>{{ request.working_days }} day{{ request.working_days !== 1 ? 's' : '' }}</td>
                    </tr>
                    <tr><td class="text-medium-emphasis">{{ t('leave.submitted') }}</td><td>{{ request.submitted_at ?? '—' }}</td></tr>
                    <tr v-if="request.notes"><td class="text-medium-emphasis">{{ t('leave.notes') }}</td><td>{{ request.notes }}</td></tr>
                    <tr v-if="request.rejection_reason">
                      <td class="text-medium-emphasis text-error">{{ t('leave.rejection_reason') }}</td>
                      <td class="text-error">{{ request.rejection_reason }}</td>
                    </tr>
                    <tr v-if="request.cancellation_reason">
                      <td class="text-medium-emphasis">{{ t('leave.cancellation_reason') }}</td>
                      <td>{{ request.cancellation_reason }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </VCardText>
              <VCardActions v-if="isOwner && canCancel(request.status)" class="pa-4 pt-0">
                <VBtn color="error" variant="tonal" prepend-icon="tabler-close" @click="cancelDialog = true">
                  {{ t('leave.cancel_request') }}
                </VBtn>
                <VBtn variant="tonal" prepend-icon="tabler-upload" @click="uploadDialog = true">
                  {{ t('leave.upload_document') }}
                </VBtn>
              </VCardActions>
            </VCard>

            <!-- BCEA Validation Panel -->
            <VCard v-if="bcea && (bcea.certRequired || bcea.warnings.length || bcea.errors.length)" class="mb-4">
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">
                <VIcon icon="tabler-shield-check-outline" class="me-2" color="warning" />BCEA Validation
              </VCardTitle>
              <VDivider />
              <VCardText class="pa-4 d-flex flex-column gap-3">
                <VAlert v-if="bcea.certRequired" type="warning" variant="tonal" density="compact" icon="tabler-stethoscope">
                  <strong>Medical Certificate Required</strong>
                  <ul v-if="bcea.certReasons.length" class="mt-1 mb-0 ps-4">
                    <li v-for="(r, i) in bcea.certReasons" :key="i">{{ r }}</li>
                  </ul>
                  <p v-else class="mb-0 mt-1">Please upload a valid medical certificate for this request.</p>
                </VAlert>
                <VAlert v-for="(w, i) in bcea.warnings" :key="'w'+i" type="info" variant="tonal" density="compact">
                  {{ w }}
                </VAlert>
                <VAlert v-for="(e, i) in bcea.errors" :key="'e'+i" type="error" variant="tonal" density="compact">
                  {{ e }}
                </VAlert>
              </VCardText>
              <VCardActions v-if="isOwner && bcea.certRequired" class="pa-4 pt-0">
                <VBtn variant="tonal" color="warning" prepend-icon="tabler-upload" @click="uploadDialog = true">
                  {{ t('leave.upload_document') }}
                </VBtn>
              </VCardActions>
            </VCard>

            <!-- AI Explanation -->
            <VCard v-if="request.ai_explanation" class="mb-4">
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">
                <VIcon icon="tabler-robot" class="me-2" color="primary" />AI Leave Summary
              </VCardTitle>
              <VDivider />
              <VCardText>
                <p class="text-body-2 mb-0">{{ request.ai_explanation }}</p>
              </VCardText>
            </VCard>

            <!-- Documents -->
            <VCard v-if="request.documents.length" class="mb-4">
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">
                <VIcon icon="tabler-file-outline" class="me-2" />Documents
              </VCardTitle>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="doc in request.documents" :key="doc.id">
                  <template #prepend><VIcon icon="tabler-file-pdf-box" /></template>
                  <VListItemTitle>{{ doc.filename }}</VListItemTitle>
                  <VListItemSubtitle>{{ doc.document_type }} · {{ doc.uploaded_at }}</VListItemSubtitle>
                  <template #append>
                    <div class="d-flex gap-1">
                      <VBtn
                        size="x-small"
                        variant="tonal"
                        prepend-icon="tabler-eye"
                        :href="doc.view_url"
                        target="_blank"
                      >
                        View
                      </VBtn>
                      <VBtn
                        size="x-small"
                        variant="tonal"
                        prepend-icon="tabler-download"
                        :href="doc.download_url"
                      >
                        Download
                      </VBtn>
                    </div>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <!-- Approval timeline -->
          <VCol cols="12" md="5">
            <VCard>
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('leave.approval_chain') }}</VCardTitle>
              <VDivider />
              <VCardText class="pa-4">
                <VTimeline density="compact" side="end" align="start">
                  <VTimelineItem
                    v-for="level in request.approval_chain"
                    :key="level.level"
                    :dot-color="approvalStatusColor(level.status)"
                    size="small"
                  >
                    <template #icon>
                      <VIcon :icon="approvalIcon(level.status)" size="16" />
                    </template>
                    <div class="font-weight-medium">{{ level.level_name }}</div>
                    <VChip size="x-small" :color="approvalStatusColor(level.status)" label class="mt-1">
                      {{ level.status }}
                    </VChip>
                    <div v-if="level.actioned_at" class="text-caption text-medium-emphasis mt-1">
                      {{ level.actioned_at }}
                    </div>
                    <div v-if="level.comment" class="text-caption mt-1">
                      {{ level.comment }}
                    </div>
                  </VTimelineItem>
                </VTimeline>
                <div v-if="!request.approval_chain.length" class="text-medium-emphasis text-center py-4">
                  No approval chain configured.
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCol>
    </VRow>

    <!-- Cancel dialog -->
    <VDialog v-model="cancelDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.cancel_request') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="cancelForm.reason" :label="t('leave.reason_label') + ' *'" variant="outlined" rows="3" density="comfortable" :error-messages="cancelForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="cancelDialog = false">{{ t('leave.back') }}</VBtn>
          <VBtn color="error" :loading="cancelForm.processing" :disabled="!cancelForm.reason" @click="submitCancel(request.id)">{{ t('leave.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Upload dialog -->
    <VDialog v-model="uploadDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.upload_document') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="uploadForm.document_type" :items="docTypes" :label="t('leave.document_type')" variant="outlined" density="comfortable" class="mb-4" />
          <VFileInput
            v-model="uploadForm.document"
            :label="t('leave.select_file')"
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            variant="outlined"
            density="comfortable"
            :error-messages="uploadForm.errors.document"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="uploadDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="primary" :loading="uploadForm.processing" :disabled="!uploadForm.document" @click="submitUpload(request.id)">{{ t('leave.upload_document') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
