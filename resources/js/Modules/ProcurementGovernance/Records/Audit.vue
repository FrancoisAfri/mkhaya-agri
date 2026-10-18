<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { dateTime, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  record: any
  events: any[]
  context: any
}>()

const search = ref('')
const expanded = ref<number | null>(null)

const filtered = computed(() => {
  if (!search.value) return props.events

  const q = search.value.toLowerCase()

  return props.events.filter(e =>
    [e.summary, e.actor_label, e.actor_role, e.action, e.entity_reference, e.comment]
      .some(v => String(v ?? '').toLowerCase().includes(q)))
})

/** Only render the before/after panel when there is genuinely a change to show. */
function hasDiff(event: any): boolean {
  return !!(event.before || event.after)
}

function parse(value: any): Record<string, any> | null {
  if (!value) return null
  if (typeof value === 'object') return value

  try {
    return JSON.parse(value)
  } catch {
    return null
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <Link
        :href="`/procurement-governance/records/${record.uuid}`"
        class="text-caption text-primary text-decoration-none d-inline-flex align-center mb-1"
      >
        <VIcon icon="mdi-arrow-left" size="14" class="me-1" />{{ record.reference }}
      </Link>
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.audit.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.audit.subtitle') }}</p>
    </div>

    <!-- §7: the trail is immutable, and saying so is part of what makes it useful as evidence. -->
    <VAlert type="info" variant="tonal" density="compact" class="mb-4 text-body-2" icon="mdi-shield-lock-outline">
      {{ t('procurement_governance.audit.immutable_notice') }}
    </VAlert>

    <VCard elevation="0" border rounded="lg">
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
        <span class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.audit.events_n', { n: events.length }) }}
        </span>
        <VTextField
          v-model="search" density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="mdi-magnify" style="max-inline-size: 300px"
          :label="t('procurement_governance.common.search')"
        />
      </VCardTitle>

      <div v-if="!filtered.length" class="text-center py-10 px-4 text-medium-emphasis">
        <VIcon icon="mdi-history" size="32" class="mb-2" />
        <div class="text-body-2">{{ t('procurement_governance.audit.empty') }}</div>
      </div>

      <div v-else class="procgov-scroll">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th scope="col" style="width: 80px" class="text-end">{{ t('procurement_governance.audit.sequence') }}</th>
              <th scope="col" style="width: 170px">{{ t('procurement_governance.audit.when') }}</th>
              <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.audit.who') }}</th>
              <th scope="col">{{ t('procurement_governance.audit.what') }}</th>
              <th scope="col" class="d-none d-lg-table-cell">{{ t('procurement_governance.audit.decision') }}</th>
              <th scope="col" class="d-none d-lg-table-cell" style="width: 120px">{{ t('procurement_governance.audit.from') }}</th>
              <th scope="col" style="width: 44px" />
            </tr>
          </thead>
          <tbody>
            <template v-for="event in filtered" :key="event.sequence">
              <tr>
                <td class="text-end text-caption text-medium-emphasis">{{ event.sequence }}</td>
                <td class="text-body-2 text-no-wrap">{{ dateTime(event.occurred_at) }}</td>
                <td class="d-none d-md-table-cell">
                  <div class="text-body-2">{{ event.actor_label ?? '—' }}</div>
                  <div v-if="event.actor_role" class="text-caption text-medium-emphasis">{{ event.actor_role }}</div>
                </td>
                <td>
                  <div class="text-body-2">{{ event.summary ?? humanise(event.action) }}</div>
                  <div v-if="event.comment" class="text-caption text-medium-emphasis">{{ event.comment }}</div>
                </td>
                <td class="d-none d-lg-table-cell">
                  <StatusChip v-if="event.decision" :status="event.decision" size="x-small" />
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td class="d-none d-lg-table-cell text-caption text-medium-emphasis">{{ event.ip ?? '—' }}</td>
                <td>
                  <VBtn
                    v-if="hasDiff(event)" icon size="x-small" variant="text"
                    :aria-label="t('procurement_governance.audit.show_changes')"
                    @click="expanded = expanded === event.sequence ? null : event.sequence"
                  >
                    <VIcon :icon="expanded === event.sequence ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="18" />
                  </VBtn>
                </td>
              </tr>
              <tr v-if="expanded === event.sequence" class="procgov-diff-row">
                <td :colspan="7">
                  <VRow dense class="py-2">
                    <VCol cols="12" md="6">
                      <div class="text-caption text-medium-emphasis text-uppercase mb-1">
                        {{ t('procurement_governance.audit.before') }}
                      </div>
                      <pre class="text-caption procgov-json">{{ parse(event.before) ?? '—' }}</pre>
                    </VCol>
                    <VCol cols="12" md="6">
                      <div class="text-caption text-medium-emphasis text-uppercase mb-1">
                        {{ t('procurement_governance.audit.after') }}
                      </div>
                      <pre class="text-caption procgov-json">{{ parse(event.after) ?? '—' }}</pre>
                    </VCol>
                  </VRow>
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.procgov-diff-row { background: rgba(var(--v-theme-on-surface), 0.03); }
.procgov-json { white-space: pre-wrap; word-break: break-word; margin: 0; font-family: inherit; }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
}
</style>
