<script setup lang="ts">
import CompanySwitchNotice from '@/Components/Tenancy/CompanySwitchNotice.vue'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Shown when a SHEQ screen has no company context. Every record in the module belongs to a
 * company, so there is nothing to list and nothing to file.
 *
 * The switcher itself is the shared CompanySwitchNotice; this supplies only the SHEQ sentence.
 */
defineProps<{ show: boolean }>()

const { t } = useI18n()
const page = usePage()

const selfHosted = computed(() => (page.props as any)?.edition === 'self_hosted')

const body = computed(() => selfHosted.value
  ? t('sheq.no_company.body_self_hosted')
  : t('sheq.no_company.body'))
</script>

<template>
  <CompanySwitchNotice
    :show="show"
    :title="t('sheq.no_company.title')"
    :body="body"
  />
</template>
