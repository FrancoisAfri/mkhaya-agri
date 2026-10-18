<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface PolicyRule {
  id: number
  name: string
  description: string
  severity: 'low' | 'medium' | 'high' | 'critical'
  action_on_breach: 'block' | 'warn' | 'escalate' | 'log_only'
  action_pattern: string
  active: boolean
}

defineProps<{ rules: PolicyRule[] }>()

const severityColor = (s: string) => ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' }[s] ?? 'grey')

const actionIcon = (a: string) => ({
  block:    'tabler-ban',
  warn:     'tabler-alert-triangle',
  escalate: 'tabler-circle-arrow-up',
  log_only: 'tabler-eye',
}[a] ?? 'tabler-shield-check')

const actionColor = (a: string) => ({
  block:    'error',
  warn:     'warning',
  escalate: 'info',
  log_only: 'grey',
}[a] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_settings.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_settings.description') }}</p>
      </div>
      <VBtn variant="outlined" prepend-icon="tabler-shield-exclamation" :href="route('petty-cash.settings.violations')">
        {{ t('petty_cash_settings.policy_violations') }}
      </VBtn>
    </div>

    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
        <VIcon class="mr-2">tabler-shield-check</VIcon>
        {{ t('petty_cash_settings.active_policy_rules') }}
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
            <span class="font-weight-semibold">{{ rule.name }}</span>
          </template>

          <template #append>
            <div class="d-flex align-center ga-2">
              <VChip size="x-small" :color="severityColor(rule.severity)" variant="tonal">
                {{ rule.severity }}
              </VChip>
              <VChip size="x-small" :color="actionColor(rule.action_on_breach)" variant="tonal">
                {{ rule.action_on_breach }}
              </VChip>
            </div>
          </template>
        </VListItem>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        <VIcon size="48" class="mb-2">tabler-shield-off</VIcon>
        <p>{{ t('petty_cash_settings.empty.message') }}</p>
      </VCardText>
    </VCard>
  </div>
</template>
