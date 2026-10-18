<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ApprovalStep = {
  level: number
  level_name: string
  status: string
  actioned_at: string | null
  comment: string | null
}

type Document = {
  id: number
  document_type: string
  filename: string
  uploaded_at: string
  view_url: string
  download_url: string
}

type LeaveRequest = {
  id: number
  uuid: string
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  request_mode: string
  start_time: string | null
  end_time: string | null
  working_days: number
  working_hours: number | null
  status: string
  status_label: string
  status_color: string
  submitted_at: string | null
  notes: string | null
  ai_explanation: string | null
  rejection_reason: string | null
  cancellation_reason: string | null
  bcea_validation: any
  approval_chain: ApprovalStep[]
  documents: Document[]
}

const props = defineProps<{
  request: LeaveRequest
  isOwner: boolean
}>()

const cancelDialog = ref(false)
const cancelForm = useForm({ reason: '' })
const uploadDialog = ref(false)
const uploadFile = ref<File | null>(null)
const uploadType = ref('supporting_document')
const uploading = ref(false)

function submitCancel() {
  cancelForm.post(route('hr.leave.cancel', { leaveRequest: props.request.id }), {
    onSuccess: () => { cancelDialog.value = false },
  })
}

function submitUpload() {
  if (!uploadFile.value) return
  uploading.value = true
  const fd = new FormData()
  fd.append('document', uploadFile.value)
  fd.append('document_type', uploadType.value)
  router.post(route('hr.leave.documents.upload', { leaveRequest: props.request.id }), fd as any, {
    forceFormData: true,
    onFinish: () => { uploading.value = false },
    onSuccess: () => { uploadDialog.value = false; uploadFile.value = null },
  })
}

const stepColor = (status: string) => {
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'error'
  if (status === 'pending') return 'warning'
  return 'default'
}

const stepIcon = (status: string) => {
  if (status === 'approved') return 'tabler-circle-check'
  if (status === 'rejected') return 'tabler-circle-x'
  return 'tabler-clock'
}

const canCancel = ['pending', 'pending_manager', 'pending_department', 'pending_hr'].includes(props.request.status)

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 900px;">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.my-applications'))" />
        <div>
          <div class="d-flex align-center gap-2">
            <h4 class="text-h4">{{ request.request_number }}</h4>
            <VChip :color="request.status_color" size="small" variant="tonal">{{ request.status_label }}</VChip>
          </div>
          <p class="text-medium-emphasis mb-0">{{ request.leave_type }} · {{ request.employee_name }}</p>
        </div>
      </div>
      <div v-if="isOwner && canCancel" class="d-flex gap-2">
        <VBtn color="error" variant="tonal" prepend-icon="tabler-x" @click="cancelDialog = true">Cancel Request</VBtn>
      </div>
    </div>

    <VRow>
      <!-- Main Details -->
      <VCol cols="12" md="7">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Leave Details</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow dense>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1">Leave Type</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ request.leave_type }}</p>
              </VCol>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1">Mode</p>
                <p class="text-body-2 mb-0 text-capitalize">{{ request.request_mode }}</p>
              </VCol>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1 mt-3">Start Date</p>
                <p class="text-body-2 mb-0">{{ request.start_date }}</p>
              </VCol>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1 mt-3">End Date</p>
                <p class="text-body-2 mb-0">{{ request.end_date }}</p>
              </VCol>
              <template v-if="request.request_mode === 'hourly'">
                <VCol cols="6">
                  <p class="text-caption text-medium-emphasis mb-1 mt-3">Start Time</p>
                  <p class="text-body-2 mb-0">{{ request.start_time ?? '—' }}</p>
                </VCol>
                <VCol cols="6">
                  <p class="text-caption text-medium-emphasis mb-1 mt-3">End Time</p>
                  <p class="text-body-2 mb-0">{{ request.end_time ?? '—' }}</p>
                </VCol>
              </template>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1 mt-3">Working Days</p>
                <p class="text-body-2 mb-0"><strong>{{ request.working_days }}</strong></p>
              </VCol>
              <VCol cols="6">
                <p class="text-caption text-medium-emphasis mb-1 mt-3">Submitted</p>
                <p class="text-body-2 mb-0">{{ formatDate(request.submitted_at) }}</p>
              </VCol>
              <VCol v-if="request.notes" cols="12">
                <p class="text-caption text-medium-emphasis mb-1 mt-3">Notes</p>
                <p class="text-body-2 mb-0">{{ request.notes }}</p>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- Rejection / Cancellation reason -->
        <VAlert v-if="request.rejection_reason" type="error" variant="tonal" class="mb-5">
          <template #title>Rejected</template>
          {{ request.rejection_reason }}
        </VAlert>
        <VAlert v-if="request.cancellation_reason" type="warning" variant="tonal" class="mb-5">
          <template #title>Cancelled</template>
          {{ request.cancellation_reason }}
        </VAlert>

        <!-- AI Explanation -->
        <VAlert v-if="request.ai_explanation" type="info" variant="tonal" class="mb-5">
          <template #prepend>
            <VIcon icon="tabler-robot" />
          </template>
          <template #title>AI Analysis</template>
          {{ request.ai_explanation }}
        </VAlert>

        <!-- Documents -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
            Supporting Documents
            <VBtn v-if="isOwner" size="small" variant="tonal" color="primary" prepend-icon="tabler-upload" @click="uploadDialog = true">Upload</VBtn>
          </VCardTitle>
          <VDivider />
          <VList v-if="request.documents.length">
            <VListItem
              v-for="doc in request.documents"
              :key="doc.id"
              :subtitle="doc.uploaded_at"
            >
              <template #title>
                <span class="text-body-2">{{ doc.filename }}</span>
              </template>
              <template #prepend>
                <VAvatar color="primary" variant="tonal" size="36" rounded class="me-2">
                  <VIcon icon="tabler-file" size="18" />
                </VAvatar>
              </template>
              <template #append>
                <div class="d-flex gap-1">
                  <VBtn icon="tabler-eye" size="x-small" variant="text" color="primary" :href="doc.view_url" target="_blank" />
                  <VBtn icon="tabler-download" size="x-small" variant="text" color="default" :href="doc.download_url" />
                </div>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No documents attached.</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Approval Chain -->
      <VCol cols="12" md="5">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Approval Timeline</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-if="request.approval_chain.length" class="d-flex flex-column gap-4">
              <div v-for="step in request.approval_chain" :key="step.level" class="d-flex gap-3">
                <div class="d-flex flex-column align-center" style="min-width: 36px;">
                  <VAvatar :color="stepColor(step.status)" variant="tonal" size="36" rounded>
                    <VIcon :icon="stepIcon(step.status)" size="18" />
                  </VAvatar>
                  <div v-if="step.level < request.approval_chain.length" class="flex-1-1 mt-1" style="width: 2px; min-height: 24px; background: rgba(0,0,0,.12);" />
                </div>
                <div class="pb-3">
                  <p class="text-body-2 font-weight-medium mb-0">{{ step.level_name }}</p>
                  <VChip :color="stepColor(step.status)" size="x-small" variant="tonal" class="mt-1">{{ step.status }}</VChip>
                  <p v-if="step.actioned_at" class="text-caption text-medium-emphasis mt-1 mb-0">{{ formatDate(step.actioned_at) }}</p>
                  <p v-if="step.comment" class="text-caption mt-1 mb-0">{{ step.comment }}</p>
                </div>
              </div>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis text-center py-4">No approval steps yet.</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Cancel Dialog -->
    <VDialog v-model="cancelDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Cancel Leave Request</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-4">Are you sure you want to cancel this leave request? This cannot be undone.</p>
          <VTextarea
            v-model="cancelForm.reason"
            label="Reason for cancellation"
            variant="outlined"
            density="comfortable"
            rows="3"
            :error-messages="cancelForm.errors.reason"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="cancelDialog = false">Keep Request</VBtn>
          <VBtn color="error" variant="flat" :loading="cancelForm.processing" @click="submitCancel">Cancel Request</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Upload Dialog -->
    <VDialog v-model="uploadDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Upload Document</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect
            v-model="uploadType"
            :items="[
              { title: 'Medical Certificate', value: 'medical_certificate' },
              { title: 'Supporting Document', value: 'supporting_document' },
              { title: 'Other', value: 'other' },
            ]"
            item-title="title"
            item-value="value"
            label="Document Type"
            variant="outlined"
            density="comfortable"
            class="mb-4"
          />
          <VFileInput
            v-model="uploadFile"
            label="Choose File"
            variant="outlined"
            density="comfortable"
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            prepend-icon=""
            prepend-inner-icon="tabler-paperclip"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="uploadDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="uploading" :disabled="!uploadFile" @click="submitUpload">Upload</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
