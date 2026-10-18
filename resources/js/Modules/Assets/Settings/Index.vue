<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect    from '@/@core/components/app-form-elements/AppSelect.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  settings: Record<string, any>
}>()

const form = reactive({ ...props.settings })

const depreciationRunItems = computed(()=>[
  { title: t('assets_settings.financial_settings.depreciation_monthly'), value: 'monthly' },
  { title: t('assets_settings.financial_settings.depreciation_quarterly'), value: 'quarterly' },
  { title: t('assets_settings.financial_settings.depreciation_annual'), value: 'annual' },
])

function save() {
  router.patch(route('assets.settings.update'), form, { preserveScroll: true })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_settings.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_settings.description') }}</p>
      </div>
    </div>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.asset_numbering.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow>
              <VCol cols="12" md="4">
                <AppTextField
                  v-model="form.asset_number_prefix"
                  :label="t('assets_settings.asset_numbering.number_prefix')"
                  :placeholder="t('assets_settings.asset_numbering.number_prefix_placeholder')"
                />
              </VCol>
              <VCol cols="12" md="8">
                <AppTextField
                  v-model="form.asset_number_format"
                  :label="t('assets_settings.asset_numbering.number_format')"
                  :placeholder="t('assets_settings.asset_numbering.number_format_placeholder')"
                  :hint="t('assets_settings.asset_numbering.number_format_hint')"
                  persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.financial_settings.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow>
              <VCol cols="12" md="6">
                <AppTextField
                  v-model="form.financial_year_start"
                  :label="t('assets_settings.financial_settings.financial_year_start')"
                  :placeholder="t('assets_settings.financial_settings.financial_year_start_placeholder')"
                  :hint="t('assets_settings.financial_settings.financial_year_start_hint')"
                  persistent-hint
                />
              </VCol>
              <VCol cols="12" md="6">
                <AppSelect
                  v-model="form.depreciation_run"
                  :items="depreciationRunItems"
                  item-title="title"
                  item-value="value"
                  :label="t('assets_settings.financial_settings.depreciation_run')"
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.approval_thresholds.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow>
              <VCol cols="12" md="4">
                <AppTextField
                  v-model.number="form.approval_levels"
                  :label="t('assets_settings.approval_thresholds.approval_levels')"
                  type="number" min="1" max="5"
                />
              </VCol>
              <VCol cols="12" md="4">
                <AppTextField
                  v-model.number="form.approval_threshold_l2"
                  :label="t('assets_settings.approval_thresholds.level_2_threshold')"
                  type="number" min="0" step="100"
                />
              </VCol>
              <VCol cols="12" md="4">
                <AppTextField
                  v-model.number="form.approval_threshold_l3"
                  :label="t('assets_settings.approval_thresholds.level_3_threshold')"
                  type="number" min="0" step="100"
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard class="mb-6">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.module_features.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow>
              <VCol cols="12" md="4">
                <VSwitch
                  v-model="form.enable_dual_depreciation"
                  :label="t('assets_settings.module_features.dual_depreciation')"
                  :hint="t('assets_settings.module_features.dual_depreciation_hint')"
                  persistent-hint color="primary" inset
                />
              </VCol>
              <VCol cols="12" md="4">
                <VSwitch
                  v-model="form.enable_impairment"
                  :label="t('assets_settings.module_features.impairment')"
                  :hint="t('assets_settings.module_features.impairment_hint')"
                  persistent-hint color="primary" inset
                />
              </VCol>
              <VCol cols="12" md="4">
                <VSwitch
                  v-model="form.enable_lease_rou"
                  :label="t('assets_settings.module_features.lease_rou')"
                  :hint="t('assets_settings.module_features.lease_rou_hint')"
                  persistent-hint color="primary" inset
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.actions.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VBtn color="primary" block prepend-icon="tabler-device-floppy" @click="save">
              {{ t('assets_settings.actions.save_settings') }}
            </VBtn>
          </VCardText>
        </VCard>

        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.reference_lookups.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact" nav>
            <VListItem prepend-icon="tabler-category"
              :title="t('assets_settings.reference_lookups.categories')"
              :subtitle="t('assets_settings.reference_lookups.categories_desc')"
              :href="route('assets.settings.categories.index')" />
            <VListItem prepend-icon="tabler-tags"
              :title="t('assets_settings.reference_lookups.types')"
              :subtitle="t('assets_settings.reference_lookups.types_desc')"
              :href="route('assets.settings.types.index')" />
            <VListItem prepend-icon="tabler-map-pin"
              :title="t('assets_settings.reference_lookups.locations')"
              :subtitle="t('assets_settings.reference_lookups.locations_desc')"
              :href="route('assets.settings.locations.index')" />
            <VListItem prepend-icon="tabler-building-factory"
              :title="t('assets_settings.reference_lookups.makes')"
              :subtitle="t('assets_settings.reference_lookups.makes_desc')"
              :href="route('assets.settings.makes.index')" />
          </VList>
        </VCard>

        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_settings.automation.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact" nav>
            <VListItem prepend-icon="tabler-calendar-clock"
              :title="t('assets_settings.automation.scheduled_reports')"
              :subtitle="t('assets_settings.automation.scheduled_reports_desc')"
              :href="route('assets.settings.scheduled-reports.index')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
