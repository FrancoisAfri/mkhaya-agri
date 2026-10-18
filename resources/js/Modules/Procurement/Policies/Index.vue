<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Rule = {
  id: number
  name: string
  type: string
  min_amount: number | null
  max_amount: number | null
  role: string | null
  params: Record<string, any> | null
  active: boolean
  sort_order: number | null
}

type Exception = {
  id: number
  rule_type: string
  reason: string | null
  status: string
  source_id: number | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  rules: Rule[]
  exceptions: Exception[]
  types: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)
const tab = ref('rules')

// ── Rule dialog ──────────────────────────────────────────────────────────────
const ruleDialog = ref(false)
const editing = ref<Rule | null>(null)

interface RuleForm {
  name: string
  type: string
  min_amount: number | null
  max_amount: number | null
  role: string
  params: string
  active: boolean
  sort_order: number | null
}

const ruleForm = ref<RuleForm>({
  name: '',
  type: '',
  min_amount: null,
  max_amount: null,
  role: '',
  params: '',
  active: true,
  sort_order: 0,
})

// ── Helpers ────────────────────────────────────────────────────────────────────
function getTypeLabel(type: string): string {
  const map: Record<string, string> = {
    approval: t('procurement_policies.types.approval'),
    compliance: t('procurement_policies.types.compliance'),
    budget: t('procurement_policies.types.budget'),
    sourcing: t('procurement_policies.types.sourcing'),
  }
  return map[type] || type
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    active: t('procurement_policies.statuses.active'),
    inactive: t('procurement_policies.statuses.inactive'),
    pending: t('procurement_policies.statuses.pending'),
    approved: t('procurement_policies.statuses.approved'),
    rejected: t('procurement_policies.statuses.rejected'),
  }
  return map[status] || status
}

function getStatusColor(status: string): string {
  const map: Record<string, string> = {
    active: 'success',
    inactive: 'grey',
    pending: 'warning',
    approved: 'success',
    rejected: 'error',
  }
  return map[status] || 'default'
}

function formatCurrency(value: number | null): string {
  if (value == null) return '—'
  return new Intl.NumberFormat('en-ZA', {
    style: 'currency',
    currency: 'ZAR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value)
}

// ── Rule methods ──────────────────────────────────────────────────────────────
function newRule(): void {
  editing.value = null
  ruleForm.value = {
    name: '',
    type: props.types[0] ?? '',
    min_amount: null,
    max_amount: null,
    role: '',
    params: '',
    active: true,
    sort_order: 0,
  }
  ruleDialog.value = true
}

function editRule(r: Rule): void {
  editing.value = r
  ruleForm.value = {
    name: r.name,
    type: r.type,
    min_amount: r.min_amount,
    max_amount: r.max_amount,
    role: r.role ?? '',
    params: r.params ? JSON.stringify(r.params, null, 2) : '',
    active: r.active,
    sort_order: r.sort_order ?? 0,
  }
  ruleDialog.value = true
}

function saveRule(): void {
  let params: any = null
  if (ruleForm.value.params.trim()) {
    try {
      params = JSON.parse(ruleForm.value.params)
    } catch {
      alert('Params must be valid JSON.')
      return
    }
  }
  const payload = { ...ruleForm.value, params }
  if (editing.value) {
    router.patch(
      route('procurement.policies.update', editing.value.id),
      payload,
      {
        preserveScroll: true,
        onSuccess: () => (ruleDialog.value = false),
      }
    )
  } else {
    router.post(
      route('procurement.policies.store'),
      payload,
      {
        preserveScroll: true,
        onSuccess: () => (ruleDialog.value = false),
      }
    )
  }
}

function deleteRule(r: Rule): void {
  confirmDialog.value?.open(
    {
      title: t('procurement_policies.delete.title'),
      message: t('procurement_policies.delete.message', { name: r.name }),
      confirmText: t('procurement_policies.delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(route('procurement.policies.destroy', r.id), { preserveScroll: true })
    }
  )
}

// ── Exception methods ──────────────────────────────────────────────────────
function approveException(e: Exception): void {
  router.post(route('procurement.policies.exceptions.approve', e.id), {}, { preserveScroll: true })
}

function rejectException(e: Exception): void {
  router.post(route('procurement.policies.exceptions.reject', e.id), {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="primary" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-shield-cog" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('procurement_policies.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('procurement_policies.page_description') }}</p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="rules">{{ t('procurement_policies.tabs.rules') }}</VTab>
      <VTab value="exceptions">{{ t('procurement_policies.tabs.exceptions') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- ── Rules Tab ────────────────────────────────────────────────────── -->
      <VWindowItem value="rules">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="newRule">
            {{ t('procurement_policies.new_rule') }}
          </VBtn>
        </div>
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('procurement_policies.table.name') }}</th>
                <th>{{ t('procurement_policies.table.type') }}</th>
                <th class="text-right">{{ t('procurement_policies.table.min_amount') }}</th>
                <th>{{ t('procurement_policies.table.role') }}</th>
                <th>{{ t('procurement_policies.table.active') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!rules.length">
                <td colspan="6" class="text-center text-medium-emphasis py-8">
                  {{ t('procurement_policies.no_rules') }}
                </td>
              </tr>
              <tr v-for="r in rules" :key="r.id">
                <td class="font-weight-medium">{{ r.name }}</td>
                <td>
                  <VChip size="small" variant="tonal">{{ getTypeLabel(r.type) }}</VChip>
                </td>
                <td class="text-right">{{ formatCurrency(r.min_amount) }}</td>
                <td>{{ r.role ?? '—' }}</td>
                <td>
                  <VChip
                    size="small"
                    :color="r.active ? 'success' : 'grey'"
                    variant="tonal"
                  >
                    {{ r.active ? t('procurement_policies.statuses.active') : t('procurement_policies.statuses.inactive') }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn icon="tabler-edit" size="small" variant="text" @click="editRule(r)" />
                  <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteRule(r)" />
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- ── Exceptions Tab ──────────────────────────────────────────────── -->
      <VWindowItem value="exceptions">
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('procurement_policies.table.rule_type') }}</th>
                <th>{{ t('procurement_policies.table.reason') }}</th>
                <th>{{ t('procurement_policies.table.status') }}</th>
                <th class="text-right">{{ t('procurement_policies.table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!exceptions.length">
                <td colspan="4" class="text-center text-medium-emphasis py-8">
                  {{ t('procurement_policies.no_exceptions') }}
                </td>
              </tr>
              <tr v-for="e in exceptions" :key="e.id">
                <td>
                  <VChip size="small" variant="tonal">{{ getTypeLabel(e.rule_type) }}</VChip>
                </td>
                <td>{{ e.reason ?? '—' }}</td>
                <td>
                  <VChip
                    size="small"
                    :color="getStatusColor(e.status)"
                    variant="tonal"
                  >
                    {{ getStatusLabel(e.status) }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn
                    size="small"
                    variant="tonal"
                    color="success"
                    class="me-2"
                    @click="approveException(e)"
                  >
                    {{ t('procurement_policies.actions.approve') }}
                  </VBtn>
                  <VBtn
                    size="small"
                    variant="tonal"
                    color="error"
                    @click="rejectException(e)"
                  >
                    {{ t('procurement_policies.actions.reject') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Rule Dialog ────────────────────────────────────────────────────── -->
    <VDialog v-model="ruleDialog" max-width="560">
      <VCard>
        <VCardTitle>
          {{ editing ? t('procurement_policies.dialog.title_edit') : t('procurement_policies.dialog.title_new') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12" md="7">
              <VTextField
                v-model="ruleForm.name"
                :label="t('procurement_policies.dialog.name_label')"
                variant="outlined"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VSelect
                v-model="ruleForm.type"
                :items="types"
                :label="t('procurement_policies.dialog.type_label')"
                variant="outlined"
                :item-title="(v: string) => getTypeLabel(v)"
              />
            </VCol>
          </VRow>
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="ruleForm.min_amount"
                type="number"
                :label="t('procurement_policies.dialog.min_amount_label')"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="ruleForm.max_amount"
                type="number"
                :label="t('procurement_policies.dialog.max_amount_label')"
                variant="outlined"
              />
            </VCol>
          </VRow>
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model="ruleForm.role"
                :label="t('procurement_policies.dialog.role_label')"
                variant="outlined"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="ruleForm.sort_order"
                type="number"
                :label="t('procurement_policies.dialog.sort_order_label')"
                variant="outlined"
              />
            </VCol>
          </VRow>
          <VTextarea
            v-model="ruleForm.params"
            :label="t('procurement_policies.dialog.params_label')"
            variant="outlined"
            rows="4"
            :placeholder="t('procurement_policies.dialog.params_placeholder')"
            class="mb-2"
          />
          <VSwitch
            v-model="ruleForm.active"
            :label="t('procurement_policies.dialog.active_label')"
            color="primary"
            hide-details
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="ruleDialog = false">
            {{ t('procurement_policies.dialog.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!ruleForm.name || !ruleForm.type"
            @click="saveRule"
          >
            {{ t('procurement_policies.dialog.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
