<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  company_name: string
}

interface Industry {
  id: number
  industry_type_name: string
}

interface Question {
  id: number
  question: string
  weight_percentage: number | null
}

interface Template {
  id: number
  name: string
  questions: Question[]
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  supplier: Supplier
  industries: Industry[]
  /** True when this supplier has no industry of its own and we are offering all of them. */
  industriesAreFallback?: boolean
  templates: Template[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const industryId = ref<number | null>(null)
const templateId = ref<number | null>(null)
const ratings = ref<Record<number, number>>({})
const comments = ref<Record<number, string>>({})
const submitting = ref<boolean>(false)

// ── Computed ──────────────────────────────────────────────────────────────────
const selectedTemplate = computed(() => props.templates.find(t => t.id === templateId.value))
const questions = computed(() => selectedTemplate.value?.questions ?? [])

// ── Methods ────────────────────────────────────────────────────────────────────
function submit(): void {
  submitting.value = true
  const answers = questions.value.map((q: Question) => ({
    question_library_id: q.id,
    rating: ratings.value[q.id] ?? 0,
    comment: comments.value[q.id] ?? '',
  }))
  router.post(
    route('suppliers.appraisals.store', props.supplier.uuid),
    {
      industry_id: industryId.value,
      template_id: templateId.value,
      answers,
    },
    { onFinish: () => (submitting.value = false) }
  )
}

function goToIndex(): void {
  router.get(route('suppliers.appraisals.index'))
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">
      {{ t('supplier_appraisal_create.page_title', { name: supplier.company_name }) }}
    </h1>
    <VCard border>
      <VCardText>
        <!-- ── Filters ───────────────────────────────────────────────────────── -->
        <VRow class="mb-2">
          <VCol cols="12" md="6">
            <VSelect
              v-model="industryId"
              :items="industries"
              item-title="industry_type_name"
              item-value="id"
              :label="t('supplier_appraisal_create.industry')"
              variant="outlined"
              clearable
              :hint="industriesAreFallback ? t('supplier_appraisal_create.industry_fallback_hint') : undefined"
              :persistent-hint="industriesAreFallback"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect
              v-model="templateId"
              :items="templates"
              item-title="name"
              item-value="id"
              :label="t('supplier_appraisal_create.template')"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <!-- ── Questions Table ──────────────────────────────────────────────── -->
        <VTable v-if="questions.length">
          <thead>
            <tr>
              <th>{{ t('supplier_appraisal_create.table.question') }}</th>
              <th>{{ t('supplier_appraisal_create.table.weight') }}</th>
              <th>{{ t('supplier_appraisal_create.table.rating') }}</th>
              <th>{{ t('supplier_appraisal_create.table.comment') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in questions" :key="q.id">
              <td>{{ q.question }}</td>
              <td>{{ q.weight_percentage ?? '—' }}</td>
              <td>
                <VRating
                  v-model="ratings[q.id]"
                  length="5"
                  size="small"
                  color="primary"
                />
              </td>
              <td style="min-width:200px">
                <VTextField
                  v-model="comments[q.id]"
                  density="compact"
                  variant="outlined"
                  hide-details
                  :placeholder="t('supplier_appraisal_create.placeholder')"
                />
              </td>
            </tr>
          </tbody>
        </VTable>
        <p v-else class="text-medium-emphasis py-4">
          {{ t('supplier_appraisal_create.select_template') }}
        </p>
      </VCardText>

      <!-- ── Actions ────────────────────────────────────────────────────────── -->
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="goToIndex">
          {{ t('supplier_appraisal_create.actions.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!questions.length"
          @click="submit"
        >
          {{ t('supplier_appraisal_create.actions.submit') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
