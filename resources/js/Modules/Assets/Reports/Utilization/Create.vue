<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  categories: { id: number; name: string }[]
  locations:  { id: number; name: string }[]
  assets:     { id: number; name: string; asset_tag: string | null }[]
}>()

const form = useForm({
  report_name:  '',
  period_start: '',
  period_end:   '',
  scope:        'all',
  scope_id:     null as number | null,
})

const scopeOptions = computed(() => [
  { title: t('assets_utilization_reports_create.scopes.all'), value: 'all' },
  { title: t('assets_utilization_reports_create.scopes.category'), value: 'category' },
  { title: t('assets_utilization_reports_create.scopes.location'), value: 'location' },
  { title: t('assets_utilization_reports_create.scopes.single'), value: 'single' },
])

watch(() => form.scope, () => { form.scope_id = null })

function scopeItems() {
  if (form.scope === 'category') return props.categories
  if (form.scope === 'location') return props.locations
  if (form.scope === 'single')   return props.assets.map(a => ({ id: a.id, name: a.name }))
  return []
}

function submit() {
  form.post(route('assets.utilization-reports.store'))
}
</script>

<template>
  <div class="pa-6" style="max-width:640px">
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_utilization_reports_create.page_title') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('assets_utilization_reports_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-6">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12">
              <VTextField v-model="form.report_name" :label="t('assets_utilization_reports_create.form.report_name')" :error-messages="form.errors.report_name" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.period_start" :label="t('assets_utilization_reports_create.form.period_start')" type="date" :error-messages="form.errors.period_start" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.period_end" :label="t('assets_utilization_reports_create.form.period_end')" type="date" :error-messages="form.errors.period_end" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="form.scope" :items="scopeOptions" item-title="title" item-value="value" :label="t('assets_utilization_reports_create.form.scope')" :error-messages="form.errors.scope" />
            </VCol>
            <VCol v-if="form.scope !== 'all'" cols="12">
              <VAutocomplete
                v-model="form.scope_id"
                :items="scopeItems()"
                item-title="name"
                item-value="id"
                :label="t('assets_utilization_reports_create.form.select')"
                :error-messages="form.errors.scope_id"
              />
            </VCol>
          </VRow>
          <div class="d-flex gap-3 mt-4">
            <VBtn type="submit" color="primary" :loading="form.processing">{{ t('assets_utilization_reports_create.form.generate') }}</VBtn>
            <VBtn variant="text" :href="route('assets.utilization-reports.index')">{{ t('assets_utilization_reports_create.form.cancel') }}</VBtn>
          </div>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
