<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  onSite: Array<Record<string, any>>
  overstaying: Array<Record<string, any>>
  expected: Array<Record<string, any>>
  sites: Array<{ id: number; name: string }>
  noCompany?: boolean
  /** False when no workflow exists, so nobody is authorised to admit anyone. */
  approvalConfigured?: boolean
  approvalsHref?: string
}>()

const canRequest = computed(() => props.approvalConfigured !== false)

const siteOptions = computed(() => props.sites.map(s => ({ title: s.name, value: s.id })))

const induct = useForm({ signature: 'signed-on-device', nda_accepted: true })
const blank = useForm({})

const act = (uuid: string, action: string) => {
  const f = action === 'induct' ? induct : blank
  f.post(`/sheq/visitors/${uuid}/${action}`, { preserveScroll: true })
}

const statusColour = (s: string) => ({
  REQUESTED: 'warning', APPROVED: 'info', INDUCTED: 'primary', ON_SITE: 'success',
  SIGNED_OUT: 'grey', REJECTED: 'error',
}[s] ?? 'grey')

const nextAction = (s: string) =>
  ({ APPROVED: 'induct', INDUCTED: 'sign-in' }[s] ?? null)

const formatTime = (v: string | null) => { if (!v) return '—'; try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.visitors.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.visitors.subtitle') }}</p>
      </div>
      <!-- Omitted rather than disabled where no approval workflow exists: the alert below says
           why and where to go, and a dead button beside it only invites clicking. -->
      <AddEntryDialog
        v-if="canRequest"
        :title="t('sheq.visitors.request')" action="/sheq/visitors"
        :button-label="t('sheq.visitors.request')" :note="t('sheq.visitors.request_note')"
        :fields="[
          { key: 'visitor_name', label: t('sheq.visitors.visitor_name'), required: true },
          { key: 'visitor_company', label: t('sheq.visitors.visitor_company') },
          { key: 'visitor_contact', label: t('sheq.visitors.visitor_contact') },
          { key: 'vehicle_registration', label: t('sheq.visitors.vehicle') },
          { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select' as const,
            items: siteOptions },
          { key: 'expected_from', label: t('sheq.visitors.expected_from'),
            type: 'datetime' as const },
          { key: 'expected_to', label: t('sheq.visitors.expected_to'), type: 'datetime' as const,
            hint: t('sheq.visitors.expected_to_hint') },
          { key: 'purpose', label: t('sheq.visitors.purpose'), type: 'textarea' as const, cols: 12 },
        ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <!--
      Said before anything is typed.
      A visit is approval-driven: with no workflow there is nobody authorised to admit anyone, and
      the request is refused. That refusal used to arrive on submit and took the form with it.
    -->
    <VAlert v-if="!noCompany && !canRequest" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-semibold mb-1">{{ t('sheq.visitors.no_workflow_title') }}</div>
      <div>{{ t('sheq.visitors.no_workflow_detail') }}</div>
      <template #append>
        <VBtn :href="approvalsHref ?? '/sheq/approvals/workflows'" variant="tonal"
              size="small" color="warning">
          {{ t('sheq.visitors.configure_approvals') }}
        </VBtn>
      </template>
    </VAlert>

    <template v-if="!noCompany">
      <VAlert v-if="overstaying.length" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ overstaying.length }} {{ t('sheq.visitors.overstaying').toLowerCase() }}
      </VAlert>

      <VRow>
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.visitors.on_site') }}
              <VChip v-if="onSite.length" size="small" class="ms-2" variant="tonal">{{ onSite.length }}</VChip>
            </VCardTitle>
            <VDivider />
            <!-- The roll call. Stated on the screen so its purpose is unmistakable. -->
            <div class="px-4 pt-3 text-caption text-medium-emphasis">
              {{ t('sheq.visitors.roll_call') }}
            </div>
            <div v-if="!onSite.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">{{ t('sheq.visitors.none_on_site') }}</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="v in onSite" :key="v.uuid">
                  <td>
                    <div class="text-body-2 font-weight-medium">{{ v.visitor_name }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ v.visitor_company || '—' }} · {{ v.visitor_contact || 'no contact' }}
                    </div>
                  </td>
                  <td class="text-caption">{{ v.badge_code }}</td>
                  <td class="text-caption text-medium-emphasis">{{ formatTime(v.signed_in_at) }}</td>
                  <td style="width: 100px">
                    <VBtn size="x-small" variant="outlined" @click="act(v.uuid, 'sign-out')">
                      {{ t('sheq.visitors.sign_out') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.visitors.expected') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!expected.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">Nothing expected.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="v in expected" :key="v.uuid">
                  <td class="text-body-2">{{ v.visitor_name }}</td>
                  <td style="width: 110px">
                    <VChip size="x-small" :color="statusColour(v.status)" variant="tonal">
                      {{ v.status.replace('_', ' ').toLowerCase() }}
                    </VChip>
                  </td>
                  <td style="width: 120px">
                    <VBtn v-if="nextAction(v.status)" size="x-small" variant="outlined"
                      @click="act(v.uuid, nextAction(v.status)!)">
                      {{ v.status === 'APPROVED' ? t('sheq.visitors.induct') : t('sheq.visitors.sign_in') }}
                    </VBtn>
                    <span v-else class="text-caption text-medium-emphasis">awaiting approval</span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
