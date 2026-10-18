<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { count, date, dateTime, humanise, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  deviation: any
  history: any[]
  preflight: any | null
  complianceCheck: any | null
  complianceResults: any[]
  approval: any | null
  approvalSteps: any[]
  documents: any[]
  supplier: any | null
  nextStatuses: string[]
  types: any[]
  riskLevels: string[]
  can: { edit: boolean; submit: boolean; approve: boolean }
}>()

const submitting = ref(false)
const uploading = ref(false)

const submitForm = useForm({ accept_warnings: false, acceptance_reason: '' })
const uploadForm = useForm({ file: null as File | null, title: '', category_code: '', expires_at: '' })

const base = computed(() => `/procurement-governance/deviations/${props.deviation.uuid}`)

const isDraft = computed(() => ['DRAFT', 'REJECTED'].includes(props.deviation.status))

/** The live §5.4 verdict on a draft, so nothing is a surprise at submit time. */
const preflightBlocking = computed(() => props.preflight?.failures ?? [])
const preflightWarnings = computed(() => props.preflight?.warnings ?? [])
const preflightSkipped = computed(() => props.preflight?.skipped ?? [])

function doSubmit() {
  submitForm.post(`${base.value}/submit`, {
    preserveScroll: true,
    onSuccess: () => { submitting.value = false; submitForm.reset() },
  })
}

function moveTo(status: string) {
  router.post(`${base.value}/transition`, { status }, { preserveScroll: true })
}

function upload() {
  uploadForm.post(`${base.value}/documents`, {
    preserveScroll: true, forceFormData: true,
    onSuccess: () => { uploading.value = false; uploadForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <span class="text-caption font-weight-bold text-medium-emphasis">{{ deviation.reference }}</span>
          <StatusChip :status="deviation.status" />
          <StatusChip :status="deviation.risk_level" :label="t('procurement_governance.deviations.risk_n', {
            level: humanise(deviation.risk_level),
          })" />
          <StatusChip v-if="deviation.compliance_result" :status="deviation.compliance_result" />
        </div>
        <h1 class="text-h5 font-weight-bold">{{ deviation.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ money(deviation.procurement_value, deviation.currency) }}
          <template v-if="deviation.supplier_name"> · {{ deviation.supplier_name }}</template>
          <template v-if="deviation.deviation_type_code"> · {{ humanise(deviation.deviation_type_code) }}</template>
        </p>
      </div>

      <div class="d-flex flex-wrap ga-2">
        <VBtn variant="text" size="small" prepend-icon="mdi-paperclip" @click="uploading = true">
          {{ t('procurement_governance.common.attach_document') }}
        </VBtn>
        <VBtn
          v-if="isDraft && can.submit" color="primary" prepend-icon="mdi-send-outline"
          :disabled="!!preflightBlocking.length" @click="submitting = true"
        >
          {{ t('procurement_governance.common.submit') }}
        </VBtn>
        <VBtn
          v-for="status in nextStatuses.filter(s => ['WITHDRAWN', 'CLOSED'].includes(s))" :key="status"
          size="small" variant="outlined" @click="moveTo(status)"
        >
          {{ humanise(status) }}
        </VBtn>
      </div>
    </div>

    <!-- §5.4: PASS / WARNING / FAIL, stated plainly and with the individual findings. -->
    <VCard v-if="isDraft && preflight" elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="d-flex align-center ga-2 text-body-1 font-weight-bold py-3">
        {{ t('procurement_governance.deviations.compliance_check') }}
        <StatusChip :status="preflight.result" />
      </VCardTitle>
      <VCardText class="pt-0">
        <div v-if="!preflightBlocking.length && !preflightWarnings.length && !preflightSkipped.length"
             class="text-body-2 text-medium-emphasis">
          {{ t('procurement_governance.deviations.no_rules') }}
        </div>

        <VList v-else density="compact" class="py-0">
          <VListItem v-for="(message, i) in preflightBlocking" :key="`f${i}`">
            <template #prepend><VIcon icon="mdi-alert-circle-outline" color="error" size="20" /></template>
            <VListItemTitle class="text-body-2">{{ message }}</VListItemTitle>
          </VListItem>
          <VListItem v-for="(message, i) in preflightWarnings" :key="`w${i}`">
            <template #prepend><VIcon icon="mdi-alert-outline" color="warning" size="20" /></template>
            <VListItemTitle class="text-body-2">{{ message }}</VListItemTitle>
          </VListItem>
          <!-- A skipped rule is not a passed rule; it is stated as such. -->
          <VListItem v-for="(message, i) in preflightSkipped" :key="`s${i}`">
            <template #prepend><VIcon icon="mdi-help-circle-outline" color="grey" size="20" /></template>
            <VListItemTitle class="text-body-2 text-medium-emphasis">{{ message }}</VListItemTitle>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.deviations.motivation') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <template v-for="field in ['reason', 'business_justification', 'circumstances', 'risk_assessment', 'proposed_alternative', 'financial_impact']"
                      :key="field">
              <div v-if="deviation[field]" class="mb-4">
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1">
                  {{ t(`procurement_governance.deviations.fields.${field}`) }}
                </div>
                <div class="text-body-2" style="white-space: pre-wrap">{{ deviation[field] }}</div>
              </div>
            </template>
            <div v-if="deviation.financial_impact_amount" class="text-body-2">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.financial_impact_amount') }}:</span>
              {{ money(deviation.financial_impact_amount, deviation.currency) }}
            </div>
          </VCardText>
        </VCard>

        <VCard v-if="complianceResults.length" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.deviations.recorded_check') }}
            <StatusChip :status="complianceCheck?.result" />
            <span v-if="complianceCheck?.rule_set_code" class="text-caption text-medium-emphasis">
              {{ complianceCheck.rule_set_code }} v{{ complianceCheck.rule_set_version }}
            </span>
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="result in complianceResults" :key="result.id">
              <template #prepend>
                <VIcon
                  :icon="{
                    FAIL: 'mdi-alert-circle-outline', WARNING: 'mdi-alert-outline',
                    SKIPPED: 'mdi-help-circle-outline', PASS: 'mdi-check-circle-outline',
                  }[result.outcome as string]"
                  :color="{
                    FAIL: 'error', WARNING: 'warning', SKIPPED: 'grey', PASS: 'success',
                  }[result.outcome as string]"
                  size="20"
                />
              </template>
              <VListItemTitle class="text-body-2">{{ result.rule_name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ result.message ?? humanise(result.rule_type) }}</VListItemSubtitle>
              <div v-if="result.acknowledged_at" class="text-caption text-warning mt-1">
                {{ t('procurement_governance.deviations.warning_accepted', { reason: result.acknowledgement_reason }) }}
              </div>
            </VListItem>
          </VList>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.common.documents') }}
          </VCardTitle>
          <div v-if="!documents.length" class="text-center py-8 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.common.no_documents') }}</div>
          </div>
          <VList v-else density="compact">
            <VListItem v-for="doc in documents" :key="doc.id"
                       :href="`/procurement-governance/documents/${doc.uuid}`" class="procgov-link-item">
              <VListItemTitle class="text-body-2">{{ doc.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ doc.category_name ?? '—' }} · {{ humanise(doc.confidentiality) }}
                <template v-if="doc.expires_at"> · {{ t('procurement_governance.common.expires') }} {{ date(doc.expires_at) }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard v-if="supplier" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.deviations.supplier_compliance') }}
          </VCardTitle>
          <VCardText class="pt-0 text-body-2">
            <div class="d-flex justify-space-between mb-1">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.active') }}</span>
              <span>{{ supplier.is_active ? t('procurement_governance.common.yes') : t('procurement_governance.common.no') }}</span>
            </div>
            <div class="d-flex justify-space-between mb-1">
              <span class="text-medium-emphasis">{{ t('procurement_governance.tenders.bbbee_level') }}</span>
              <span>{{ supplier.bbbee_level ?? '—' }}</span>
            </div>
            <div class="d-flex justify-space-between mb-1">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.risk_flags') }}</span>
              <span :class="{ 'text-error font-weight-medium': supplier.open_risk_flags > 0 }">
                {{ count(supplier.open_risk_flags) }}
              </span>
            </div>
            <div class="d-flex justify-space-between">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.expired_docs') }}</span>
              <span :class="{ 'text-error font-weight-medium': supplier.expired_documents > 0 }">
                {{ count(supplier.expired_documents) }}
              </span>
            </div>
          </VCardText>
        </VCard>

        <VCard v-if="approval" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.common.approval') }}
            <StatusChip :status="approval.status" />
          </VCardTitle>
          <VCardText class="pt-0">
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem
                v-for="step in approvalSteps" :key="step.id"
                :dot-color="step.status === 'APPROVED' ? 'success'
                  : step.status === 'REJECTED' ? 'error'
                    : step.status === 'ACTIVE' ? 'warning' : 'grey'"
                size="x-small"
              >
                <div class="text-body-2 font-weight-medium">{{ step.name }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ step.approver_label ?? '—' }} · {{ humanise(step.status) }}
                  <template v-if="step.decided_at"> · {{ date(step.decided_at) }}</template>
                </div>
                <div v-if="step.comment" class="text-caption mt-1">{{ step.comment }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.common.history') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem v-for="(entry, i) in history" :key="i" dot-color="primary" size="x-small">
                <div class="text-body-2 font-weight-medium">
                  {{ humanise(entry.from_status) }} → {{ humanise(entry.to_status) }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ dateTime(entry.occurred_at) }}
                  <template v-if="entry.actor_label"> · {{ entry.actor_label }}</template>
                </div>
                <div v-if="entry.comment" class="text-caption mt-1">{{ entry.comment }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Submit, with the §5.4 warning acceptance where the check came back WARNING -->
    <VDialog v-model="submitting" max-width="560">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.deviations.submit_title') }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-3">{{ t('procurement_governance.deviations.submit_body') }}</p>

          <template v-if="preflightWarnings.length">
            <VAlert type="warning" variant="tonal" density="compact" class="mb-3">
              <div class="text-body-2 font-weight-medium mb-1">
                {{ t('procurement_governance.deviations.warnings_present') }}
              </div>
              <ul class="text-body-2 ps-4 mb-0">
                <li v-for="(w, i) in preflightWarnings" :key="i">{{ w }}</li>
              </ul>
            </VAlert>
            <VCheckbox
              v-model="submitForm.accept_warnings"
              :label="t('procurement_governance.deviations.accept_warnings')"
              density="compact" hide-details class="mb-2"
            />
            <VTextarea
              v-if="submitForm.accept_warnings"
              v-model="submitForm.acceptance_reason"
              :label="t('procurement_governance.deviations.acceptance_reason')"
              :hint="t('procurement_governance.deviations.acceptance_reason_hint')" persistent-hint
              variant="outlined" rows="2" auto-grow
            />
          </template>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="submitting = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="submitForm.processing"
            :disabled="submitForm.accept_warnings && !submitForm.acceptance_reason.trim()"
            @click="doSubmit"
          >
            {{ t('procurement_governance.common.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="uploading" max-width="520">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.common.attach_document') }}</VCardTitle>
        <VCardText>
          <VFileInput
            :label="t('procurement_governance.common.file')"
            variant="outlined" density="comfortable" class="mb-3"
            :error-messages="uploadForm.errors.file"
            @update:model-value="files => uploadForm.file = Array.isArray(files) ? files[0] : files"
          />
          <VTextField
            v-model="uploadForm.title" :label="t('procurement_governance.common.title')"
            variant="outlined" density="comfortable" class="mb-3"
          />
          <VTextField
            v-model="uploadForm.expires_at" type="date"
            :label="t('procurement_governance.common.expires')"
            variant="outlined" density="comfortable"
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="uploading = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="uploadForm.processing" :disabled="!uploadForm.file" @click="upload">
            {{ t('procurement_governance.common.upload') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.min-width-0 { min-inline-size: 0; }
.procgov-link-item { cursor: pointer; }
</style>
