<script setup lang="ts">
import CompanySwitchNotice from '@/Components/Tenancy/CompanySwitchNotice.vue'
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Shown when the request has no company context. Every figure in this module is per company, so
 * there is genuinely nothing to show and nothing to write; saying why beats an empty table that
 * looks like a bug.
 *
 * The controls live in the shared CompanySwitchNotice — this only supplies the sentence that is
 * specific to procurement governance. Keeping the switcher in one place is deliberate: the two
 * module copies of this notice had already drifted once.
 */
defineProps<{ show: boolean }>()

const { t } = useI18n()
const page = usePage()

/**
 * The remedy differs by edition, so the wording does too. A self-hosted install has no tenant
 * switcher, so advice about entering a tenant sends somebody hunting for a menu their build does
 * not have.
 */
const selfHosted = computed(() => (page.props as any)?.edition === 'self_hosted')

const body = computed(() => selfHosted.value
  ? t('procurement_governance.no_company.body_self_hosted')
  : t('procurement_governance.no_company.body'))
</script>

<template>
  <CompanySwitchNotice
    :show="show"
    :title="t('procurement_governance.no_company.title')"
    :body="body"
  />
</template>
