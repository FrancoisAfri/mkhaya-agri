<script setup lang="ts">
/**
 * Consumer trace codes: per produce lot, a public page behind a QR code. Choose what the page shows
 * (organisation defaults, per lot), revoke a code, and print a sheet of QR labels.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, statusColor } from '../Components/agri'

type Token = { id: string; url: string; visibility: Record<string, boolean>; views: number; last_viewed_at: string | null; issued: string | null }
type Lot = { id: string; code: string; kind: string; product: string; grade: string | null; status: string; produced_on: string | null; token: Token | null; revoked: number }
const props = defineProps<{
  filters: { farm: string | null }
  farms: Array<{ id: string; name: string }>
  lots: Lot[]
  settings: { defaults: Record<string, boolean>; certifications: string[] }
  adopted_schemes: string[]
  parts: string[]
  can: { manage: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.trace.${key}`, params ?? {})
const keep = { preserveScroll: true }

const defaults = ref({ ...props.settings.defaults })
const certText = ref(props.settings.certifications.join('\n'))
const saveSettings = () => router.post(route('agri.trace.settings'), {
  defaults: defaults.value, certifications: certText.value.split('\n').map(s => s.trim()).filter(Boolean),
}, keep)

const editing = ref<null | { lot: Lot; visibility: Record<string, boolean> }>(null)
const edit = (lot: Lot) => { editing.value = { lot, visibility: { ...(lot.token?.visibility ?? props.settings.defaults) } } }
const saveVisibility = () => {
  if (!editing.value) return
  const { lot, visibility } = editing.value
  const opts = { ...keep, onSuccess: () => { editing.value = null } }
  lot.token ? router.patch(route('agri.trace.update', lot.token.id), { visibility }, opts) : router.post(route('agri.trace.issue', lot.id), { visibility }, opts)
}
const issue = (lot: Lot) => router.post(route('agri.trace.issue', lot.id), {}, keep)
const revoke = (lot: Lot) => {
  if (!lot.token) return
  const reason = prompt(k('revoke_reason'))
  if (reason !== null) router.post(route('agri.trace.revoke', lot.token.id), { reason }, keep)
}
const copy = async (url: string) => { try { await navigator.clipboard.writeText(url) } catch { /* the link is shown beside it */ } }

const picked = ref<string[]>([])
const printable = computed(() => props.lots.filter(l => l.token && picked.value.includes(l.id)))
const printLabels = () => {
  const q = new URLSearchParams()
  printable.value.forEach(l => q.append('lots[]', l.id))
  window.open(`${route('agri.trace.labels')}?${q.toString()}`, '_blank')
}
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" />
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard :title="k('lots_title')">
          <VCardText class="pb-0 d-flex gap-2 align-center">
            <VBtn :disabled="!printable.length" color="primary" variant="tonal" prepend-icon="tabler-printer" @click="printLabels">{{ k('print', { n: printable.length }) }}</VBtn>
            <span class="text-caption text-medium-emphasis">{{ k('print_hint') }}</span>
          </VCardText>
          <VTable density="compact">
            <thead><tr><th /><th>{{ k('lot') }}</th><th>{{ k('product') }}</th><th>{{ k('code') }}</th><th class="text-end">{{ k('views') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="l in lots" :key="l.id">
                <td><VCheckbox v-if="l.token" v-model="picked" :value="l.id" hide-details density="compact" /></td>
                <td class="text-no-wrap">{{ l.code }}<div class="text-caption text-medium-emphasis">{{ human(l.kind) }} · {{ l.produced_on }}</div></td>
                <td>{{ l.product }}<span v-if="l.grade" class="text-caption"> · {{ l.grade }}</span>
                  <VChip v-if="l.status !== 'available'" size="x-small" class="ms-1" :color="statusColor(l.status)">{{ human(l.status) }}</VChip></td>
                <td>
                  <template v-if="l.token">
                    <a :href="l.token.url" target="_blank" rel="noopener" class="text-caption">{{ k('open_page') }}</a>
                    <VBtn size="x-small" variant="text" icon="tabler-copy" :title="k('copy')" @click="copy(l.token.url)" />
                    <div class="text-caption text-medium-emphasis">{{ k('issued', { on: l.token.issued }) }}</div>
                  </template>
                  <span v-else class="text-caption text-medium-emphasis">{{ l.revoked ? k('revoked_n', { n: l.revoked }) : k('none') }}</span>
                </td>
                <td class="text-end">{{ l.token?.views ?? '' }}</td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.manage">
                    <VBtn v-if="!l.token" size="x-small" color="primary" variant="tonal" @click="issue(l)">{{ k('issue') }}</VBtn>
                    <VBtn size="x-small" variant="text" @click="edit(l)">{{ k('what_shows') }}</VBtn>
                    <VBtn v-if="l.token" size="x-small" variant="text" color="error" @click="revoke(l)">{{ k('revoke') }}</VBtn>
                  </template>
                </td>
              </tr>
              <tr v-if="!lots.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('no_lots') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard :title="k('settings_title')" :subtitle="k('settings_subtitle')">
          <VCardText>
            <VSwitch v-for="p in parts" :key="p" v-model="defaults[p]" :label="k(`part_${p}`)" :disabled="!can.manage" color="primary" density="compact" hide-details />
            <VTextarea v-model="certText" :label="k('certifications')" :hint="k('certifications_hint')" persistent-hint rows="3" auto-grow class="mt-4" :disabled="!can.manage" />
            <div v-if="adopted_schemes.length" class="text-caption text-medium-emphasis mt-2">{{ k('adopted', { list: adopted_schemes.join(', ') }) }}</div>
          </VCardText>
          <VCardActions v-if="can.manage"><VSpacer /><VBtn color="primary" @click="saveSettings">{{ k('save') }}</VBtn></VCardActions>
        </VCard>
        <p class="text-caption text-medium-emphasis mt-3">{{ k('never_shown') }}</p>
      </VCol>
    </VRow>

    <VDialog v-if="editing" :model-value="true" max-width="460" @update:model-value="(v: boolean) => { if (!v) editing = null }">
      <VCard :title="k('what_shows_for', { lot: editing.lot.code })">
        <VCardText>
          <VSwitch v-for="p in parts" :key="p" v-model="editing.visibility[p]" :label="k(`part_${p}`)" color="primary" density="compact" hide-details />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ k('cancel') }}</VBtn><VBtn color="primary" @click="saveVisibility">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
