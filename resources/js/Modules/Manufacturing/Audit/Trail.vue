<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Entry = { id: number; at: string; event: string; record_type: string; record_id: number | null; label: string | null; actor: string
  factory_id: number | null; changes: Array<{ field: string; before: string | null; after: string | null }> }

const props = defineProps<{
  entries: Entry[]
  pagination: { page: number; pages: number; total: number }
  types: Array<{ value: string; title: string }>
  filters: { type: string | null; user_id: number | null; from: string; to: string; q: string | null; event: string | null }
}>()

const f = ref({ ...props.filters })
const go = (page = 1) => router.get(route('manufacturing.audit.index'), { ...Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== null && v !== '')), page }, { preserveState: true, replace: true })
watch(() => [f.value.type, f.value.event, f.value.from, f.value.to], () => go())
const eventColor = (e: string) => ({ created: 'success', updated: 'info', deleted: 'error' } as Record<string, string>)[e] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.audit.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.audit.subtitle') }}</p>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VSelect v-model="f.type" :items="types" clearable hide-details style="min-width: 220px" :label="t('manufacturing.audit.record_type')" />
        <VSelect v-model="f.event" :items="['created', 'updated', 'deleted'].map(e => ({ value: e, title: t(`manufacturing.audit.events.${e}`) }))" clearable hide-details style="max-width: 180px" :label="t('manufacturing.audit.action')" />
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.audit.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.audit.to')" />
        <VTextField v-model="f.q" hide-details style="max-width: 220px" :label="t('manufacturing.audit.search')" @keyup.enter="go()" />
      </VCardText>
    </VCard>
    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.audit.when') }}</th><th>{{ t('manufacturing.audit.who') }}</th><th>{{ t('manufacturing.audit.record') }}</th>
          <th>{{ t('manufacturing.audit.action') }}</th><th>{{ t('manufacturing.audit.changes') }}</th></tr></thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td class="text-no-wrap">{{ e.at.slice(0, 19).replace('T', ' ') }}</td>
            <td>{{ e.actor }}</td>
            <td>{{ e.record_type }}<span v-if="e.label"> · {{ e.label }}</span><div class="text-caption text-medium-emphasis">#{{ e.record_id }}</div></td>
            <td><VChip size="x-small" label :color="eventColor(e.event)">{{ t(`manufacturing.audit.events.${e.event}`) }}</VChip></td>
            <td class="text-caption">
              <div v-for="c in e.changes.slice(0, 8)" :key="c.field"><span class="font-weight-medium">{{ c.field }}</span>:
                <span v-if="e.event !== 'created'" class="text-error">{{ c.before ?? '—' }}</span><span v-if="e.event !== 'created'"> → </span><span class="text-success">{{ c.after ?? '—' }}</span></div>
              <div v-if="e.changes.length > 8" class="text-medium-emphasis">{{ t('manufacturing.audit.more', { n: e.changes.length - 8 }) }}</div>
            </td>
          </tr>
          <tr v-if="!entries.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.audit.none') }}</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="pagination.pages > 1" class="d-flex justify-center"><VPagination :model-value="pagination.page" :length="pagination.pages" density="compact" @update:model-value="p => go(p)" /></VCardText>
    </VCard>
  </div>
</template>
