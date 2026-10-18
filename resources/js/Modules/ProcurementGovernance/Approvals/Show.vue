<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { date, dateTime, humanise, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  approval: any
  steps: any[]
  subject: any | null
  mayDecide: boolean
  context: any
}>()

const deciding = ref<'APPROVED' | 'REJECTED' | null>(null)
const form = useForm({ decision: '', comment: '' })

const activeStep = computed(() => props.steps.find(s => s.status === 'ACTIVE'))

/** A rejection must carry its reason; an approval need not, but is better with one. */
const commentRequired = computed(() => deciding.value === 'REJECTED')

function open(decision: 'APPROVED' | 'REJECTED') {
  deciding.value = decision
  form.decision = decision
  form.comment = ''
}

function submit() {
  form.post(`/procurement-governance/approvals/${props.approval.uuid}/decide`, {
    preserveScroll: true,
    onSuccess: () => { deciding.value = null; form.reset() },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <Link
        href="/procurement-governance/approvals"
        class="text-caption text-primary text-decoration-none d-inline-flex align-center mb-1"
      >
        <VIcon icon="mdi-arrow-left" size="14" class="me-1" />{{ t('procurement_governance.approvals.title') }}
      </Link>
      <div class="d-flex align-center ga-2 mb-1 flex-wrap">
        <span class="text-caption font-weight-bold text-medium-emphasis">
          {{ approval.subject_reference ?? humanise(approval.subject_type) }}
        </span>
        <StatusChip :status="approval.status" />
      </div>
      <h1 class="text-h5 font-weight-bold">{{ approval.subject_label ?? subject?.title }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('procurement_governance.approvals.step_of', {
          n: approval.current_step, total: approval.total_steps,
        }) }}
        <template v-if="approval.chain_code"> · {{ approval.chain_code }}</template>
        <template v-if="approval.amount"> · {{ money(approval.amount) }}</template>
      </p>
    </div>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard v-if="subject" elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ subject.type }}
          </VCardTitle>
          <VCardText class="pt-0 text-body-2">
            <div v-if="subject.value" class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('procurement_governance.common.value') }}</span>
              <span class="font-weight-medium">{{ money(subject.value) }}</span>
            </div>
            <div v-if="subject.risk_level" class="d-flex justify-space-between py-1 align-center">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.risk') }}</span>
              <StatusChip :status="subject.risk_level" size="x-small" />
            </div>
            <div v-if="subject.compliance" class="d-flex justify-space-between py-1 align-center">
              <span class="text-medium-emphasis">{{ t('procurement_governance.deviations.compliance') }}</span>
              <StatusChip :status="subject.compliance" size="x-small" />
            </div>

            <template v-for="field in ['reason', 'justification', 'narrative', 'conditions']" :key="field">
              <div v-if="subject[field]" class="mt-3">
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1">
                  {{ t(`procurement_governance.approvals.${field}`) }}
                </div>
                <div style="white-space: pre-wrap">{{ subject[field] }}</div>
              </div>
            </template>
          </VCardText>
        </VCard>

        <!-- The decision, only when this person may actually make it. -->
        <VCard v-if="mayDecide && approval.status === 'PENDING'" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.approvals.your_decision') }}
          </VCardTitle>
          <VCardSubtitle v-if="activeStep" class="text-caption pb-2">
            {{ activeStep.name }}
            <template v-if="activeStep.quorum > 1">
              · {{ t('procurement_governance.approvals.quorum_progress', {
                have: activeStep.approvals_received, need: activeStep.quorum,
              }) }}
            </template>
          </VCardSubtitle>
          <VCardText class="pt-0 d-flex ga-2 flex-wrap">
            <VBtn color="success" prepend-icon="mdi-check" @click="open('APPROVED')">
              {{ t('procurement_governance.approvals.approve') }}
            </VBtn>
            <VBtn color="error" variant="outlined" prepend-icon="mdi-close" @click="open('REJECTED')">
              {{ t('procurement_governance.approvals.reject') }}
            </VBtn>
          </VCardText>
        </VCard>

        <!-- Separation of duties is enforced server-side; explaining it here avoids a dead button. -->
        <VAlert
          v-else-if="approval.status === 'PENDING'" type="info" variant="tonal"
          class="text-body-2" icon="mdi-information-outline"
        >
          {{ t('procurement_governance.approvals.cannot_decide') }}
        </VAlert>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.approvals.chain') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem
                v-for="step in steps" :key="step.id"
                :dot-color="{
                  APPROVED: 'success', REJECTED: 'error', ACTIVE: 'warning',
                  ESCALATED: 'warning', SKIPPED: 'grey',
                }[step.status as string] ?? 'grey'"
                size="x-small"
              >
                <div class="text-body-2 font-weight-medium">
                  {{ step.step_no }}. {{ step.name }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ step.approver_label ?? '—' }} · {{ humanise(step.status) }}
                  <template v-if="step.decided_by"> · {{ step.decided_by }}</template>
                  <template v-if="step.decided_at"> · {{ dateTime(step.decided_at) }}</template>
                </div>
                <div v-if="step.due_at && step.status === 'ACTIVE'" class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.approvals.due') }} {{ date(step.due_at) }}
                </div>
                <div v-if="step.comment" class="text-caption mt-1">{{ step.comment }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="!!deciding" max-width="520" @update:model-value="deciding = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ deciding === 'APPROVED'
            ? t('procurement_governance.approvals.approve')
            : t('procurement_governance.approvals.reject') }}
        </VCardTitle>
        <VCardText>
          <VTextarea
            v-model="form.comment"
            :label="t('procurement_governance.common.comment')"
            :hint="commentRequired
              ? t('procurement_governance.approvals.reason_required')
              : t('procurement_governance.common.comment_optional')"
            persistent-hint
            :error-messages="form.errors.comment"
            variant="outlined" rows="3" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="deciding = null">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            :color="deciding === 'APPROVED' ? 'success' : 'error'"
            :loading="form.processing"
            :disabled="form.processing || (commentRequired && !form.comment.trim())"
            @click="submit"
          >
            {{ t('procurement_governance.common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
