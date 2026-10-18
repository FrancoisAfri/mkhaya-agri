<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const k = (key: string, a?: Record<string, unknown>) => t('bureau.audit.' + key, a ?? {})

interface Entry {
  id: number; uuid: string
  action: string; description: string | null
  user_id: number | null; user_name: string
  old_values: Record<string, any> | null
  new_values: Record<string, any> | null
  meta: Record<string, any> | null
  ip_address: string | null
  created_at: string
}
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  company: { uuid: string; name: string }
  entries: Page<Entry>
  filters: Record<string, string>
  actions: { value: string; title: string }[]
}>()

const action = ref(props.filters.action ?? '')
const search = ref(props.filters.search ?? '')

let debounce: ReturnType<typeof setTimeout>
watch([action, search], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('payroll.bureau.audit.index', props.company.uuid), {
      action: action.value, search: search.value,
    }, { preserveState: true, replace: true })
  }, 300)
})

/** Money leaving, or SARS being told something, reads differently from a note. */
const tone = (a: string): string => {
  if (a.startsWith('bureau.disbursement') || a.startsWith('bureau.bankfile')) return 'error'
  if (a.startsWith('bureau.emp201') || a.startsWith('bureau.certificates')) return 'warning'
  if (a.includes('banking') || a.includes('tax_changed') || a.includes('eti_changed')) return 'purple'
  if (a === 'bureau.run.finalised') return 'success'
  return 'primary'
}

const label = (a: string) => props.actions.find(x => x.value === a)?.title ?? a

function when(value: string): string {
  return new Date(value).toLocaleString('en-ZA', {
    year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

/** The before/after of one entry, flattened for display. */
function changes(entry: Entry): { field: string; from: any; to: any }[] {
  const keys = Object.keys(entry.new_values ?? {})
  return keys.map(field => ({
    field: field.replace(/_/g, ' '),
    from: entry.old_values?.[field] ?? '—',
    to: entry.new_values?.[field] ?? '—',
  }))
}

function goPage(page: number) {
  router.get(route('payroll.bureau.audit.index', props.company.uuid), {
    action: action.value, search: search.value, page,
  }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the client" class="row-action" variant="text" @click="router.get(route('payroll.bureau.companies.show', company.uuid))" />
      <div class="flex-grow-1">
        <h4 class="text-h4 mb-0">{{ k('title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ k('subtitle', { company: company.name, total: entries.total }) }}</p>
      </div>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense align="center">
          <VCol cols="12" sm="5">
            <VSelect v-model="action" :items="actions" :label="k('filter_action')" density="compact" hide-details />
          </VCol>
          <VCol cols="12" sm="7">
            <VTextField
              v-model="search" :label="k('search')" density="compact" hide-details
              prepend-inner-icon="tabler-search" clearable
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VList lines="two">
        <template v-for="entry in entries.data" :key="entry.uuid">
          <VListItem>
            <template #prepend>
              <VChip :color="tone(entry.action)" size="small" variant="tonal" label class="me-3">
                {{ label(entry.action) }}
              </VChip>
            </template>
            <VListItemTitle class="text-body-2">{{ entry.description }}</VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ entry.user_name }} · {{ when(entry.created_at) }}
              <span v-if="entry.ip_address"> · {{ entry.ip_address }}</span>
            </VListItemSubtitle>

            <div v-if="entry.new_values" class="mt-2 ms-1">
              <div v-for="c in changes(entry)" :key="c.field" class="text-caption">
                <span class="text-medium-emphasis text-capitalize">{{ c.field }}:</span>
                <span class="text-decoration-line-through text-medium-emphasis ms-1">{{ c.from }}</span>
                <VIcon size="12" class="mx-1">tabler-arrow-right</VIcon>
                <span class="font-weight-medium">{{ c.to }}</span>
              </div>
            </div>

            <div v-else-if="entry.meta" class="mt-1 ms-1 text-caption text-medium-emphasis">
              <span v-for="(value, key) in entry.meta" :key="key" class="me-3">
                {{ String(key).replace(/_/g, ' ') }}: {{ Array.isArray(value) ? value.length : value }}
              </span>
            </div>
          </VListItem>
          <VDivider />
        </template>

        <VListItem v-if="!entries.data.length">
          <VListItemTitle class="text-center text-medium-emphasis py-8">{{ k('empty') }}</VListItemTitle>
        </VListItem>
      </VList>

      <VCardActions v-if="entries.last_page > 1" class="justify-center">
        <VPagination
          :model-value="entries.current_page" :length="entries.last_page" :total-visible="7"
          @update:model-value="goPage"
        />
      </VCardActions>
    </VCard>
  </div>
</template>
