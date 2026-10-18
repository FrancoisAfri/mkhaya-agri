<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Question = { id: string; text: string; type: string; critical?: boolean }
type Section = { title: string; questions: Question[] }

const props = defineProps<{
  schedule: {
    uuid: string; code: string; title: string; site_name: string | null
    next_due: string | null; scoring_mode: string; sections: Section[]
  }
  canPerform?: boolean
}>()

const allQuestions = computed(() => props.schedule.sections.flatMap(s => s.questions))

const form = useForm({
  responses: Object.fromEntries(allQuestions.value.map(q => [q.id, null])) as Record<string, any>,
  signature: '',
  evidence: [] as File[],
})

const answered = computed(() =>
  Object.values(form.responses).filter(v => v !== null && v !== '').length)

const percent = computed(() =>
  allQuestions.value.length === 0 ? 0
    : Math.round((answered.value / allQuestions.value.length) * 100))

/** Unanswered critical questions are the ones that must not be skipped. */
const outstandingCritical = computed(() =>
  allQuestions.value.filter(q => q.critical && (form.responses[q.id] === null || form.responses[q.id] === '')))

const submit = () => form.post(`/sheq/inspections/${props.schedule.uuid}/complete`, {
  forceFormData: true,
})

const SCALE = [1, 2, 3, 4, 5]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="d-flex align-center gap-2 flex-wrap">
        <VChip size="small" variant="tonal">{{ schedule.code }}</VChip>
        <h1 class="text-h5 font-weight-bold mb-0">{{ schedule.title }}</h1>
      </div>
      <p class="text-body-2 text-medium-emphasis mb-0 mt-1">
        {{ schedule.site_name ?? t('sheq.inspections.no_site') }}
        <span v-if="schedule.next_due"> · {{ t('sheq.registers.col.next_due') }}: {{ schedule.next_due }}</span>
      </p>
    </div>

    <!-- Progress, so a long checklist shows how much is left rather than feeling endless. -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText>
        <div class="d-flex align-center justify-space-between mb-2">
          <span class="text-body-2 font-weight-medium">{{ t('sheq.forms.progress') }}</span>
          <span class="text-body-2 text-medium-emphasis">
            {{ t('sheq.forms.progress_count', { done: answered, total: allQuestions.length }) }}
          </span>
        </div>
        <VProgressLinear
          :model-value="percent" height="10" rounded
          :color="percent === 100 ? 'success' : 'primary'"
          :aria-label="t('sheq.forms.progress')" aria-live="polite"
        />
      </VCardText>
    </VCard>

    <VForm @submit.prevent="submit">
      <VCard
        v-for="section in schedule.sections" :key="section.title"
        elevation="0" border rounded="lg" class="mb-4"
      >
        <VCardTitle class="text-body-1 font-weight-semibold">{{ section.title }}</VCardTitle>
        <VCardText>
          <div v-for="q in section.questions" :key="q.id" class="question py-3">
            <div class="d-flex align-start gap-2 mb-2">
              <span class="text-body-2 font-weight-medium">{{ q.text }}</span>
              <VChip v-if="q.critical" size="x-small" color="error" variant="tonal">
                {{ t('sheq.inspections.critical') }}
              </VChip>
            </div>

            <!-- Big targets: this gets used on a phone, outdoors, in gloves (SHEQ-A11Y-4). -->
            <VBtnToggle
              v-if="q.type === 'PASS_FAIL' || q.type === 'YES_NO'"
              v-model="form.responses[q.id]" density="comfortable" variant="outlined" divided
            >
              <VBtn :value="q.type === 'PASS_FAIL' ? 'PASS' : 'YES'" color="success" class="answer-btn">
                {{ q.type === 'PASS_FAIL' ? t('sheq.inspections.pass') : t('sheq.inspections.yes') }}
              </VBtn>
              <VBtn :value="q.type === 'PASS_FAIL' ? 'FAIL' : 'NO'" color="error" class="answer-btn">
                {{ q.type === 'PASS_FAIL' ? t('sheq.inspections.fail') : t('sheq.inspections.no') }}
              </VBtn>
              <VBtn value="N/A" class="answer-btn">{{ t('sheq.inspections.na') }}</VBtn>
            </VBtnToggle>

            <VBtnToggle
              v-else-if="q.type === 'SCALE'"
              v-model="form.responses[q.id]" density="comfortable" variant="outlined" divided
            >
              <VBtn v-for="n in SCALE" :key="n" :value="n" class="answer-btn">{{ n }}</VBtn>
            </VBtnToggle>

            <VTextField
              v-else-if="q.type === 'NUMBER'" v-model.number="form.responses[q.id]"
              type="number" variant="outlined" density="comfortable" hide-details
              style="max-inline-size: 200px" :aria-label="q.text"
            />

            <VTextarea
              v-else v-model="form.responses[q.id]" rows="2"
              variant="outlined" density="comfortable" hide-details :aria-label="q.text"
            />
          </div>
        </VCardText>
      </VCard>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.inspections.evidence_signature') }}
        </VCardTitle>
        <VCardText>
          <VFileInput
            :label="t('sheq.inspections.photos')" multiple prepend-icon=""
            prepend-inner-icon="mdi-camera-outline" variant="outlined" density="comfortable"
            accept="image/*" :hint="t('sheq.registers.stored_in_db')" persistent-hint
            @update:model-value="(f: any) => form.evidence = Array.isArray(f) ? f : [f]"
          />
          <VTextField
            v-model="form.signature" :label="t('sheq.inspections.signed_by')" class="mt-4"
            variant="outlined" density="comfortable"
            :hint="t('sheq.inspections.signed_hint')" persistent-hint
          />
        </VCardText>
      </VCard>

      <VAlert
        v-if="outstandingCritical.length" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite"
      >
        {{ t('sheq.inspections.critical_unanswered', { count: outstandingCritical.length }) }}
      </VAlert>

      <div class="d-flex justify-end">
        <VBtn
          v-if="canPerform" type="submit" color="primary" variant="flat" size="large"
          prepend-icon="mdi-check" :loading="form.processing"
          :disabled="form.processing || answered === 0"
        >{{ t('sheq.inspections.submit') }}</VBtn>
      </div>
    </VForm>
  </div>
</template>

<style scoped>
.question + .question {
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), .1);
}

/* 56dp in field-capture screens, per SHEQ-A11Y-4 — this is answered in gloves. */
.answer-btn {
  min-inline-size: 76px;
  min-block-size: 56px;
}
</style>
