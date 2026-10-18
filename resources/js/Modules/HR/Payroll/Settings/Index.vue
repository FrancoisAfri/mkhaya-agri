<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface PolicyRule {
  id: number; name: string; description: string
  severity: string; action_on_breach: string; active: boolean
}

const props = defineProps<{
  settings: Record<string, any>
  rules: PolicyRule[]
}>()

const form = useForm({
  currency:             props.settings.currency              ?? 'ZAR',
  pay_frequency:        props.settings.pay_frequency         ?? 'monthly',
  pay_day:              props.settings.pay_day               ?? 25,
  overtime_rate:        props.settings.overtime_rate         ?? 1.5,
  paye_enabled:         props.settings.paye_enabled          ?? true,
  uif_enabled:          props.settings.uif_enabled           ?? true,
  sdl_enabled:          props.settings.sdl_enabled           ?? true,
  uif_employee_rate:    props.settings.uif_employee_rate     ?? 1,
  uif_employer_rate:    props.settings.uif_employer_rate     ?? 1,
  sdl_rate:             props.settings.sdl_rate              ?? 1,
  payroll_budget:       props.settings.payroll_budget        ?? null as number | null,
})

function submit() {
  form.put(route('payroll.settings.update'))
}

const severityColor = (s: string) => ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' }[s] ?? 'grey')
const actionIcon    = (a: string) => ({ block: 'tabler-ban', warn: 'tabler-alert-triangle', escalate: 'tabler-circle-arrow-up', log_only: 'tabler-eye' }[a] ?? 'tabler-shield')
const actionColor   = (a: string) => ({ block: 'error', warn: 'warning', escalate: 'info', log_only: 'grey' }[a] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_settings.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_settings.description') }}</p>
      </div>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon class="mr-2">tabler-settings</VIcon> {{ t('payroll_settings.general.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.currency"
                    :label="t('payroll_settings.general.currency')"
                    variant="outlined"
                    density="compact"
                    :error-messages="form.errors.currency"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.pay_frequency"
                    :label="t('payroll_settings.general.pay_frequency')"
                    :items="[
                      { title: t('payroll_settings.general.pay_frequency_monthly'), value: 'monthly' },
                      { title: t('payroll_settings.general.pay_frequency_fortnightly'), value: 'fortnightly' },
                      { title: t('payroll_settings.general.pay_frequency_weekly'), value: 'weekly' },
                    ]"
                    item-title="title"
                    item-value="value"
                    variant="outlined"
                    density="compact"
                    :error-messages="form.errors.pay_frequency"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.pay_day"
                    :label="t('payroll_settings.general.pay_day')"
                    type="number"
                    :min="1"
                    :max="31"
                    variant="outlined"
                    density="compact"
                    :error-messages="form.errors.pay_day"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.overtime_rate"
                    :label="t('payroll_settings.general.overtime_rate')"
                    type="number"
                    step="0.1"
                    variant="outlined"
                    density="compact"
                    :hint="t('payroll_settings.general.overtime_hint')"
                    persistent-hint
                    :error-messages="form.errors.overtime_rate"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.payroll_budget"
                    :label="t('payroll_settings.general.payroll_budget')"
                    type="number"
                    variant="outlined"
                    density="compact"
                    :error-messages="form.errors.payroll_budget"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon class="mr-2">tabler-building-bank</VIcon> {{ t('payroll_settings.statutory.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" md="4" class="d-flex align-center">
                  <VCheckbox v-model="form.paye_enabled" :label="t('payroll_settings.statutory.paye_enabled')" density="compact" hide-details />
                </VCol>
                <VCol cols="12" md="4" class="d-flex align-center">
                  <VCheckbox v-model="form.uif_enabled" :label="t('payroll_settings.statutory.uif_enabled')" density="compact" hide-details />
                </VCol>
                <VCol cols="12" md="4" class="d-flex align-center">
                  <VCheckbox v-model="form.sdl_enabled" :label="t('payroll_settings.statutory.sdl_enabled')" density="compact" hide-details />
                </VCol>
              </VRow>
              <VDivider class="my-3" />
              <VRow>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.uif_employee_rate"
                    :label="t('payroll_settings.statutory.uif_employee_rate')"
                    type="number"
                    step="0.01"
                    variant="outlined"
                    density="compact"
                    :disabled="!form.uif_enabled"
                    :error-messages="form.errors.uif_employee_rate"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.uif_employer_rate"
                    :label="t('payroll_settings.statutory.uif_employer_rate')"
                    type="number"
                    step="0.01"
                    variant="outlined"
                    density="compact"
                    :disabled="!form.uif_enabled"
                    :error-messages="form.errors.uif_employer_rate"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.sdl_rate"
                    :label="t('payroll_settings.statutory.sdl_rate')"
                    type="number"
                    step="0.01"
                    variant="outlined"
                    density="compact"
                    :disabled="!form.sdl_enabled"
                    :error-messages="form.errors.sdl_rate"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <div class="d-flex justify-end">
            <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy">
              {{ t('payroll_settings.save_settings') }}
            </VBtn>
          </div>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border>
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon class="mr-2">tabler-shield-check</VIcon> {{ t('payroll_settings.active_policy_rules') }}
              <VChip size="x-small" class="ml-2" color="primary">{{ rules.length }}</VChip>
            </VCardTitle>
            <VDivider />
            <VList v-if="rules.length" lines="two">
              <VListItem
                v-for="rule in rules"
                :key="rule.id"
                :subtitle="rule.description"
              >
                <template #prepend>
                  <VIcon :color="actionColor(rule.action_on_breach)" size="22" class="mr-2">
                    {{ actionIcon(rule.action_on_breach) }}
                  </VIcon>
                </template>
                <template #title>
                  <span class="font-weight-semibold text-body-2">{{ rule.name }}</span>
                </template>
                <template #append>
                  <div class="d-flex align-center ga-1">
                    <VChip size="x-small" :color="severityColor(rule.severity)" variant="tonal">{{ rule.severity }}</VChip>
                    <VChip size="x-small" :color="actionColor(rule.action_on_breach)" variant="tonal">{{ rule.action_on_breach }}</VChip>
                  </div>
                </template>
              </VListItem>
            </VList>
            <VCardText v-else class="text-center text-medium-emphasis py-8">
              <VIcon size="48" class="mb-2">tabler-shield-off</VIcon>
              <p>{{ t('payroll_settings.no_rules') }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
