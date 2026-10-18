<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const confirmDialog = ref()

type SuccessionCandidate = {
  id: number
  readiness_level: 'ready_now' | 'ready_in_1_year' | 'ready_in_2_years' | 'development_needed'
  notes: string | null
  candidate: { id: number; full_name: string; email: string }
  nominated_by: { id: number; name: string } | null
  created_at: string
}

type SuccessionJob = {
  id: number
  title: string
  department: string | null
  succession_candidates: SuccessionCandidate[]
}

type CandidateOption = { id: number; full_name: string; email: string }
type JobOption = { id: number; title: string; department: string | null }

const props = defineProps<{
  jobs: SuccessionJob[]
  allCandidates: CandidateOption[]
  allJobs: JobOption[]
  readinessLevels: string[]
}>()

// ─── Nominate form ────────────────────────────────────────────────────────────
const showNominateDialog = ref(false)

const nominateForm = useForm({
  candidate_id: null as number | null,
  job_id: null as number | null,
  readiness_level: '' as string,
  notes: '',
})

function submitNominate() {
  nominateForm.post(route('recruitment.succession.store'), {
    onSuccess: () => {
      showNominateDialog.value = false
      nominateForm.reset()
    },
  })
}

// ─── Remove ───────────────────────────────────────────────────────────────────
function removeCandidate(id: number) {
  if (!confirm(t('recruitement_succession.remove_confirm'))) return
  router.delete(route('recruitment.succession.destroy', id))
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
const readinessConfig: Record<string, { label: string; color: string; icon: string }> = {
  ready_now: { label: t('recruitement_succession.readiness_levels.ready_now'), color: 'success', icon: 'tabler-circle-check' },
  ready_in_1_year: { label: t('recruitement_succession.readiness_levels.ready_in_1_year'), color: 'info', icon: 'tabler-clock' },
  ready_in_2_years: { label: t('recruitement_succession.readiness_levels.ready_in_2_years'), color: 'warning', icon: 'tabler-clock-2' },
  development_needed: { label: t('recruitement_succession.readiness_levels.development_needed'), color: 'error', icon: 'tabler-trending-up' },
}

const readinessItems = computed(() =>
  props.readinessLevels.map(r => ({
    value: r,
    title: readinessConfig[r]?.label ?? r,
  }))
)

const candidateItems = computed(() =>
  props.allCandidates.map(c => ({
    value: c.id,
    title: `${c.full_name} (${c.email})`,
  }))
)

const jobItems = computed(() =>
  props.allJobs.map(j => ({
    value: j.id,
    title: j.department ? `${j.title} — ${j.department}` : j.title,
  }))
)

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
  <VContainer fluid>
    <!-- Page header -->
    <VRow align="center" class="mb-6">
      <VCol>
        <h5 class="text-h5 font-weight-bold">{{ t('recruitement_succession.title') }}</h5>
        <p class="text-medium-emphasis mb-0">{{ t('recruitement_succession.subtitle') }}</p>
      </VCol>
      <VCol cols="auto">
        <VBtn
          color="primary"
          prepend-icon="tabler-plus"
          @click="showNominateDialog = true"
        >
          {{ t('recruitement_succession.nominate_button') }}
        </VBtn>
      </VCol>
    </VRow>

    <!-- Empty state -->
    <VCard v-if="!jobs.length" elevation="0" border>
      <VCardText class="d-flex flex-column align-center justify-center py-12">
        <VIcon icon="tabler-users" size="64" color="medium-emphasis" class="mb-4" />
        <p class="text-h6 text-medium-emphasis mb-0">{{ t('recruitement_succession.empty_title') }}</p>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('recruitement_succession.empty_subtitle') }}</p>
      </VCardText>
    </VCard>

    <!-- Jobs list -->
    <VCard
      v-for="job in jobs"
      :key="job.id"
      elevation="0"
      border
      class="mb-4"
    >
      <VCardTitle class="d-flex align-center gap-2 flex-wrap pt-4 pb-2 px-4">
        <span>{{ job.title }}</span>
        <VChip
          v-if="job.department"
          :label="true"
          size="small"
          color="primary"
          variant="tonal"
        >
          {{ job.department }}
        </VChip>
      </VCardTitle>

      <VDivider />

      <VCardText class="pt-4">
        <VRow>
          <VCol
            v-for="sc in job.succession_candidates"
            :key="sc.id"
            cols="12"
            sm="6"
            lg="4"
          >
            <VCard elevation="0" border class="position-relative h-100">
              <!-- Remove button -->
              <VBtn
                icon
                color="error"
                size="x-small"
                variant="text"
                class="position-absolute"
                style="top: 6px; right: 6px;"
                @click="removeCandidate(sc.id)"
              >
                <VIcon icon="tabler-x" />
              </VBtn>

              <VCardText class="pr-8">
                <!-- Candidate name -->
                <p class="text-body-1 font-weight-bold mb-0">{{ sc.candidate.full_name }}</p>
                <p class="text-caption text-medium-emphasis mb-2">{{ sc.candidate.email }}</p>

                <!-- Readiness chip -->
                <VChip
                  :color="readinessConfig[sc.readiness_level]?.color"
                  :prepend-icon="readinessConfig[sc.readiness_level]?.icon"
                  size="small"
                  variant="tonal"
                  class="mb-2"
                >
                  {{ readinessConfig[sc.readiness_level]?.label ?? sc.readiness_level }}
                </VChip>

                <!-- Notes -->
                <p v-if="sc.notes" class="text-caption mt-2 mb-1">{{ sc.notes }}</p>

                <!-- Nominated by / date -->
                <p class="text-caption text-medium-emphasis mb-0 mt-2">
                  <span v-if="sc.nominated_by">{{ t('recruitement_succession.nominated_by', { name: sc.nominated_by.name }) }} &middot; </span>
                  {{ formatDate(sc.created_at) }}
                </p>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Nominate Candidate dialog -->
    <VDialog v-model="showNominateDialog" max-width="520">
      <VCard elevation="0" border>
        <VCardTitle class="pt-4 px-4">{{ t('recruitement_succession.dialog.title') }}</VCardTitle>

        <VDivider />

        <VCardText>
          <VForm @submit.prevent="submitNominate">
            <VAutocomplete
              v-model="nominateForm.candidate_id"
              :label="t('recruitement_succession.dialog.candidate_label')"
              :items="candidateItems"
              item-value="value"
              item-title="title"
              :error-messages="nominateForm.errors.candidate_id"
              class="mb-3"
            />

            <VAutocomplete
              v-model="nominateForm.job_id"
              :label="t('recruitement_succession.dialog.position_label')"
              :items="jobItems"
              item-value="value"
              item-title="title"
              :error-messages="nominateForm.errors.job_id"
              class="mb-3"
            />

            <VSelect
              v-model="nominateForm.readiness_level"
              :label="t('recruitement_succession.dialog.readiness_label')"
              :items="readinessItems"
              item-value="value"
              item-title="title"
              :error-messages="nominateForm.errors.readiness_level"
              class="mb-3"
            />

            <VTextarea
              v-model="nominateForm.notes"
              :label="t('recruitement_succession.dialog.notes_label')"
              :rows="3"
              :error-messages="nominateForm.errors.notes"
            />
          </VForm>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="text"
            @click="showNominateDialog = false; nominateForm.reset()"
          >
            {{ t('recruitement_succession.dialog.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="nominateForm.processing"
            @click="submitNominate"
          >
            {{ t('recruitement_succession.dialog.nominate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
