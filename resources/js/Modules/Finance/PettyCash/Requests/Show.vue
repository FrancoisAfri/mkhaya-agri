<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface PCRequest {
    id: number; uuid: string; reference_number: string
    requestor: string | null; custodian: string | null; approver: string | null; float_name: string | null
    purpose: string; category: string | null
    amount_requested: number; amount_approved: number | null; amount_issued: number | null
    amount_spent: number | null; amount_returned: number | null
    status: string; rejection_reason: string | null; notes: string | null
    risk_score: number | null; risk_level: string | null; ai_flags: any[] | null
    receipt_submitted: boolean; receipt_overdue: boolean; receipt_due_at: string | null
    requested_at: string | null; approved_at: string | null; issued_at: string | null; settled_at: string | null
}

interface Attachment { id: number; original_name: string; url: string; type: string; created_at: string }
interface Approval { approver: string | null; action: string; comments: string | null; acted_at: string }
interface Violation { id: number; type: string; description: string; severity: string; status: string }

const props = defineProps<{
    request: PCRequest
    attachments: Attachment[]
    approvals: Approval[]
    policyViolations: Violation[]
}>()

const approveDialog = ref(false)
const rejectDialog = ref(false)
const settleDialog = ref(false)
const receiptDialog = ref(false)

const approveForm = useForm({ comments: '' })
const rejectForm = useForm({ reason: '' })
const settleForm = useForm({ amount_spent: null as number | null, amount_returned: 0, notes: '' })
const receiptForm = useForm({ receipt: null as File | null })

const approve = () => approveForm.post(route('petty-cash.requests.approve', props.request.uuid), { onSuccess: () => { approveDialog.value = false } })
const reject = () => rejectForm.post(route('petty-cash.requests.reject', props.request.uuid), { onSuccess: () => { rejectDialog.value = false } })
const issue = () => useForm({}).post(route('petty-cash.requests.issue', props.request.uuid))
const settle = () => settleForm.post(route('petty-cash.requests.settle', props.request.uuid), { onSuccess: () => { settleDialog.value = false } })
const uploadReceipt = () => {
    const form = useForm({ receipt: receiptForm.receipt })
    form.post(route('petty-cash.requests.receipt.upload', props.request.uuid), { onSuccess: () => { receiptDialog.value = false } })
}

const fmt = (n: number | null) => n == null ? '—' : 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const statusColor = (s: string) => ({
    draft: 'grey', pending: 'blue', approved: 'teal', rejected: 'error',
    issued: 'orange', settled: 'success', cancelled: 'grey',
})[s] ?? 'grey'

const severityColor = (s: string) => ({
    low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error',
})[s] ?? 'grey'

const riskColor = (r: string | null) => ({
    low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error',
})[r ?? ''] ?? 'grey'

const actionColor = (a: string) => ({
    approved: 'success', rejected: 'error', issued: 'orange', settled: 'success',
})[a] ?? 'grey'
</script>

<template>
    <div class="pa-4">
        <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.requests.index')" class="mb-3">
            {{ t('petty_cash_requests_show.back') }}
        </VBtn>

        <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
            <div>
                <h1 class="text-h5 font-weight-bold">{{ request.reference_number }}</h1>
                <p class="text-body-2 text-medium-emphasis">{{ request.purpose }}</p>
            </div>
            <div class="d-flex ga-2 flex-wrap">
                <VChip :color="statusColor(request.status)" variant="flat">{{ request.status }}</VChip>
                <VChip v-if="request.risk_level" :color="riskColor(request.risk_level)" variant="tonal" size="small">
                    {{ t('petty_cash_requests_show.risk', { level: request.risk_level }) }}
                </VChip>
                <!-- Action Buttons -->
                <template v-if="request.status === 'pending'">
                    <VBtn color="success" @click="approveDialog = true">{{ t('petty_cash_requests_show.actions.approve')
                        }}</VBtn>
                    <VBtn color="error" variant="outlined" @click="rejectDialog = true">{{
                        t('petty_cash_requests_show.actions.reject') }}</VBtn>
                </template>
                <VBtn v-if="request.status === 'approved'" color="orange" @click="issue">{{
                    t('petty_cash_requests_show.actions.issue_cash') }}</VBtn>
                <VBtn v-if="request.status === 'issued'" color="primary" @click="settleDialog = true">{{
                    t('petty_cash_requests_show.actions.settle') }}</VBtn>
                <VBtn v-if="request.status === 'issued' && !request.receipt_submitted" variant="outlined"
                    prepend-icon="tabler-upload" @click="receiptDialog = true">
                    {{ t('petty_cash_requests_show.actions.upload_receipt') }}
                </VBtn>
            </div>
        </div>

        <!-- Receipt Overdue Banner -->
        <VAlert v-if="request.receipt_overdue" type="error" variant="tonal" class="mb-4" density="compact" prominent>
            <strong>{{ t('petty_cash_requests_show.receipt_overdue', { date: request.receipt_due_at }) }}</strong>
        </VAlert>

        <VRow>
            <!-- Main Info -->
            <VCol cols="12" md="8">
                <VCard elevation="0" border class="mb-4">
                    <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{
                        t('petty_cash_requests_show.details.title') }}</VCardTitle>
                    <VDivider />
                    <VCardText class="pa-4">
                        <VRow dense>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.requestor') }}</span>
                                <div class="text-body-2 font-weight-medium">{{ request.requestor ?? '—' }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.float') }}</span>
                                <div class="text-body-2 font-weight-medium">{{ request.float_name ?? '—' }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.custodian') }}</span>
                                <div class="text-body-2 font-weight-medium">{{ request.custodian ?? '—' }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.category') }}</span>
                                <div class="text-body-2 font-weight-medium">{{ request.category ??
                                    t('petty_cash_requests_show.details.uncategorised') }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.amount_requested') }}</span>
                                <div class="text-h6 font-weight-bold text-primary">{{ fmt(request.amount_requested) }}
                                </div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.amount_approved') }}</span>
                                <div class="text-body-2">{{ fmt(request.amount_approved) }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.amount_issued') }}</span>
                                <div class="text-body-2">{{ fmt(request.amount_issued) }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.amount_spent') }}</span>
                                <div class="text-body-2">{{ fmt(request.amount_spent) }}</div>
                            </VCol>
                            <VCol cols="6"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.amount_returned') }}</span>
                                <div class="text-body-2">{{ fmt(request.amount_returned) }}</div>
                            </VCol>
                            <VCol cols="12" v-if="request.notes"><span class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.details.notes') }}</span>
                                <div class="text-body-2">{{ request.notes }}</div>
                            </VCol>
                            <VCol cols="12" v-if="request.rejection_reason">
                                <VAlert type="error" variant="tonal" density="compact" class="mt-1">{{
                                    request.rejection_reason
                                    }}</VAlert>
                            </VCol>
                        </VRow>
                    </VCardText>
                </VCard>

                <!-- AI Flags -->
                <VCard v-if="request.ai_flags?.length" elevation="0" border class="mb-4">
                    <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
                        <VIcon class="mr-1" color="deep-orange">tabler-robot</VIcon> {{
                            t('petty_cash_requests_show.ai_flags.title') }}
                    </VCardTitle>
                    <VDivider />
                    <VList density="compact">
                        <VListItem v-for="(flag, i) in request.ai_flags" :key="i">
                            <template #prepend>
                                <VChip :color="severityColor(flag.severity)" size="x-small" variant="flat" class="mr-2">
                                    {{ flag.severity }}</VChip>
                            </template>
                            <VListItemTitle class="text-body-2">{{ flag.description }}</VListItemTitle>
                        </VListItem>
                    </VList>
                </VCard>

                <!-- Policy Violations -->
                <VCard v-if="policyViolations.length" elevation="0" border class="mb-4">
                    <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
                        <VIcon class="mr-1" color="error">tabler-shield-exclamation</VIcon> {{
                            t('petty_cash_requests_show.policy_violations.title') }}
                    </VCardTitle>
                    <VDivider />
                    <VList density="compact">
                        <VListItem v-for="violation in policyViolations" :key="violation.id">
                            <template #prepend>
                                <VChip :color="severityColor(violation.severity)" size="x-small" variant="flat"
                                    class="mr-2">{{ violation.severity }}</VChip>
                            </template>
                            <VListItemTitle class="text-body-2">{{ violation.description }}</VListItemTitle>
                            <template #append>
                                <VChip size="x-small" :color="violation.status === 'resolved' ? 'success' : 'grey'"
                                    variant="tonal">{{ violation.status }}</VChip>
                            </template>
                        </VListItem>
                    </VList>
                </VCard>
            </VCol>

            <!-- Right Rail -->
            <VCol cols="12" md="4">
                <!-- Timeline -->
                <VCard elevation="0" border class="mb-4">
                    <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{
                        t('petty_cash_requests_show.approval_log.title') }}</VCardTitle>
                    <VDivider />
                    <VList density="compact">
                        <VListItem v-for="(approval, i) in approvals" :key="i">
                            <template #prepend>
                                <VIcon :color="actionColor(approval.action)" size="18" class="mr-2">tabler-user-check
                                </VIcon>
                            </template>
                            <VListItemTitle class="text-body-2">{{ approval.approver }}</VListItemTitle>
                            <VListItemSubtitle class="text-caption">{{ approval.action }} · {{ approval.acted_at }}
                            </VListItemSubtitle>
                            <div v-if="approval.comments" class="text-caption text-medium-emphasis pl-6">{{
                                approval.comments }}
                            </div>
                        </VListItem>
                        <VListItem v-if="!approvals.length">
                            <VListItemSubtitle class="text-caption">{{
                                t('petty_cash_requests_show.approval_log.no_actions') }}
                            </VListItemSubtitle>
                        </VListItem>
                    </VList>
                </VCard>

                <!-- Attachments -->
                <VCard elevation="0" border>
                    <VCardTitle
                        class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex justify-space-between align-center">
                        <span>{{ t('petty_cash_requests_show.attachments.title') }}</span>
                        <VBtn size="small" variant="text" prepend-icon="tabler-upload" @click="receiptDialog = true">{{
                            t('petty_cash_requests_show.attachments.upload') }}</VBtn>
                    </VCardTitle>
                    <VDivider />
                    <VList density="compact">
                        <VListItem v-for="attachment in attachments" :key="attachment.id" :href="attachment.url"
                            target="_blank">
                            <template #prepend>
                                <VIcon size="18" class="mr-2">tabler-file-text</VIcon>
                            </template>
                            <VListItemTitle class="text-body-2">{{ attachment.original_name }}</VListItemTitle>
                            <VListItemSubtitle class="text-caption">{{ attachment.type }}</VListItemSubtitle>
                            <template #append>
                                <VIcon size="16">tabler-external-link</VIcon>
                            </template>
                        </VListItem>
                        <VListItem v-if="!attachments.length">
                            <VListItemSubtitle class="text-caption text-medium-emphasis">{{
                                t('petty_cash_requests_show.attachments.no_attachments') }}</VListItemSubtitle>
                        </VListItem>
                    </VList>
                </VCard>
            </VCol>
        </VRow>

        <!-- Approve Dialog -->
        <VDialog v-model="approveDialog" max-width="400">
            <VCard>
                <VCardTitle class="pa-4">{{ t('petty_cash_requests_show.dialog.approve_title') }}</VCardTitle>
                <VDivider />
                <VCardText class="pa-4">
                    <VTextarea v-model="approveForm.comments"
                        :label="t('petty_cash_requests_show.dialog.approve_comments')" variant="outlined" rows="3"
                        density="comfortable" />
                </VCardText>
                <VCardActions class="pa-4 pt-0">
                    <VSpacer />
                    <VBtn variant="text" @click="approveDialog = false">{{ t('petty_cash_requests_show.dialog.cancel')
                        }}</VBtn>
                    <VBtn color="success" :loading="approveForm.processing" @click="approve">{{
                        t('petty_cash_requests_show.dialog.approve_btn') }}</VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <!-- Reject Dialog -->
        <VDialog v-model="rejectDialog" max-width="400">
            <VCard>
                <VCardTitle class="pa-4">{{ t('petty_cash_requests_show.dialog.reject_title') }}</VCardTitle>
                <VDivider />
                <VCardText class="pa-4">
                    <VTextarea v-model="rejectForm.reason" :label="t('petty_cash_requests_show.dialog.reject_reason')"
                        variant="outlined" rows="3" density="comfortable" :error-messages="rejectForm.errors.reason" />
                </VCardText>
                <VCardActions class="pa-4 pt-0">
                    <VSpacer />
                    <VBtn variant="text" @click="rejectDialog = false">{{ t('petty_cash_requests_show.dialog.cancel') }}
                    </VBtn>
                    <VBtn color="error" :loading="rejectForm.processing" @click="reject">{{
                        t('petty_cash_requests_show.dialog.reject_btn') }}</VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <!-- Settle Dialog -->
        <VDialog v-model="settleDialog" max-width="400">
            <VCard>
                <VCardTitle class="pa-4">{{ t('petty_cash_requests_show.dialog.settle_title') }}</VCardTitle>
                <VDivider />
                <VCardText class="pa-4">
                    <VTextField v-model="settleForm.amount_spent"
                        :label="t('petty_cash_requests_show.dialog.amount_spent')" type="number" min="0"
                        variant="outlined" density="comfortable" class="mb-3"
                        :error-messages="settleForm.errors.amount_spent" />
                    <VTextField v-model="settleForm.amount_returned"
                        :label="t('petty_cash_requests_show.dialog.change_returned')" type="number" min="0"
                        variant="outlined" density="comfortable" class="mb-3"
                        :error-messages="settleForm.errors.amount_returned" />
                    <VTextarea v-model="settleForm.notes" :label="t('petty_cash_requests_show.dialog.settle_notes')"
                        variant="outlined" rows="2" density="comfortable" />
                </VCardText>
                <VCardActions class="pa-4 pt-0">
                    <VSpacer />
                    <VBtn variant="text" @click="settleDialog = false">{{ t('petty_cash_requests_show.dialog.cancel') }}
                    </VBtn>
                    <VBtn color="primary" :loading="settleForm.processing" @click="settle">{{
                        t('petty_cash_requests_show.dialog.settle_btn') }}</VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <!-- Receipt Upload Dialog -->
        <VDialog v-model="receiptDialog" max-width="400">
            <VCard>
                <VCardTitle class="pa-4">{{ t('petty_cash_requests_show.dialog.receipt_title') }}</VCardTitle>
                <VDivider />
                <VCardText class="pa-4">
                    <VFileInput v-model="receiptForm.receipt" :label="t('petty_cash_requests_show.dialog.receipt_file')"
                        accept="application/pdf,image/*" variant="outlined" density="comfortable"
                        prepend-icon="tabler-paperclip" />
                </VCardText>
                <VCardActions class="pa-4 pt-0">
                    <VSpacer />
                    <VBtn variant="text" @click="receiptDialog = false">{{ t('petty_cash_requests_show.dialog.cancel')
                        }}</VBtn>
                    <VBtn color="primary" @click="uploadReceipt">{{ t('petty_cash_requests_show.dialog.upload_btn') }}
                    </VBtn>
                </VCardActions>
            </VCard>
        </VDialog>
    </div>
</template>
