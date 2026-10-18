<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  assessments: Array<Record<string, any>>
  expiring: Array<Record<string, any>>
  riskCategories: string[]
  requiredByRisk: Record<string, string[]>
  canManage?: boolean
  /** False where Supplier Management is not installed: there is nothing to link to. */
  canLink?: boolean
  noCompany?: boolean
}>()

const submitForm = useForm({})
const linkForm = useForm({})

const riskOptions = computed(() => props.riskCategories.map(r => ({
  title: t('sheq.suppliers.risks.' + r), value: r,
})))

/*
 * What this risk band will be asked to produce, said as soon as the band is chosen.
 *
 * The invitation goes out naming the documents, so the supplier sends them once instead of
 * discovering them one rejection at a time. It moves with the dropdown, which is why it is a
 * function of the form rather than a fixed hint.
 */
const requiredFor = (values: Record<string, unknown>) => {
  const docs = props.requiredByRisk[String(values.risk_category ?? '')] ?? []

  return docs.length
    ? t('sheq.suppliers.required_for', { risk: String(values.risk_category ?? '').toLowerCase() })
      + ' ' + docs.map(d => d.replace(/_/g, ' ').toLowerCase()).join(', ')
    : ''
}

const statusColour = (s: string) => ({
  INVITED: 'grey', SUBMITTED: 'info', UNDER_REVIEW: 'warning',
  APPROVED: 'success', CONDITIONAL: 'info', REJECTED: 'error', EXPIRED: 'error',
}[s] ?? 'grey')

const riskColour = (r: string) => ({ LOW: 'success', MEDIUM: 'warning', HIGH: 'error' }[r] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.suppliers.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.suppliers.subtitle') }}</p>
      </div>
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.suppliers.invite')" action="/sheq/suppliers"
        :button-label="t('sheq.suppliers.invite')" :note="t('sheq.suppliers.invite_note')"
        :fields="[
          { key: 'supplier_name', label: t('sheq.suppliers.supplier_name'), required: true },
          { key: 'registration_number', label: t('sheq.registers.col.reg_no') },
          { key: 'contact_email', label: t('sheq.registers.col.email') },
          { key: 'contact_phone', label: t('sheq.registers.col.phone') },
          { key: 'risk_category', label: t('sheq.suppliers.risk'), type: 'select' as const,
            items: riskOptions, default: 'MEDIUM', cols: 12, hintFor: requiredFor },
          { key: 'scope_of_work', label: t('sheq.suppliers.scope'), type: 'textarea' as const,
            cols: 12 },
        ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="expiring.length" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ t('sheq.suppliers.expiring_count', { count: expiring.length }) }}
      </VAlert>


      <VCard elevation="0" border rounded="lg">
        <div v-if="!assessments.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-handshake-outline" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.suppliers.none') }}</div>
        </div>
        <VTable v-else density="compact">
          <thead><tr>
            <th>Supplier</th><th>{{ t('sheq.suppliers.risk') }}</th><th>Status</th>
            <th>{{ t('sheq.suppliers.may_engage') }}</th>
            <th>{{ t('sheq.suppliers.evidence') }}</th><th>{{ t('sheq.suppliers.valid_until') }}</th>
            <th class="text-end">{{ t('sheq.suppliers.commercial') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="a in assessments" :key="a.uuid">
              <td class="text-body-2">{{ a.supplier_name }}</td>
              <td>
                <VChip size="x-small" :color="riskColour(a.risk_category)" variant="tonal">
                  {{ a.risk_category.toLowerCase() }}
                </VChip>
              </td>
              <td>
                <VChip size="x-small" :color="statusColour(a.status)" variant="tonal">
                  {{ a.status.replace('_', ' ').toLowerCase() }}
                </VChip>
              </td>
              <!--
                The verdict, said rather than implied. "APPROVED, score 80" reads as a yes even
                when the approval expired last month or the insurance behind it has lapsed, and the
                reasons are what a buyer needs in order to do something about it.
              -->
              <td>
                <VChip size="x-small" variant="tonal" :color="a.may_engage ? 'success' : 'error'">
                  {{ a.may_engage ? t('sheq.suppliers.may_engage_yes') : t('sheq.suppliers.may_engage_no') }}
                </VChip>
                <ul v-if="a.engage_reasons.length" class="text-caption text-medium-emphasis mt-1 ps-4 mb-0">
                  <li v-for="reason in a.engage_reasons" :key="reason">{{ reason }}</li>
                </ul>
              </td>
              <td class="text-caption">
                <span :class="a.complete ? 'text-success' : 'text-warning'">
                  {{ a.evidence_score }}%
                </span>
                <span v-if="a.missing.length" class="text-medium-emphasis">
                  · {{ a.missing.length }} {{ t('sheq.suppliers.missing').toLowerCase() }}
                </span>
                <span v-if="a.expired.length" class="text-error">
                  · {{ a.expired.length }} {{ t('sheq.suppliers.expired').toLowerCase() }}
                </span>
              </td>
              <td class="text-caption">{{ a.valid_until || '—' }}</td>
              <td style="width: 190px" class="text-end">
                <VBtn v-if="a.complete && a.status === 'INVITED'" size="x-small" variant="outlined"
                  :disabled="submitForm.processing"
                  @click="submitForm.post(`/sheq/suppliers/${a.uuid}/submit`, { preserveScroll: true })">
                  {{ t('sheq.suppliers.submit') }}
                </VBtn>
                <!--
                  CON-5: tie the safety file to the commercial record. Without the link the two
                  halves of what we know about this company cannot be read against each other, and
                  the portal login they already have cannot be reused for their safety documents.
                -->
                <VChip v-if="a.supplier_id" size="x-small" variant="tonal" color="success"
                       prepend-icon="mdi-link-variant" class="ms-1">
                  {{ t('sheq.suppliers.linked') }}
                </VChip>
                <VBtn v-else-if="canLink && canManage" size="x-small" variant="text" class="ms-1"
                      prepend-icon="mdi-link-variant-plus" :disabled="linkForm.processing"
                      @click="linkForm.post(`/sheq/suppliers/${a.uuid}/link`, { preserveScroll: true })">
                  {{ t('sheq.suppliers.link') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
