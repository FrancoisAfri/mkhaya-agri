<script setup lang="ts">
/**
 * What this system tells contractors, where it goes, and what actually went out (CON-19).
 *
 * Notifications are the one part of a system whose failure is invisible from the inside. A
 * document that will not save throws; a notice that was never sent looks exactly like one that
 * was sent and ignored, and the first anybody hears is a contractor saying "nobody told us".
 *
 * So the page answers three questions rather than one: what is sent at all, where it goes, and
 * what went out — with failures shown rather than buried in a log, and the contractors nobody
 * can reach listed first, because that is the quietest failure of the lot.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Dispatch = {
  uuid: string
  event_type: string
  key: string
  title: string
  subject_reference: string | null
  channel: string
  recipient_label: string | null
  recipient_address: string | null
  severity: string
  status: string
  sent_at: string | null
  attempts: number
  last_error: string | null
  created_at: string
}

const props = defineProps<{
  events: { type: string; key: string; severity: string }[]
  channels: string[]
  chosen: string[]
  recent: Dispatch[]
  counts: { sent: number; pending: number; failed: number }
  unreachable: { uuid: string; name: string; contact_email: string | null
    contact_phone: string | null }[]
  canManage?: boolean
  noCompany?: boolean
}>()

const search = ref('')
const statusFilter = ref<string | null>(null)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return props.recent.filter(row => {
    if (statusFilter.value && row.status !== statusFilter.value) return false
    if (!needle) return true

    return [row.subject_reference ?? '', row.title, row.recipient_address ?? '']
      .join(' ').toLowerCase().includes(needle)
  })
})

const statusColour = (status: string) =>
  ({ SENT: 'success', PENDING: 'info', FAILED: 'error', CANCELLED: 'default' }[status] ?? 'default')

const severityColour = (severity: string) =>
  ({ CRITICAL: 'error', HIGH: 'warning', NORMAL: 'default' }[severity] ?? 'default')

const when = (value: string | null) => {
  if (!value) return '—'
  try { return d(new Date(value), 'short') } catch { return value }
}

// ── Channels ──────────────────────────────────────────────────────────────────────────────────
const form = useForm({ channels: [...props.chosen] })

const dirty = computed(() =>
  JSON.stringify([...form.channels].sort()) !== JSON.stringify([...props.chosen].sort()))

function save() {
  form.post('/sheq/contractor-notices/channels', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.notices.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.notices.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- The quietest failure in the module: everything works, nothing is queued, and the
           contractor being told nothing is the last to know. -->
      <VAlert v-if="unreachable.length" type="warning" variant="tonal" class="mb-4"
              role="status" aria-live="polite">
        <div class="font-weight-semibold mb-1">
          {{ t('sheq.notices.unreachable_title', { n: unreachable.length }) }}
        </div>
        <div class="mb-2">{{ t('sheq.notices.unreachable_body') }}</div>
        <div class="d-flex flex-wrap ga-1">
          <VChip v-for="c in unreachable.slice(0, 12)" :key="c.uuid" size="x-small"
                 variant="tonal">
            {{ c.name }}
          </VChip>
          <VChip v-if="unreachable.length > 12" size="x-small" variant="text">
            {{ t('sheq.notices.and_more', { n: unreachable.length - 12 }) }}
          </VChip>
        </div>
      </VAlert>

      <VRow dense class="mb-1">
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.notices.sent_30d') }}
            </div>
            <div class="text-h4 font-weight-bold">{{ counts.sent }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.notices.waiting') }}
            </div>
            <div class="text-h4 font-weight-bold">{{ counts.pending }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.notices.failed') }}
            </div>
            <div class="text-h4 font-weight-bold" :class="counts.failed ? 'text-error' : ''">
              {{ counts.failed }}
            </div>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <!-- ── What is sent, and where ───────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.notices.channels') }}
            </VCardTitle>
            <VDivider />
            <VCardText>
              <p class="text-body-2 text-medium-emphasis mb-3">
                {{ t('sheq.notices.channels_hint') }}
              </p>

              <VCheckbox v-for="channel in channels" :key="channel"
                         v-model="form.channels" :value="channel" density="compact" hide-details
                         :disabled="!canManage"
                         :label="t('sheq.notices.channel.' + channel)" />

              <!-- Said plainly rather than implied by an empty list, because switching every
                   channel off is a decision with consequences a host should see stated. -->
              <VAlert v-if="!form.channels.length" type="warning" variant="tonal"
                      density="compact" class="mt-3">
                {{ t('sheq.notices.none_selected') }}
              </VAlert>

              <div v-if="canManage && dirty" class="d-flex justify-end mt-3">
                <VBtn color="primary" variant="flat" size="small" :loading="form.processing"
                      @click="save">
                  {{ t('sheq.registers.save') }}
                </VBtn>
              </div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.notices.what_we_send') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('sheq.notices.what_we_send_hint') }}
              </p>
            </VCardText>
            <VTable density="compact">
              <tbody>
                <tr v-for="event in events" :key="event.type">
                  <td class="py-2">
                    <div class="text-body-2">{{ t('sheq.notices.events.' + event.key) }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ t('sheq.notices.when.' + event.key) }}
                    </div>
                  </td>
                  <td style="width: 90px" class="text-end">
                    <VChip size="x-small" variant="tonal" :color="severityColour(event.severity)">
                      {{ t('sheq.notices.severity.' + event.severity) }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── What went out ─────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.notices.what_went_out') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
              <VTextField
                v-model="search" density="compact" variant="outlined" hide-details clearable
                prepend-inner-icon="mdi-magnify" style="max-width: 280px"
                :label="t('sheq.notices.search')"
              />
              <VSelect
                v-model="statusFilter" density="compact" variant="outlined" hide-details clearable
                style="max-width: 180px" :label="t('sheq.registers.col.status')"
                :items="[
                  { value: 'SENT', title: t('sheq.notices.status.SENT') },
                  { value: 'PENDING', title: t('sheq.notices.status.PENDING') },
                  { value: 'FAILED', title: t('sheq.notices.status.FAILED') },
                ]"
              />
            </VCardText>
            <VDivider />

            <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-email-outline" size="32" class="mb-2" />
              <div class="text-body-2">
                {{ recent.length ? t('sheq.notices.no_match') : t('sheq.notices.nothing_yet') }}
              </div>
            </div>

            <VTable v-else density="compact" class="notice-list">
              <tbody>
                <tr v-for="row in visible" :key="row.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal" :color="statusColour(row.status)">
                        {{ t('sheq.notices.status.' + row.status, row.status) }}
                      </VChip>
                      <span class="text-body-2">{{ row.title }}</span>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ row.subject_reference }}
                      · {{ t('sheq.notices.channel.' + row.channel, row.channel) }}
                      <template v-if="row.recipient_address"> · {{ row.recipient_address }}</template>
                      · {{ when(row.sent_at ?? row.created_at) }}
                    </div>
                    <!-- Why it failed, where it did. A failure with no reason on the screen is
                         a failure somebody has to read a log file to understand, which means
                         nobody does. -->
                    <div v-if="row.last_error" class="text-caption text-error mt-1">
                      {{ t('sheq.notices.failed_after', {
                        n: row.attempts, error: row.last_error,
                      }) }}
                    </div>
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
.notice-list { max-height: 720px; overflow-y: auto; }
</style>
