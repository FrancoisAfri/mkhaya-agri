<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()
const page = usePage()

type Induction = {
  uuid: string; code: string; title: string; audience: string; sector: string
  basis: string | null; version: number; validity_months: number
  requires_nda: boolean; is_shared: boolean; site_name: string | null
  section_count: number; question_count: number
}

const props = defineProps<{
  inductions: Induction[]
  expiring: Array<Record<string, any>>
  completions: Array<Record<string, any>>
  sectors: string[]
  sites: Array<{ id: number; name: string }>
  canManage?: boolean
  noCompany?: boolean
}>()

const authoring = ref(false)
const uploading = ref(false)

const IMPORT_TYPES = [
  { value: 'CONTRACTOR_WORKERS', label: 'Contractor workers' },
  { value: 'EQUIPMENT', label: 'Safety equipment' },
  { value: 'PPE_ITEMS', label: 'PPE catalogue' },
]

const form = useForm({
  code: '', title: '', audience: 'CONTRACTOR', site_id: null as number | null,
  validity_months: 12, requires_nda: true, nda_text: '',
  content: [{ heading: '', body: '' }],
  questions: [] as Array<{ id: string; text: string; options: string[]; answer: string }>,
})

const upload = useForm({ import_type: 'CONTRACTOR_WORKERS', file: null as File | null, commit: false })

const importErrors = computed(() => (page.props.flash as any)?.importErrors ?? [])
const expired = computed(() => props.expiring.filter(e => e.expired).length)

const addSection = () => form.content.push({ heading: '', body: '' })
const addQuestion = () => form.questions.push({
  id: `q${form.questions.length + 1}`, text: '', options: ['', ''], answer: '',
})

const submit = () => form.post('/sheq/inductions', {
  preserveScroll: true, onSuccess: () => { form.reset(); authoring.value = false },
})

const submitUpload = (commit: boolean) => {
  upload.commit = commit
  upload.post('/sheq/inductions/upload', { preserveScroll: true, forceFormData: true })
}

const formatDate = (v: string | null) => {
  if (!v) return '—'
  try { return d(new Date(v), 'short') } catch { return v }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.inductions.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.inductions.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="mdi-upload" @click="uploading = !uploading">
          {{ t('sheq.inductions.bulk') }}
        </VBtn>
        <VBtn v-if="canManage" color="primary" variant="flat" prepend-icon="mdi-plus"
          @click="authoring = !authoring">
          {{ t('sheq.inductions.author') }}
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="expired" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ t('sheq.inductions.expired_count', { count: expired }) }}
      </VAlert>

      <!-- ── Bulk onboarding ─────────────────────────────────────────────────── -->
        <VDialog v-model="uploading" max-width="760" scrollable>
          <VCard>
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.inductions.bulk') }}
            </VCardTitle>
            <VDivider />
            <div class="pa-4">
              <!--
                Download first. The template carries the instructions and two worked rows, generated
                from the same definition the importer reads — so it cannot drift from what is accepted.
              -->
              <div class="text-body-2 mb-3">{{ t('sheq.inductions.bulk_how') }}</div>

              <VRow dense class="mb-2">
                <VCol cols="12" md="4">
                  <VSelect v-model="upload.import_type" :items="IMPORT_TYPES"
                    item-title="label" item-value="value" variant="outlined" density="compact"
                    :label="t('sheq.inductions.what')" />
                </VCol>
                <VCol cols="12" md="4" class="d-flex align-center">
                  <VBtn variant="outlined" size="small" prepend-icon="mdi-download"
                    :href="`/sheq/inductions/template/${upload.import_type}`">
                    {{ t('sheq.inductions.download_template') }}
                  </VBtn>
                </VCol>
                <VCol cols="12" md="4">
                  <VFileInput variant="outlined" density="compact" accept=".csv"
                    :label="t('sheq.inductions.upload_file')" prepend-icon=""
                    prepend-inner-icon="mdi-paperclip"
                    @update:model-value="f => upload.file = Array.isArray(f) ? f[0] : f" />
                </VCol>
              </VRow>

              <div class="d-flex flex-wrap ga-2">
                <VBtn variant="outlined" size="small" :disabled="!upload.file || upload.processing"
                  @click="submitUpload(false)">
                  {{ t('sheq.inductions.check_only') }}
                </VBtn>
                <VBtn color="primary" variant="flat" size="small"
                  :disabled="!upload.file || upload.processing" @click="submitUpload(true)">
                  {{ t('sheq.inductions.import_now') }}
                </VBtn>
                <span class="text-caption text-medium-emphasis align-self-center">
                  {{ t('sheq.inductions.check_hint') }}
                </span>
              </div>

              <!-- Row-level errors: the row number, the column, and the value that failed. -->
              <VTable v-if="importErrors.length" density="compact" class="mt-4 border rounded">
                <thead>
                  <tr>
                    <th style="width: 80px">{{ t('sheq.inductions.row') }}</th>
                    <th>{{ t('sheq.inductions.problem') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(e, i) in importErrors" :key="i">
                    <td class="text-caption font-weight-medium">{{ e.row }}</td>
                    <td class="text-caption text-error">
                      {{ Array.isArray(e.errors) ? e.errors.join(' · ') : e.error }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VDialog>

      <!-- ── Author an induction ─────────────────────────────────────────────── -->
        <VDialog v-model="authoring" max-width="960" scrollable>
          <VCard>
            <div class="pa-4">
              <VRow dense>
                <VCol cols="6" md="2">
                  <VTextField v-model="form.code" variant="outlined" density="compact"
                    label="Code" :error-messages="form.errors.code" />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField v-model="form.title" variant="outlined" density="compact"
                    label="Title" :error-messages="form.errors.title" />
                </VCol>
                <VCol cols="6" md="2">
                  <VSelect v-model="form.audience"
                    :items="['VISITOR', 'CONTRACTOR', 'EMPLOYEE', 'DRIVER']"
                    variant="outlined" density="compact" label="Audience" />
                </VCol>
                <VCol cols="6" md="2">
                  <VSelect v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                    variant="outlined" density="compact" label="Site" clearable />
                </VCol>
                <VCol cols="6" md="2">
                  <VTextField v-model.number="form.validity_months" type="number"
                    variant="outlined" density="compact" label="Valid (months)" />
                </VCol>
              </VRow>

              <div class="text-caption text-uppercase text-medium-emphasis mt-3 mb-1">
                {{ t('sheq.inductions.content') }}
              </div>
              <div v-for="(section, i) in form.content" :key="i" class="mb-2 pa-3 border rounded">
                <VTextField v-model="section.heading" variant="outlined" density="compact"
                  hide-details label="Heading" class="mb-2" />
                <VTextarea v-model="section.body" rows="2" auto-grow variant="outlined"
                  density="compact" hide-details label="Body" />
              </div>
              <VBtn variant="outlined" size="x-small" prepend-icon="mdi-plus" @click="addSection">
                {{ t('sheq.inductions.add_section') }}
              </VBtn>

              <div class="text-caption text-uppercase text-medium-emphasis mt-4 mb-1">
                {{ t('sheq.inductions.questions') }}
                <span class="text-lowercase">— {{ t('sheq.inductions.pass_hint') }}</span>
              </div>
              <div v-for="(q, i) in form.questions" :key="i" class="mb-2 pa-3 border rounded">
                <VTextField v-model="q.text" variant="outlined" density="compact" hide-details
                  label="Question" class="mb-2" />
                <VRow dense>
                  <VCol v-for="(_, oi) in q.options" :key="oi" cols="12" md="6">
                    <VTextField v-model="q.options[oi]" variant="outlined" density="compact"
                      hide-details :label="`Option ${oi + 1}`" />
                  </VCol>
                  <VCol cols="12">
                    <VTextField v-model="q.answer" variant="outlined" density="compact"
                      hide-details label="Correct answer (must match an option exactly)" />
                  </VCol>
                </VRow>
              </div>
              <VBtn variant="outlined" size="x-small" prepend-icon="mdi-plus" @click="addQuestion">
                {{ t('sheq.inductions.add_question') }}
              </VBtn>

              <VCheckbox v-model="form.requires_nda" density="compact" hide-details class="mt-3"
                :label="t('sheq.inductions.requires_nda')" />
              <VTextarea v-if="form.requires_nda" v-model="form.nda_text" rows="3" auto-grow
                variant="outlined" density="compact" class="mt-2"
                :label="t('sheq.inductions.nda_text')" />

              <div class="d-flex ga-2 mt-3">
                <VBtn color="primary" variant="flat" size="small" :loading="form.processing"
                  @click="submit">
                  {{ t('sheq.inductions.publish') }}
                </VBtn>
                <VBtn variant="text" size="small" @click="authoring = false">Cancel</VBtn>
              </div>
            </div>
          </VCard>
        </VDialog>

      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.inductions.title') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!inductions.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-card-account-details-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.inductions.none') }}</div>
            </div>
            <div v-for="i in inductions" :key="i.uuid" class="pa-4 border-b">
              <div class="d-flex align-center flex-wrap ga-2">
                <span class="text-body-2 font-weight-medium">{{ i.title }}</span>
                <VChip size="x-small" variant="tonal">{{ i.audience.toLowerCase() }}</VChip>
                <VChip size="x-small" variant="tonal" color="info">{{ i.sector.toLowerCase() }}</VChip>
                <!-- A library induction belongs to everyone and must not look editable. -->
                <VChip v-if="i.is_shared" size="x-small" variant="tonal">
                  {{ t('sheq.inductions.shared') }}
                </VChip>
                <VChip v-if="i.requires_nda" size="x-small" variant="tonal">NDA</VChip>
                <span class="text-caption text-medium-emphasis">v{{ i.version }}</span>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ i.section_count }} {{ t('sheq.inductions.sections') }} ·
                {{ i.question_count }} {{ t('sheq.inductions.questions').toLowerCase() }} ·
                {{ t('sheq.inductions.valid_months', { n: i.validity_months }) }}
              </div>
              <!-- The legal basis, so a practitioner can check the content against it. -->
              <div v-if="i.basis" class="text-caption text-medium-emphasis mt-1">
                <VIcon icon="mdi-scale-balance" size="12" class="me-1" />{{ i.basis }}
              </div>
            </div>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.inductions.completions') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!completions.length" class="pa-4 text-body-2 text-medium-emphasis">
              {{ t('sheq.inductions.no_completions') }}
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="c in completions" :key="c.uuid">
                  <td class="text-body-2">{{ c.person_name }}</td>
                  <td style="width: 90px">
                    <VChip size="x-small" :color="c.passed ? 'success' : 'error'" variant="tonal">
                      {{ c.score }}%
                    </VChip>
                  </td>
                  <td class="text-caption text-medium-emphasis" style="width: 110px">
                    {{ formatDate(c.expires_on) }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
