<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Cause = {
  uuid: string; category: string; cause_type: string
  description: string; evidence: string | null; depth: number | null
}

type Investigation = {
  uuid: string; revision: number; method: string; required_method: string
  minimum_causes: number; potential_severity: number | null; status: string
  summary: string | null; sequence_of_events: string | null
  reopen_reason: string | null; opened_at: string; approved_at: string | null
  incident: { id: number; uuid: string; reference: string; status: string; description: string } | null
  causes: Cause[]; shortfalls: string[]; layers: string[]
}

const props = defineProps<{
  investigation: Investigation
  history: Record<string, any>[]
  blockers: string[]
  evidence: Record<string, any>[]
  canInvestigate?: boolean
  canApprove?: boolean
}>()

const isDraft = computed(() => props.investigation.status === 'DRAFT')
const isApproved = computed(() => props.investigation.status === 'APPROVED')

/** DRAFT → PENDING_APPROVAL → APPROVED → CLOSED, as a step indicator. */
const STEPS = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'CLOSED']
const currentStep = computed(() => {
  if (props.investigation.incident?.status === 'CLOSED') return 3

  return Math.max(0, STEPS.indexOf(props.investigation.status))
})

/**
 * Causes arranged as ICAM's ladder, deepest last.
 *
 * The whole point of showing it this way: when only the individual-action rung has entries, the
 * investigation has stopped at the person and not reached the system that let them — and that is
 * obvious at a glance rather than discovered at submission.
 */
const ladder = computed(() =>
  [...props.investigation.layers].reverse().map(layer => ({
    layer,
    causes: props.investigation.causes.filter(c => c.category === layer),
  })))

const onlyBlamesPeople = computed(() => {
  const roots = props.investigation.causes.filter(c => c.cause_type === 'ROOT')

  return roots.length > 0 && roots.every(c => c.category === 'INDIVIDUAL_TEAM_ACTION')
})

const addingTo = ref<string | null>(null)
const confirming = ref<'submit' | 'approve' | 'close' | 'reopen' | null>(null)

const cause = useForm({ category: '', cause_type: 'CONTRIBUTING', description: '', evidence: '' })
const narrative = useForm({
  summary: props.investigation.summary ?? '',
  sequence_of_events: props.investigation.sequence_of_events ?? '',
})
const action = useForm({})
const reopen = useForm({ reason: '' })

const openCauseForm = (layer: string) => {
  addingTo.value = layer
  cause.category = layer
  cause.cause_type = 'CONTRIBUTING'
  cause.description = ''
  cause.evidence = ''
  cause.clearErrors()
}

const saveCause = () => cause.post(`/sheq/investigations/${props.investigation.uuid}/causes`, {
  preserveScroll: true,
  onSuccess: () => { addingTo.value = null },
})

const saveNarrative = () => narrative.post(`/sheq/investigations/${props.investigation.uuid}`, {
  preserveScroll: true,
})

const post = (path: string) => {
  action.post(path, { preserveScroll: true, onSuccess: () => { confirming.value = null } })
}

const submitReopen = () => {
  reopen.post(`/sheq/investigations/${props.investigation.incident?.uuid}/reopen`, {
    onSuccess: () => { confirming.value = null },
  })
}

const layerColour = (layer: string) => ({
  ORGANISATIONAL: 'deep-purple',
  TASK_ENVIRONMENT: 'indigo',
  INDIVIDUAL_TEAM_ACTION: 'orange',
  ABSENT_FAILED_DEFENCE: 'blue-grey',
}[layer] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <!-- ── Header ──────────────────────────────────────────────────────────────────────── -->
    <div class="d-flex align-start justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="d-flex align-center gap-2 flex-wrap mb-1">
          <Link v-if="investigation.incident" :href="`/sheq/incidents/${investigation.incident.uuid}`"
                class="text-primary text-decoration-none font-weight-medium">
            {{ investigation.incident.reference }}
          </Link>
          <VChip size="small" variant="tonal">{{ investigation.method }}</VChip>
          <VChip v-if="investigation.revision > 1" size="small" variant="tonal" color="info">
            {{ t('sheq.investigations.revision_n', { n: investigation.revision }) }}
          </VChip>
        </div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sheq.investigations.heading') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0 mt-1">
          {{ investigation.incident?.description }}
        </p>
      </div>
    </div>

    <!-- Step indicator: where this sits in the workflow. -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="py-3">
        <div class="d-flex align-center flex-wrap gap-2">
          <template v-for="(step, i) in STEPS" :key="step">
            <div class="d-flex align-center gap-2">
              <VIcon
                :icon="i < currentStep ? 'mdi-check-circle' : i === currentStep ? 'mdi-circle-slice-5' : 'mdi-circle-outline'"
                :color="i <= currentStep ? 'primary' : undefined"
                :class="i > currentStep ? 'text-disabled' : ''"
                size="18"
              />
              <span :class="['text-body-2', i > currentStep ? 'text-disabled' : 'font-weight-medium']">
                {{ t('sheq.investigations.status_' + step.toLowerCase()) }}
              </span>
            </div>
            <VDivider v-if="i < STEPS.length - 1" class="flex-grow-1" style="min-inline-size: 16px" />
          </template>
        </div>
      </VCardText>
    </VCard>

    <VAlert v-if="investigation.reopen_reason" type="info" variant="tonal" class="mb-4">
      {{ t('sheq.investigations.reopened_because', { reason: investigation.reopen_reason }) }}
    </VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <!-- ── The ICAM ladder ─────────────────────────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-1 font-weight-semibold">{{ t('sheq.investigations.causes') }}</span>
            <span class="text-caption text-medium-emphasis">
              {{ t('sheq.investigations.minimum', {
                have: investigation.causes.length, need: investigation.minimum_causes }) }}
            </span>
          </VCardTitle>

          <VCardText>
            <!-- The finding that separates blame from prevention, said before submission not at it. -->
            <VAlert
              v-if="onlyBlamesPeople" type="warning" variant="tonal" density="comfortable"
              class="mb-4" role="status" aria-live="polite"
            >
              {{ t('sheq.investigations.only_blames_people') }}
            </VAlert>

            <div v-for="rung in ladder" :key="rung.layer" class="rung">
              <div class="d-flex align-center justify-space-between gap-2 mb-2">
                <div class="d-flex align-center gap-2">
                  <VChip :color="layerColour(rung.layer)" size="x-small" variant="flat">
                    {{ t('sheq.investigations.layer_' + rung.layer.toLowerCase()) }}
                  </VChip>
                  <span class="text-caption text-medium-emphasis">
                    {{ t('sheq.investigations.layer_hint_' + rung.layer.toLowerCase()) }}
                  </span>
                </div>
                <VBtn
                  v-if="isDraft && canInvestigate" size="x-small" variant="text"
                  prepend-icon="mdi-plus"
                  :aria-label="t('sheq.investigations.add_to_layer', {
                    layer: t('sheq.investigations.layer_' + rung.layer.toLowerCase()) })"
                  @click="openCauseForm(rung.layer)"
                >{{ t('sheq.investigations.add_cause') }}</VBtn>
              </div>

              <div v-if="!rung.causes.length" class="text-caption text-disabled ps-1 pb-3">
                {{ t('sheq.investigations.nothing_at_this_layer') }}
              </div>

              <div v-for="c in rung.causes" :key="c.uuid" class="cause-item pa-3 mb-2">
                <div class="d-flex align-start gap-2">
                  <VChip
                    :color="c.cause_type === 'ROOT' ? 'error' : undefined"
                    size="x-small" :variant="c.cause_type === 'ROOT' ? 'flat' : 'tonal'"
                  >{{ t('sheq.investigations.' + c.cause_type.toLowerCase()) }}</VChip>
                  <div>
                    <div class="text-body-2">{{ c.description }}</div>
                    <div v-if="c.evidence" class="text-caption text-medium-emphasis mt-1">
                      {{ c.evidence }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- ── Narrative ───────────────────────────────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.investigations.narrative') }}
          </VCardTitle>
          <VCardText>
            <VTextarea
              v-model="narrative.summary" :label="t('sheq.investigations.summary')"
              rows="3" variant="outlined" density="comfortable" :disabled="!isDraft || !canInvestigate"
              :error-messages="narrative.errors.summary"
              :hint="t('sheq.investigations.summary_hint')" persistent-hint
            />
            <VTextarea
              v-model="narrative.sequence_of_events" :label="t('sheq.investigations.sequence')"
              rows="5" variant="outlined" density="comfortable" class="mt-4"
              :disabled="!isDraft || !canInvestigate"
              :error-messages="narrative.errors.sequence_of_events"
            />
            <div v-if="isDraft && canInvestigate" class="d-flex justify-end mt-3">
              <VBtn
                variant="tonal" prepend-icon="mdi-content-save-outline"
                :loading="narrative.processing" :disabled="narrative.processing"
                @click="saveNarrative"
              >{{ t('sheq.registers.save') }}</VBtn>
            </div>
          </VCardText>
        </VCard>

        <VCard v-if="evidence.length" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.investigations.evidence') }}
          </VCardTitle>
          <VCardText>
            <div v-for="f in evidence" :key="f.uuid" class="d-flex align-center gap-2 py-1">
              <VIcon :icon="f.type === 'image' ? 'mdi-image-outline' : 'mdi-file-outline'" size="16" />
              <a :href="`/sheq/files/${f.uuid}/download`" class="text-body-2 text-primary text-decoration-none">
                {{ f.original_name }}
              </a>
              <VChip v-if="f.upload_status !== 'STORED'" size="x-small" color="warning" variant="tonal">
                {{ f.upload_status }}
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── What is required, and what happens next ──────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.investigations.required') }}
          </VCardTitle>
          <VCardText>
            <div class="d-flex align-center justify-space-between py-1">
              <span class="text-body-2 text-medium-emphasis">{{ t('sheq.investigations.potential') }}</span>
              <VChip :color="(investigation.potential_severity ?? 0) >= 4 ? 'error' : 'grey'"
                     size="x-small" variant="tonal">
                {{ investigation.potential_severity ?? '—' }}
              </VChip>
            </div>
            <div class="d-flex align-center justify-space-between py-1">
              <span class="text-body-2 text-medium-emphasis">{{ t('sheq.investigations.pack_requires') }}</span>
              <span class="text-body-2 font-weight-medium">{{ investigation.required_method }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">
              {{ t('sheq.investigations.depth_hint') }}
            </p>
          </VCardText>
        </VCard>

        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardText>
            <template v-if="investigation.shortfalls.length">
              <div class="text-body-2 font-weight-medium mb-2">
                {{ t('sheq.investigations.before_submitting') }}
              </div>
              <ul class="text-body-2 ps-4">
                <li v-for="s in investigation.shortfalls" :key="s" class="mb-1">{{ s }}</li>
              </ul>
            </template>

            <div v-else-if="isDraft" class="text-body-2 text-success d-flex align-center gap-1">
              <VIcon icon="mdi-check-circle-outline" size="16" />
              {{ t('sheq.investigations.ready_to_submit') }}
            </div>

            <div class="d-flex flex-column gap-2 mt-4">
              <VBtn
                v-if="isDraft && canInvestigate" color="primary" variant="flat" block
                :disabled="investigation.shortfalls.length > 0 || action.processing"
                @click="confirming = 'submit'"
              >{{ t('sheq.investigations.submit') }}</VBtn>

              <VBtn
                v-if="investigation.status === 'PENDING_APPROVAL' && canApprove"
                color="primary" variant="flat" block
                :disabled="action.processing" @click="confirming = 'approve'"
              >{{ t('sheq.investigations.approve') }}</VBtn>

              <template v-if="isApproved && canApprove">
                <VBtn
                  color="success" variant="flat" block
                  :disabled="blockers.length > 0 || action.processing"
                  @click="confirming = 'close'"
                >{{ t('sheq.investigations.close_incident') }}</VBtn>
                <VBtn variant="tonal" block @click="confirming = 'reopen'">
                  {{ t('sheq.investigations.reopen') }}
                </VBtn>
              </template>
            </div>

            <VAlert
              v-if="isApproved && blockers.length" type="warning" variant="tonal"
              density="compact" class="mt-3"
            >
              <div class="text-caption font-weight-medium mb-1">
                {{ t('sheq.investigations.cannot_close') }}
              </div>
              <ul class="text-caption ps-4 mb-0">
                <li v-for="b in blockers" :key="b">{{ b }}</li>
              </ul>
            </VAlert>
          </VCardText>
        </VCard>

        <!-- Revision history — the prior findings stay readable (SHEQ-INC-10). -->
        <VCard v-if="history.length > 1" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.investigations.history') }}
          </VCardTitle>
          <VCardText>
            <div v-for="h in history" :key="h.uuid" class="d-flex align-center justify-space-between py-2">
              <div>
                <span class="text-body-2 font-weight-medium">
                  {{ t('sheq.investigations.revision_n', { n: h.revision }) }}
                </span>
                <div class="text-caption text-medium-emphasis">{{ h.method }}</div>
              </div>
              <div class="d-flex align-center gap-2">
                <VChip size="x-small" variant="tonal">
                  {{ t('sheq.investigations.status_' + h.status.toLowerCase()) }}
                </VChip>
                <VBtn
                  v-if="h.uuid !== investigation.uuid" size="x-small" variant="text"
                  :href="`/sheq/investigations/${h.uuid}`"
                >{{ t('sheq.investigations.view') }}</VBtn>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Add a cause ─────────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="!!addingTo" max-width="680" @update:model-value="addingTo = null">
      <VCard v-if="addingTo">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.investigations.layer_' + addingTo.toLowerCase()) }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.investigations.layer_hint_' + addingTo.toLowerCase()) }}
          </p>

          <VSelect
            v-model="cause.cause_type" :label="t('sheq.investigations.cause_type')"
            :items="[
              { title: t('sheq.investigations.contributing'), value: 'CONTRIBUTING' },
              { title: t('sheq.investigations.root'), value: 'ROOT' },
            ]"
            variant="outlined" density="comfortable" class="mb-3"
            :error-messages="cause.errors.cause_type"
          />
          <VTextarea
            v-model="cause.description" :label="t('sheq.investigations.what_happened')"
            rows="3" variant="outlined" density="comfortable" autofocus
            :error-messages="cause.errors.description"
          />
          <VTextarea
            v-model="cause.evidence" :label="t('sheq.investigations.what_shows_it')"
            rows="2" variant="outlined" density="comfortable" class="mt-3"
            :error-messages="cause.errors.evidence"
            :hint="t('sheq.investigations.evidence_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="addingTo = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="cause.processing"
                :disabled="cause.processing" @click="saveCause">
            {{ t('sheq.registers.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Confirmations before anything irreversible ──────────────────────────────────── -->
    <VDialog :model-value="!!confirming" max-width="640" @update:model-value="confirming = null">
      <VCard v-if="confirming">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.investigations.confirm_' + confirming) }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-0">{{ t('sheq.investigations.confirm_body_' + confirming) }}</p>

          <VTextarea
            v-if="confirming === 'reopen'" v-model="reopen.reason"
            :label="t('sheq.investigations.reopen_reason')" rows="3" class="mt-4"
            variant="outlined" density="comfortable" autofocus
            :error-messages="reopen.errors.reason"
            :hint="t('sheq.investigations.reopen_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="confirming = null">{{ t('sheq.action.cancel') }}</VBtn>

          <VBtn
            v-if="confirming === 'reopen'" color="primary" variant="flat"
            :loading="reopen.processing" :disabled="reopen.processing" @click="submitReopen"
          >{{ t('sheq.investigations.reopen') }}</VBtn>

          <VBtn
            v-else color="primary" variant="flat"
            :loading="action.processing" :disabled="action.processing"
            @click="post(confirming === 'close'
              ? `/sheq/investigations/${investigation.incident?.uuid}/close`
              : `/sheq/investigations/${investigation.uuid}/${confirming}`)"
          >{{ t('sheq.investigations.' + confirming) }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Flat: no shadows, structure carried by rules and type rather than depth. */
.rung + .rung {
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), .1);
  padding-block-start: 14px;
  margin-block-start: 6px;
}

.cause-item {
  border: 1px solid rgba(var(--v-theme-on-surface), .12);
  border-radius: 6px;
}

/* Disabled must read as disabled, not as normal-but-quiet. */
.text-disabled { opacity: .45; }
</style>
