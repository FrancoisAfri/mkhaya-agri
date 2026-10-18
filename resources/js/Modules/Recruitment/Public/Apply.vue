<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

// ─── No layout — public page ──────────────────────────────────────────────────
defineOptions({ layout: null })

// ─── Props ────────────────────────────────────────────────────────────────────
const props = defineProps<{
  vacancy: {
    title: string
    department: string | null
    job_description: string
    required_qualifications: string | null
    required_skills: string[] | null
    salary_band_min: number | null
    salary_band_max: number | null
    job_type: string
  }
  token: string
}>()

// ─── Form ─────────────────────────────────────────────────────────────────────
const form = useForm({
  first_name:      '',
  last_name:       '',
  email:           '',
  phone:           '',
  expected_salary: null as number | null,
  location:        '',
  cover_note:      '',
  cv:              null as File | null,
})

const submitted    = ref(false)
const isDragging   = ref(false)
const cvFileName   = ref<string | null>(null)
const cvFileInput  = ref<HTMLInputElement | null>(null)

function submit() {
  form.post(route('recruitment.public.apply.store', props.token), {
    forceFormData: true,
    onSuccess: () => { submitted.value = true },
  })
}

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files && input.files[0]) {
    form.cv = input.files[0]
    cvFileName.value = input.files[0].name
  }
}

function onDrop(event: DragEvent) {
  isDragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) {
    form.cv = file
    cvFileName.value = file.name
  }
}

function formatSalaryRange(min: number | null, max: number | null): string | null {
  const fmt = (n: number) => `R ${n.toLocaleString('en-ZA')}`
  if (min && max)  return `${fmt(min)} – ${fmt(max)}`
  if (min)         return `From ${fmt(min)}`
  if (max)         return `Up to ${fmt(max)}`
  return null
}

const salaryRange = computed(() =>
  formatSalaryRange(props.vacancy.salary_band_min, props.vacancy.salary_band_max),
)
</script>

<template>
  <VApp>
    <div style="background: #f8fafc; min-height: 100vh; padding: 40px 16px 80px;">
      <div style="max-width: 900px; margin: 0 auto;">

        <!-- ── Company Header ──────────────────────────────────────────────── -->
        <div class="text-center mb-8">
          <div class="d-flex align-center justify-center gap-3 mb-2">
            <img
              src="/images/logo_default.png"
              alt="Mkhaya Agri"
              style="height: 48px; object-fit: contain;"
              @error="($event.target as HTMLImageElement).style.display = 'none'"
            />
            <span style="font-size: 1.5rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;">
              MKHAYA AGRI
            </span>
          </div>
          <p class="text-medium-emphasis text-body-2 mb-0">
            Talent Acquisition Platform
          </p>
        </div>

        <!-- ── Success State ───────────────────────────────────────────────── -->
        <div v-if="submitted" class="text-center py-10">
          <VCard
            elevation="0"
            border
            class="pa-10 mx-auto"
            style="max-width: 500px; border-radius: 24px;"
          >
            <VIcon icon="tabler-circle-check" color="success" size="72" class="mb-4" />
            <h4 class="font-weight-bold mb-3" style="font-size: 1.5rem;">
              Application Submitted!
            </h4>
            <p class="text-medium-emphasis mb-0">
              Thank you for applying for <strong>{{ vacancy.title }}</strong>. We will review your
              application and be in touch soon.
            </p>
          </VCard>
        </div>

        <!-- ── Main Content ────────────────────────────────────────────────── -->
        <VRow v-else>
          <!-- Left: Job Details -->
          <VCol cols="12" md="5">
            <VCard
              elevation="0"
              border
              style="border-radius: 12px; position: sticky; top: 24px;"
            >
              <VCardText class="pa-6">
                <!-- Job meta chips -->
                <div class="d-flex flex-wrap gap-2 mb-4">
                  <VChip
                    v-if="vacancy.department"
                    size="small"
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-building"
                  >
                    {{ vacancy.department }}
                  </VChip>
                  <VChip
                    size="small"
                    color="default"
                    variant="tonal"
                    prepend-icon="tabler-briefcase"
                  >
                    {{ vacancy.job_type }}
                  </VChip>
                </div>

                <h4
                  class="font-weight-bold mb-3"
                  style="font-size: 1.3rem; color: #0f172a; line-height: 1.3;"
                >
                  {{ vacancy.title }}
                </h4>

                <!-- Salary -->
                <div v-if="salaryRange" class="d-flex align-center gap-2 mb-4">
                  <VIcon icon="tabler-currency-dollar" color="success" size="18" />
                  <span class="text-body-2 font-weight-medium text-success">{{ salaryRange }}</span>
                </div>

                <VDivider class="mb-4" />

                <!-- Job Description -->
                <p class="text-caption text-medium-emphasis font-weight-semibold mb-2 text-uppercase" style="letter-spacing: 0.08em;">
                  About This Role
                </p>
                <p
                  class="text-body-2"
                  style="white-space: pre-wrap; line-height: 1.7; color: #475569;"
                >
                  {{ vacancy.job_description }}
                </p>

                <!-- Qualifications -->
                <div v-if="vacancy.required_qualifications" class="mt-4">
                  <p class="text-caption text-medium-emphasis font-weight-semibold mb-2 text-uppercase" style="letter-spacing: 0.08em;">
                    Requirements
                  </p>
                  <p
                    class="text-body-2"
                    style="white-space: pre-wrap; line-height: 1.7; color: #475569;"
                  >
                    {{ vacancy.required_qualifications }}
                  </p>
                </div>

                <!-- Skills -->
                <div v-if="vacancy.required_skills && vacancy.required_skills.length" class="mt-4">
                  <p class="text-caption text-medium-emphasis font-weight-semibold mb-2 text-uppercase" style="letter-spacing: 0.08em;">
                    Skills
                  </p>
                  <div class="d-flex flex-wrap gap-2">
                    <VChip
                      v-for="skill in vacancy.required_skills"
                      :key="skill"
                      size="small"
                      variant="tonal"
                      color="primary"
                    >
                      {{ skill }}
                    </VChip>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Right: Application Form -->
          <VCol cols="12" md="7">
            <VCard elevation="0" border style="border-radius: 12px;">
              <VCardTitle class="pa-6 pb-2 text-h6 font-weight-bold">
                <VIcon icon="tabler-user-edit" color="primary" size="22" class="me-2" />
                Your Application
              </VCardTitle>
              <VCardText class="pa-6 pt-3">
                <VRow>
                  <!-- First & Last name -->
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model="form.first_name"
                      label="First Name *"
                      variant="outlined"
                      density="comfortable"
                      :error-messages="form.errors.first_name"
                      hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model="form.last_name"
                      label="Last Name *"
                      variant="outlined"
                      density="comfortable"
                      :error-messages="form.errors.last_name"
                      hide-details="auto"
                    />
                  </VCol>

                  <!-- Email & Phone -->
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model="form.email"
                      label="Email Address *"
                      type="email"
                      variant="outlined"
                      density="comfortable"
                      prepend-inner-icon="tabler-mail"
                      :error-messages="form.errors.email"
                      hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model="form.phone"
                      label="Phone Number"
                      type="tel"
                      variant="outlined"
                      density="comfortable"
                      prepend-inner-icon="tabler-phone"
                      :error-messages="form.errors.phone"
                      hide-details="auto"
                    />
                  </VCol>

                  <!-- Expected Salary & Location -->
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model.number="form.expected_salary"
                      label="Expected Salary (ZAR)"
                      type="number"
                      variant="outlined"
                      density="comfortable"
                      prefix="R"
                      :min="0"
                      placeholder="0"
                      :error-messages="form.errors.expected_salary"
                      hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VTextField
                      v-model="form.location"
                      label="Current Location"
                      variant="outlined"
                      density="comfortable"
                      prepend-inner-icon="tabler-map-pin"
                      placeholder="City, Province"
                      :error-messages="form.errors.location"
                      hide-details="auto"
                    />
                  </VCol>

                  <!-- Cover Note -->
                  <VCol cols="12">
                    <VTextarea
                      v-model="form.cover_note"
                      label="Cover Note"
                      variant="outlined"
                      density="comfortable"
                      rows="5"
                      placeholder="Tell us why you're a great fit for this role…"
                      :error-messages="form.errors.cover_note"
                      hide-details="auto"
                    />
                  </VCol>

                  <!-- CV Upload -->
                  <VCol cols="12">
                    <p class="text-body-2 font-weight-medium mb-2">Curriculum Vitae *</p>
                    <div
                      class="pa-5 rounded-lg text-center"
                      style="border: 2px dashed; transition: border-color 0.2s, background 0.2s; cursor: pointer;"
                      :style="{
                        borderColor: isDragging ? 'rgb(var(--v-theme-primary))' : 'rgba(0,0,0,0.2)',
                        background: isDragging ? 'rgba(var(--v-theme-primary), 0.05)' : 'rgba(0,0,0,0.02)',
                      }"
                      @click="cvFileInput?.click()"
                      @dragover.prevent="isDragging = true"
                      @dragleave.prevent="isDragging = false"
                      @drop.prevent="onDrop"
                    >
                      <VIcon
                        :icon="cvFileName ? 'tabler-file-check' : 'tabler-file-upload'"
                        :color="cvFileName ? 'success' : 'medium-emphasis'"
                        size="36"
                        class="mb-2"
                      />
                      <div v-if="cvFileName">
                        <p class="text-body-2 font-weight-medium text-success mb-0">
                          {{ cvFileName }}
                        </p>
                        <p class="text-caption text-medium-emphasis mb-0">Click to replace</p>
                      </div>
                      <div v-else>
                        <p class="text-body-2 font-weight-medium mb-0">
                          Drag &amp; drop your CV here, or click to browse
                        </p>
                        <p class="text-caption text-medium-emphasis mb-0">
                          Accepted formats: PDF, DOC, DOCX
                        </p>
                      </div>
                    </div>
                    <input
                      ref="cvFileInput"
                      type="file"
                      accept=".pdf,.doc,.docx"
                      style="display: none;"
                      @change="onFileChange"
                    />
                    <p v-if="form.errors.cv" class="text-caption text-error mt-1 mb-0">
                      {{ form.errors.cv }}
                    </p>
                  </VCol>

                  <!-- Submit Button -->
                  <VCol cols="12">
                    <VDivider class="mb-5" />
                    <VBtn
                      color="primary"
                      size="large"
                      block
                      :loading="form.processing"
                      :disabled="!form.first_name || !form.last_name || !form.email"
                      @click="submit"
                    >
                      <VIcon icon="tabler-send" size="18" class="me-2" />
                      Submit Application
                    </VBtn>
                    <p class="text-caption text-medium-emphasis text-center mt-3 mb-0">
                      By submitting, you consent to your information being processed for recruitment purposes.
                    </p>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </div>
    </div>
  </VApp>
</template>
