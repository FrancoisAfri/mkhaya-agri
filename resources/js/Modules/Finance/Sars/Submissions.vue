<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Sub { id: number; uuid: string; reference: string; tax_type: string; period: string; subject: string | null; status: string; version: number; amends_id: number | null; totals: any; errors: number; has_file: boolean; generated_at: string | null }
const props = defineProps<{
  submissions: { data: Sub[]; links: { url: string | null; label: string; active: boolean }[] }
  filters: { tax_type?: string }
  taxTypes: string[]
  statuses: string[]
}>()

const typeFilter = ref(props.filters.tax_type ?? '')
function applyFilter() { router.get(route('finance.accounting.sars.submissions'), { tax_type: typeFilter.value || undefined }, { preserveState: true, replace: true }) }
function goto(url: string | null) { if (url) router.get(url, {}, { preserveState: true, replace: true }) }

const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }
const typeLabel = (v: string) => t('sars.type_' + v)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.subs_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.subs_subtitle') }}</p>
      </div>
      <Link :href="route('finance.accounting.sars.config')"><VBtn variant="tonal" prepend-icon="tabler-shield-check">{{ t('sars.config_title') }}</VBtn></Link>
    </div>

    <VCard border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VSelect v-model="typeFilter" :items="['', ...taxTypes].map(x => ({ title: x ? typeLabel(x) : t('sars.all_types'), value: x }))" :label="t('sars.tax_type')" density="compact" variant="outlined" hide-details style="max-width: 240px" @update:model-value="applyFilter" />
      </VCardText>
    </VCard>

    <VCard border rounded="lg">
      <VTable>
        <thead>
          <tr>
            <th>{{ t('sars.reference') }}</th>
            <th>{{ t('sars.tax_type') }}</th>
            <th>{{ t('sars.period') }}</th>
            <th class="text-right">{{ t('sars.version') }}</th>
            <th>{{ t('sars.status') }}</th>
            <th class="text-center">{{ t('sars.errors') }}</th>
            <th>{{ t('sars.generated') }}</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!submissions.data.length"><td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('sars.no_submissions') }}</td></tr>
          <tr v-for="s in submissions.data" :key="s.uuid" style="cursor:pointer" @click="router.get(route('finance.accounting.sars.submissions.show', s.id))">
            <td class="font-weight-medium">{{ s.reference }}<div v-if="s.subject" class="text-caption text-medium-emphasis">{{ s.subject }}</div></td>
            <td><VChip size="x-small" variant="tonal">{{ typeLabel(s.tax_type) }}</VChip><VChip v-if="s.subject" size="x-small" color="indigo" variant="tonal" class="ms-1">{{ t('sars.bureau_tag') }}</VChip></td>
            <td>{{ s.period }}</td>
            <td class="text-right">v{{ s.version }}</td>
            <td><VChip size="small" :color="statusColor[s.status]" variant="tonal">{{ t('sars.st_' + s.status) }}</VChip></td>
            <td class="text-center"><VChip v-if="s.errors" size="x-small" color="error" variant="tonal">{{ s.errors }}</VChip><span v-else class="text-success">✓</span></td>
            <td class="text-caption">{{ s.generated_at ?? '—' }}</td>
            <td class="text-end">
              <VBtn v-if="s.has_file" size="small" variant="text" icon="tabler-download" :href="route('finance.accounting.sars.submissions.download', s.id)" @click.stop />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <div v-if="submissions.links.length > 3" class="d-flex justify-center flex-wrap gap-1 mt-4">
      <VBtn v-for="(l, i) in submissions.links" :key="i" size="small" variant="text" :color="l.active ? 'primary' : undefined" :disabled="!l.url" @click="goto(l.url)" v-html="l.label" />
    </div>
  </div>
</template>
