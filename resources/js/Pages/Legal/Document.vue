<script setup lang="ts">
/**
 * The published privacy policy and data deletion notice.
 *
 * One component for both: they are the same document shape and the same audience — somebody who
 * followed a link from a social network's app settings, or a reviewer at Facebook or LinkedIn
 * checking that the page exists and says what the app does.
 *
 * `layout: null` on purpose. The pages are reachable without signing in, and wrapping them in the
 * application shell would draw a sidebar and a user menu for somebody who has no account.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineOptions({ layout: null })

interface Section { heading: string; body?: string[]; list?: string[] }
interface Operator {
  name: string; short_name: string; logo: string | null
  registration_number: string | null; address: string | null; phone: string | null
  privacy_email: string; support_email: string
  information_officer: string | null; officer_email: string; site: string
}
const props = defineProps<{
  kind: 'privacy' | 'deletion'
  operator: Operator
  effectiveDate: string
  sections: Section[]
}>()

const title = computed(() => props.kind === 'privacy'
  ? t('legal.privacy_title')
  : t('legal.deletion_title'))
function print() { window.print() }
const other = computed(() => props.kind === 'privacy'
  ? { href: '/data-deletion', label: t('legal.deletion_title') }
  : { href: '/privacy', label: t('legal.privacy_title') })
</script>

<template>
  <VApp>
    <VMain class="legal-main">
      <VContainer class="py-10" style="max-width: 820px">
        <header class="mb-8">
          <img v-if="operator.logo" :src="operator.logo" :alt="operator.name" style="max-height:56px;max-width:220px" class="mb-4" />
          <h1 class="text-h4 font-weight-bold mb-1">{{ title }}</h1>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ operator.name }}<template v-if="operator.registration_number"> · {{ operator.registration_number }}</template>
          </p>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('legal.effective', { date: effectiveDate }) }}</p>
        </header>

        <section v-for="(s, i) in sections" :key="i" class="mb-7">
          <h2 class="text-h6 font-weight-semibold mb-2">{{ s.heading }}</h2>
          <p v-for="(para, j) in s.body ?? []" :key="'p' + j" class="text-body-2 mb-3" style="line-height:1.7">{{ para }}</p>
          <ul v-if="s.list?.length" class="legal-list">
            <li v-for="(item, j) in s.list" :key="'l' + j" class="text-body-2 mb-2" style="line-height:1.7">{{ item }}</li>
          </ul>
        </section>

        <!-- How to reach a human. A policy that names no contact fails its own purpose, and both
             networks' reviewers look for one. -->
        <section class="mb-7">
          <h2 class="text-h6 font-weight-semibold mb-2">{{ t('legal.contact') }}</h2>
          <p class="text-body-2 mb-1">{{ operator.name }}</p>
          <p v-if="operator.address" class="text-body-2 mb-1">{{ operator.address }}</p>
          <p class="text-body-2 mb-1">
            <a :href="'mailto:' + operator.privacy_email" class="text-primary">{{ operator.privacy_email }}</a>
          </p>
          <p v-if="operator.phone" class="text-body-2 mb-1">{{ operator.phone }}</p>
          <p v-if="operator.information_officer" class="text-body-2 mb-0">
            {{ t('legal.information_officer') }}: {{ operator.information_officer }}
            <a :href="'mailto:' + operator.officer_email" class="text-primary ms-1">{{ operator.officer_email }}</a>
          </p>
        </section>

        <VDivider class="mb-4" />
        <div class="d-flex align-center justify-space-between flex-wrap ga-2">
          <a :href="other.href" class="text-body-2 text-primary">{{ other.label }}</a>
          <VBtn size="small" variant="text" prepend-icon="mdi-printer" class="d-print-none" @click="print">{{ t('legal.print') }}</VBtn>
        </div>
      </VContainer>
    </VMain>
  </VApp>
</template>

<style scoped>
.legal-main { min-height: 100vh; background: rgb(var(--v-theme-surface)); }
.legal-list { padding-left: 1.25rem; }
.legal-list li::marker { color: rgb(var(--v-theme-primary)); }
@media print {
  .legal-main { background: #fff; }
}
</style>
