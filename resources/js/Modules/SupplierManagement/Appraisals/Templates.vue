<script setup lang="ts">
/**
 * The scorecards: what exists, for which industry, and whether each one adds up.
 *
 * A template whose weights do not reach 100 silently caps every supplier scored against it below
 * full marks, and nothing anywhere said so. That number is the first column worth reading here.
 */
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Template {
  uuid: string; name: string; status: string | null
  industry: string | null; questions: number; weight: number
}

defineProps<{ templates: Template[]; industries: { id: number; industry_type_name: string }[] }>()

const dialog = ref(false)
const form = useForm<{ name: string; industry_id: number | null; status: string }>({
  name: '', industry_id: null, status: 'active',
})

function create() {
  // Lands in the builder on success: a template with no questions cannot appraise anything.
  form.post(route('suppliers.appraisals.templates.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}

/** 100 is usable; anything else is not, and the reason differs. */
function weightState(weight: number) {
  if (weight === 100) return { colour: 'success', hint: t('supplier_management.appraisals.weight_ok') }
  if (weight > 100) return { colour: 'error', hint: t('supplier_management.appraisals.weight_over_hint') }
  return { colour: 'warning', hint: t('supplier_management.appraisals.weight_under_hint', { short: Math.round((100 - weight) * 100) / 100 }) }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('supplier_management.appraisals.templates_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('supplier_management.appraisals.templates_subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" :href="route('suppliers.appraisals.index')">{{ t('supplier_management.appraisals.back_to_list') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">
          {{ t('supplier_management.appraisals.new_template') }}
        </VBtn>
      </div>
    </div>

    <VCard elevation="0" border rounded="lg">
      <VCardText v-if="!templates.length" class="text-center py-10 text-medium-emphasis">
        {{ t('supplier_management.appraisals.no_templates') }}
      </VCardText>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th>{{ t('supplier_management.appraisals.template') }}</th>
            <th>{{ t('supplier_management.appraisals.industry') }}</th>
            <th class="text-right">{{ t('supplier_management.appraisals.questions') }}</th>
            <th class="text-right">{{ t('supplier_management.appraisals.total_weight') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tpl in templates" :key="tpl.uuid">
            <td>
              <a :href="route('suppliers.appraisals.templates.show', tpl.uuid)" class="font-weight-medium">{{ tpl.name }}</a>
              <div v-if="tpl.status" class="text-caption text-medium-emphasis">{{ tpl.status }}</div>
            </td>
            <td class="text-caption text-medium-emphasis">{{ tpl.industry ?? t('supplier_management.appraisals.any_industry') }}</td>
            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ tpl.questions }}</td>
            <td class="text-right">
              <VChip size="small" variant="tonal" :color="weightState(tpl.weight).colour" :title="weightState(tpl.weight).hint">
                {{ tpl.weight }}%
              </VChip>
            </td>
            <td class="text-right">
              <VBtn size="x-small" variant="text" color="primary" :href="route('suppliers.appraisals.templates.show', tpl.uuid)">
                {{ t('supplier_management.appraisals.edit') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="460">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ t('supplier_management.appraisals.new_template') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="form.name" :label="t('supplier_management.appraisals.template_name')"
                      density="comfortable" variant="outlined" :error-messages="form.errors.name" class="mb-3" />
          <VSelect v-model="form.industry_id" :items="industries" item-title="industry_type_name" item-value="id"
                   :label="t('supplier_management.appraisals.industry')"
                   :hint="t('supplier_management.appraisals.industry_optional_hint')" persistent-hint
                   density="comfortable" variant="outlined" clearable :error-messages="form.errors.industry_id" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('supplier_management.appraisals.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="create">
            {{ t('supplier_management.appraisals.create_and_add_questions') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
