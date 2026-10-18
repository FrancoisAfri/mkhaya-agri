<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Portfolio = {
  id: number; uuid: string; title: string; status: string
  course: { title: string } | null
  assessor: { first_name: string; last_name: string } | null
  sections_count: number
  submitted_at: string | null; assessed_at: string | null
  assessment_decision: string | null; assessor_feedback: string | null
  learner_declared: boolean
}

const props = defineProps<{ portfolios: Portfolio[] }>()

const createDialog = ref(false)
const form = useForm({ title: '', course_id: null as number | null })

function openCreate() { form.reset(); createDialog.value = true }
function create() {
  form.post(route('lms.poe.create'), { onSuccess: () => { createDialog.value = false } })
}

const statusColor: Record<string, string> = {
  draft: 'default', submitted: 'info', under_review: 'warning',
  returned: 'error', approved: 'success', rejected: 'error', certified: 'success',
}
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_portfolios.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_portfolios.page_description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('lms_portfolios.new_portfolio') }}
      </VBtn>
    </div>

    <VRow>
      <VCol v-for="p in portfolios" :key="p.id" cols="12" md="6" lg="4">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-start gap-3 mb-3">
              <VAvatar color="primary" variant="tonal" size="42" rounded>
                <VIcon icon="tabler-folders" size="22" />
              </VAvatar>
              <div class="flex-1-1 min-width-0">
                <p class="text-body-1 font-weight-semibold mb-0 text-truncate">{{ p.title }}</p>
                <p v-if="p.course" class="text-caption text-medium-emphasis mb-0">{{ p.course.title }}</p>
              </div>
              <VChip :color="statusColor[p.status]" size="x-small" variant="tonal">
                {{ t(`lms_portfolios.statuses.${p.status}`) }}
              </VChip>
            </div>

            <div class="d-flex gap-3 mb-3 text-caption">
              <div>
                <span class="text-medium-emphasis">{{ t('lms_portfolios.sections', { count: p.sections_count }) }}</span>
              </div>
              <div v-if="p.assessor">
                <span class="text-medium-emphasis">{{ t('lms_portfolios.assessor', { name: `${p.assessor.first_name} ${p.assessor.last_name}` }) }}</span>
              </div>
            </div>

            <!-- Feedback banner -->
            <VAlert v-if="p.status === 'returned' && p.assessor_feedback"
              type="warning" variant="tonal" density="compact" class="mb-3" style="font-size:0.75rem">
              {{ p.assessor_feedback }}
            </VAlert>

            <VAlert v-if="p.assessment_decision === 'competent'"
              type="success" variant="tonal" density="compact" class="mb-3">
              {{ t('lms_portfolios.assessed_competent') }}
            </VAlert>

            <!-- Declaration warning -->
            <VAlert v-if="!p.learner_declared && p.status === 'draft'"
              type="info" variant="tonal" density="compact" class="mb-3" style="font-size:0.75rem">
              {{ t('lms_portfolios.declaration_required') }}
            </VAlert>

            <VBtn color="primary" variant="tonal" size="small" block
              @click="router.visit(route('lms.poe.show', { portfolio: p.id }))">
              {{ t('lms_portfolios.open_portfolio') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!portfolios.length" cols="12">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="text-center pa-10">
            <VAvatar color="primary" variant="tonal" size="56" class="mb-3">
              <VIcon icon="tabler-folders" size="28" />
            </VAvatar>
            <p class="text-body-1 font-weight-semibold mb-1">{{ t('lms_portfolios.no_portfolios_title') }}</p>
            <p class="text-caption text-medium-emphasis mb-4">
              {{ t('lms_portfolios.no_portfolios_message') }}
            </p>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
              {{ t('lms_portfolios.create_first') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="createDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_portfolios.dialog.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VTextField
            v-model="form.title"
            :label="t('lms_portfolios.dialog.title_label')"
            :hint="t('lms_portfolios.dialog.title_hint')"
            persistent-hint
            class="mb-3"
            :error-messages="form.errors.title"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="createDialog = false">{{ t('lms_portfolios.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="create">
            {{ t('lms_portfolios.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
