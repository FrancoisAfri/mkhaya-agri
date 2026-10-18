<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Violation = {
  id: number; policy_name: string; rule_name: string; severity: string
  outcome: string; status: string; employee: string; leave_type: string
  action: string; detected_at: string; resolved_at: string | null
}
type Conflict = {
  id: number; policy_a: string; policy_b: string; type: string
  description: string; status: string; detected_at: string
}
type AuditEntry = {
  id: number; rule_code: string; event_type: string; result: string
  detail: string; request_number: string | null; override_reason: string | null
  ai_explanation: string | null; created_at: string
}
type Summary = { total_violations: number; open: number; blocked: number; open_conflicts: number }

const props = defineProps<{
  violations:  Violation[]
  conflicts:   Conflict[]
  auditLog:    AuditEntry[]
  aiSummary:   string | null
  summary:     Summary
}>()

const tab            = ref<'violations' | 'conflicts' | 'audit'>('violations')
const resolveDialog  = ref(false)
const activeId       = ref<number | null>(null)
const resolveForm    = useForm({ resolution_note: '' })
const syncLoading    = ref(false)
const syncResult     = ref<string | null>(null)

const openResolve = (id: number) => { activeId.value = id; resolveDialog.value = true }

const submitResolve = () => {
  if (!activeId.value) return
  resolveForm.post(`/leave-management/policy-violations/${activeId.value}/resolve`, {
    onSuccess: () => { resolveDialog.value = false; resolveForm.reset() },
  })
}

const syncPolicies = async () => {
  syncLoading.value = true
  syncResult.value  = null
  try {
    const res = await fetch('/leave-management/policy-sync', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '', 'Accept': 'application/json' },
    })
    const data = await res.json()
    syncResult.value = data.message ?? 'Sync complete.'
    router.reload()
  } catch {
    syncResult.value = 'Sync failed. Please try again.'
  } finally {
    syncLoading.value = false
  }
}

const severityColor = (s: string) => ({ critical: 'error', high: 'error', medium: 'warning', low: 'info' })[s] ?? 'secondary'
const outcomeColor  = (o: string) => ({ block: 'error', escalate: 'warning', warn: 'warning', log_only: 'info' })[o] ?? 'secondary'
const outcomeIcon   = (o: string) => ({ block: 'tabler-cancel', escalate: 'tabler-arrow-up-circle', warn: 'tabler-alert', log_only: 'tabler-information' })[o] ?? 'tabler-circle'

const openViolations = computed(() => props.violations.filter(v => v.status === 'open'))

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
              <h4 class="text-h4">{{ t('leave.policy_violations_governance') }}</h4>
              <p class="mb-0 text-medium-emphasis text-body-2">
                Leave-specific policy violations, rule conflicts, and audit trail
              </p>
            </div>
            <div class="d-flex gap-2 flex-wrap align-center">
              <VChip color="error"   size="large" label>{{ summary.open }} Open</VChip>
              <VChip color="error"   size="large" label prepend-icon="tabler-cancel">{{ summary.blocked }} Blocked</VChip>
              <VChip color="warning" size="large" label prepend-icon="tabler-alert">{{ summary.open_conflicts }} Conflicts</VChip>
              <VBtn
                color="primary"
                variant="tonal"
                prepend-icon="tabler-sync"
                :loading="syncLoading"
                @click="syncPolicies"
              >
                {{ t('leave.sync_policies') }}
              </VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-shield-check" href="/leave-management/policy-overrides">
                Overrides
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Sync result feedback -->
      <VCol v-if="syncResult" cols="12">
        <VAlert type="info" variant="tonal" density="compact" icon="tabler-sync" closable>
          {{ syncResult }}
        </VAlert>
      </VCol>

      <!-- AI conflict summary -->
      <VCol v-if="aiSummary" cols="12">
        <VCard color="deep-purple" variant="tonal">
          <VCardText>
            <div class="d-flex gap-3 align-start">
              <VIcon icon="tabler-robot" size="28" color="deep-purple" />
              <div>
                <div class="text-subtitle-2 font-weight-medium mb-1">AI Conflict Analysis</div>
                <p class="text-body-2 mb-0">{{ aiSummary }}</p>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Tabs -->
      <VCol cols="12">
        <VCard>
          <VTabs v-model="tab" color="primary">
            <VTab value="violations">
              {{ t('leave.violations_tab') }}
              <VChip size="x-small" class="ms-2" :color="summary.open > 0 ? 'error' : 'secondary'" label>
                {{ violations.length }}
              </VChip>
            </VTab>
            <VTab value="conflicts">
              {{ t('leave.policy_conflicts_tab') }}
              <VChip size="x-small" class="ms-2" :color="conflicts.length > 0 ? 'warning' : 'secondary'" label>
                {{ conflicts.length }}
              </VChip>
            </VTab>
            <VTab value="audit">
              {{ t('leave.audit_log_tab') }}
              <VChip size="x-small" class="ms-2" color="secondary" label>{{ auditLog.length }}</VChip>
            </VTab>
          </VTabs>
          <VDivider />

          <VTabsWindow v-model="tab">
            <!-- ── Violations tab ── -->
            <VTabsWindowItem value="violations">
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>{{ t('leave.employee_label') }}</th>
                    <th>{{ t('leave.leave_type') }}</th>
                    <th>{{ t('leave.policy') }} / {{ t('leave.rule') }}</th>
                    <th>{{ t('leave.severity') }}</th>
                    <th>{{ t('leave.outcome') }}</th>
                    <th>{{ t('leave.status') }}</th>
                    <th>{{ t('leave.detected') }}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!violations.length">
                    <td colspan="9" class="text-center py-10 text-medium-emphasis">
                      <VIcon icon="tabler-shield-check" size="40" class="d-block mb-2" color="success" />
                      No policy violations detected.
                    </td>
                  </tr>
                  <tr v-for="v in violations" :key="v.id">
                    <td class="text-caption font-weight-medium">#{{ v.id }}</td>
                    <td>{{ v.employee }}</td>
                    <td class="text-caption">{{ v.leave_type }}</td>
                    <td>
                      <div class="text-body-2 font-weight-medium">{{ v.policy_name }}</div>
                      <div class="text-caption text-medium-emphasis">{{ v.rule_name }}</div>
                    </td>
                    <td>
                      <VChip size="x-small" :color="severityColor(v.severity)" label>{{ v.severity }}</VChip>
                    </td>
                    <td>
                      <VChip size="x-small" :color="outcomeColor(v.outcome)" :prepend-icon="outcomeIcon(v.outcome)" label>
                        {{ v.outcome }}
                      </VChip>
                    </td>
                    <td>
                      <VChip size="x-small" :color="v.status === 'resolved' ? 'success' : 'error'" label>
                        {{ v.status }}
                      </VChip>
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ v.detected_at }}</td>
                    <td>
                      <VBtn
                        v-if="v.status !== 'resolved'"
                        size="x-small"
                        color="success"
                        variant="tonal"
                        prepend-icon="tabler-check"
                        @click="openResolve(v.id)"
                      >
                        {{ t('leave.resolve') }}
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VTabsWindowItem>

            <!-- ── Conflicts tab ── -->
            <VTabsWindowItem value="conflicts">
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>Policy A</th>
                    <th>Policy B</th>
                    <th>Conflict Type</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Detected</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!conflicts.length">
                    <td colspan="6" class="text-center py-10 text-medium-emphasis">
                      No open policy conflicts.
                    </td>
                  </tr>
                  <tr v-for="c in conflicts" :key="c.id">
                    <td class="font-weight-medium text-caption">{{ c.policy_a }}</td>
                    <td class="font-weight-medium text-caption">{{ c.policy_b }}</td>
                    <td>
                      <VChip size="x-small" color="warning" label>{{ c.type.replace('_', ' ') }}</VChip>
                    </td>
                    <td class="text-caption" style="max-width:280px">{{ c.description }}</td>
                    <td>
                      <VChip size="x-small" :color="c.status === 'resolved' ? 'success' : 'warning'" label>
                        {{ c.status }}
                      </VChip>
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ c.detected_at }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VTabsWindowItem>

            <!-- ── Audit Log tab ── -->
            <VTabsWindowItem value="audit">
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>Rule Code</th>
                    <th>Event</th>
                    <th>Result</th>
                    <th>Detail</th>
                    <th>Request</th>
                    <th>Timestamp</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!auditLog.length">
                    <td colspan="6" class="text-center py-10 text-medium-emphasis">No audit entries.</td>
                  </tr>
                  <tr v-for="entry in auditLog" :key="entry.id">
                    <td>
                      <code class="text-caption">{{ entry.rule_code }}</code>
                    </td>
                    <td class="text-caption">{{ entry.event_type }}</td>
                    <td>
                      <VChip
                        size="x-small"
                        :color="entry.result === 'pass' ? 'success' : entry.result === 'fail' ? 'error' : 'warning'"
                        label
                      >
                        {{ entry.result }}
                      </VChip>
                    </td>
                    <td class="text-caption" style="max-width:240px">{{ entry.detail }}</td>
                    <td class="text-caption text-medium-emphasis">{{ entry.request_number ?? '—' }}</td>
                    <td class="text-caption text-medium-emphasis text-no-wrap">{{ entry.created_at }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VTabsWindowItem>
          </VTabsWindow>
        </VCard>
      </VCol>
    </VRow>

    <!-- Resolve dialog -->
    <VDialog v-model="resolveDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.resolve') }} #{{ activeId }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="success" variant="tonal" density="compact" class="mb-4">
            Mark this violation as resolved. Add a note to document the action taken.
          </VAlert>
          <VTextarea
            v-model="resolveForm.resolution_note"
            :label="t('leave.resolution_note')"
            variant="outlined"
            density="comfortable"
            rows="3"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="resolveDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">
            {{ t('leave.mark_resolved') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
