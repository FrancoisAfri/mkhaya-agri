<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute
const { t } = useI18n()

type Section = { key: string; title: string; icon: string; data: Record<string, any> }

const props = defineProps<{
  identity: {
    uuid: string; name: string; customer_number: string | null
    display_number: string | null; email: string | null; phone: string | null; is_active: boolean
  }
  sections: Section[]
}>()

const money = (n: any) =>
  Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 1100px;">
    <!-- Identity — always present, with or without any module -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-3">
          <h1 class="text-h5 font-weight-bold">{{ identity.name }}</h1>
          <VChip v-if="!identity.is_active" size="small" color="default" variant="tonal">
            {{ t('customer_profile.inactive') }}
          </VChip>
        </div>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ identity.display_number }}
          <span v-if="identity.customer_number && identity.customer_number !== identity.display_number"
                class="ms-2">· {{ identity.customer_number }}</span>
        </p>
      </div>
      <VBtn variant="text" :href="route('customers.index')">{{ t('customer_profile.back') }}</VBtn>
    </div>

    <VCard class="mb-4">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <div class="text-caption text-medium-emphasis">{{ t('customer_profile.email') }}</div>
            <div class="text-body-2">{{ identity.email ?? '—' }}</div>
          </VCol>
          <VCol cols="12" md="4">
            <div class="text-caption text-medium-emphasis">{{ t('customer_profile.phone') }}</div>
            <div class="text-body-2">{{ identity.phone ?? '—' }}</div>
          </VCol>
          <VCol cols="12" md="4">
            <div class="text-caption text-medium-emphasis">{{ t('customer_profile.customer_number') }}</div>
            <div class="text-body-2">{{ identity.customer_number ?? '—' }}</div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Module sections: only what this tenant actually has -->
    <VCard v-for="s in props.sections" :key="s.key" class="mb-4">
      <VCardTitle class="d-flex align-center gap-2 text-subtitle-1">
        <VIcon :icon="s.icon" size="20" />
        {{ t(s.title) }}
      </VCardTitle>
      <VDivider />
      <VCardText>
        <!-- Financial (Accounting) -->
        <template v-if="s.key === 'financial'">
          <VRow>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.debtor_code') }}</div>
              <div class="text-body-2">{{ s.data.debtor_code ?? '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.terms') }}</div>
              <div class="text-body-2">{{ s.data.credit_terms_days ?? '—' }} {{ t('customer_profile.days') }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.credit_limit') }}</div>
              <div class="text-body-2">{{ s.data.credit_limit ? money(s.data.credit_limit) : '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.vat_number') }}</div>
              <div class="text-body-2">{{ s.data.vat_number ?? '—' }}</div>
            </VCol>
          </VRow>

          <VAlert v-if="s.data.on_hold" type="warning" variant="tonal" density="compact" class="mt-4">
            {{ t('customer_profile.on_hold') }}
          </VAlert>

          <VDivider class="my-4" />

          <VRow>
            <VCol cols="4">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.open_invoices') }}</div>
              <div class="text-h6">{{ s.data.ar?.invoice_count ?? 0 }}</div>
            </VCol>
            <VCol cols="4">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.outstanding') }}</div>
              <div class="text-h6">{{ money(s.data.ar?.outstanding) }}</div>
            </VCol>
            <VCol cols="4">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.overdue') }}</div>
              <div class="text-h6" :class="{ 'text-error': (s.data.ar?.overdue ?? 0) > 0 }">
                {{ money(s.data.ar?.overdue) }}
              </div>
            </VCol>
          </VRow>
        </template>

        <!-- Sales (CRM) -->
        <template v-else-if="s.key === 'sales'">
          <VAlert v-if="!s.data.linked" type="info" variant="tonal" density="compact">
            {{ t('customer_profile.not_linked_crm') }}
          </VAlert>
          <VRow v-else>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.industry') }}</div>
              <div class="text-body-2">{{ s.data.industry ?? '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.website') }}</div>
              <div class="text-body-2">{{ s.data.website ?? '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.city') }}</div>
              <div class="text-body-2">{{ s.data.city ?? '—' }}</div>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-caption text-medium-emphasis">{{ t('customer_profile.country') }}</div>
              <div class="text-body-2">{{ s.data.country ?? '—' }}</div>
            </VCol>
          </VRow>
        </template>
      </VCardText>
    </VCard>

    <VAlert v-if="!props.sections.length" type="info" variant="tonal">
      {{ t('customer_profile.no_sections') }}
    </VAlert>
  </VContainer>
</template>
