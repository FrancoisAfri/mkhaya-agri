<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface PolicyRule { id: number; name: string; description: string; severity: string; action_on_breach: string; action_pattern: string; active: boolean }
interface LoanType { id: number; name: string; min_amount: number; max_amount: number; interest_rate: number; interest_type: string; is_active: boolean }

defineProps<{ rules: PolicyRule[]; loanTypes: LoanType[] }>()

const newTypeDialog = ref(false)
const form = useForm({
  name: '', description: '', min_amount: 0, max_amount: 50000,
  min_term_months: 1, max_term_months: 60, interest_rate: 0,
  interest_type: 'none', requires_permanent_status: true,
  requires_probation_completion: true, executive_approval_threshold: null as number | null,
  is_active: true,
})

const severityColor = (s: string) => ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' }[s] ?? 'grey')
const actionIcon    = (a: string) => ({ block: 'tabler-ban', warn: 'tabler-alert-triangle', escalate: 'tabler-circle-arrow-up', log_only: 'tabler-eye' }[a] ?? 'tabler-shield')
const actionColor   = (a: string) => ({ block: 'error', warn: 'warning', escalate: 'info', log_only: 'grey' }[a] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_settings.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('staff_loans_settings.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="tabler-shield-exclamation" :href="route('staff-loans.settings.violations')" :disabled="form.processing">
          {{ t('staff_loans_settings.policy_violations') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :disabled="form.processing" @click="newTypeDialog = true">
          {{ t('staff_loans_settings.add_loan_type') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- Policy Rules -->
      <VCol cols="12" md="7">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2">tabler-shield-check</VIcon> {{ t('staff_loans_settings.active_policy_rules') }}
            <VChip size="x-small" class="ml-2" color="primary">{{ rules.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <VList v-if="rules.length" lines="two">
            <VListItem v-for="rule in rules" :key="rule.id" :subtitle="rule.description">
              <template #prepend>
                <VIcon :color="actionColor(rule.action_on_breach)" size="22" class="mr-2">{{ actionIcon(rule.action_on_breach) }}</VIcon>
              </template>
              <template #title><span class="font-weight-semibold text-body-2">{{ rule.name }}</span></template>
              <template #append>
                <div class="d-flex align-center ga-1">
                  <VChip size="x-small" :color="severityColor(rule.severity)" variant="tonal">{{ rule.severity }}</VChip>
                  <VChip size="x-small" :color="actionColor(rule.action_on_breach)" variant="tonal">{{ rule.action_on_breach }}</VChip>
                </div>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-6">
            <VIcon size="40" class="mb-2">tabler-shield-off</VIcon>
            <p>{{ t('staff_loans_settings.no_rules') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Loan Types -->
      <VCol cols="12" md="5">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_settings.loan_types') }}</VCardTitle>
          <VDivider />
          <VList v-if="loanTypes.length" density="compact">
            <VListItem v-for="loanType in loanTypes" :key="loanType.id">
              <VListItemTitle class="font-weight-medium text-body-2">{{ loanType.name }}</VListItemTitle>
              <VListItemSubtitle>R{{ loanType.min_amount.toLocaleString() }}–R{{ loanType.max_amount.toLocaleString() }} · {{ loanType.interest_rate }}% {{ loanType.interest_type }}</VListItemSubtitle>
              <template #append>
                <VChip size="x-small" :color="loanType.is_active ? 'success' : 'grey'" variant="tonal">
                  {{ loanType.is_active ? t('staff_loans_settings.active') : t('staff_loans_settings.inactive') }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-4">{{ t('staff_loans_settings.no_loan_types') }}</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- New Loan Type Dialog -->
    <VDialog v-model="newTypeDialog" max-width="560" persistent>
      <VCard :loading="form.processing" :disabled="form.processing">
        <VCardTitle class="pa-4">{{ t('staff_loans_settings.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('staff_loans_settings.dialog.name')" variant="outlined" density="compact" :error-messages="form.errors.name" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.min_amount" :label="t('staff_loans_settings.dialog.min_amount')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.max_amount" :label="t('staff_loans_settings.dialog.max_amount')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.min_term_months" :label="t('staff_loans_settings.dialog.min_term')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.max_term_months" :label="t('staff_loans_settings.dialog.max_term')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="form.interest_rate" :label="t('staff_loans_settings.dialog.interest_rate')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="form.interest_type" :items="['none','fixed','reducing_balance']" :label="t('staff_loans_settings.dialog.interest_type')" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.executive_approval_threshold" :label="t('staff_loans_settings.dialog.executive_threshold')" type="number" variant="outlined" density="compact" :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VCheckbox v-model="form.requires_permanent_status" :label="t('staff_loans_settings.dialog.requires_permanent')" density="compact" hide-details :disabled="form.processing" />
            </VCol>
            <VCol cols="6">
              <VCheckbox v-model="form.requires_probation_completion" :label="t('staff_loans_settings.dialog.requires_probation')" density="compact" hide-details :disabled="form.processing" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" :disabled="form.processing" @click="newTypeDialog = false">
            {{ t('staff_loans_settings.dialog.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="form.processing" @click="form.post(route('staff-loans.settings.loan-types.store'), { onSuccess: () => newTypeDialog = false })">
            {{ t('staff_loans_settings.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
