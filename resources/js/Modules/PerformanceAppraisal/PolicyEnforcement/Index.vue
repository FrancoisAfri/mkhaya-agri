<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('performance_policy.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('performance_policy.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-shield-search" :loading="detecting" :disabled="!selectedCycle" @click="detectViolations">
          {{ t('performance_policy.detect_violations') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-calculator" :loading="computing" @click="computeAll">
          {{ t('performance_policy.compute_compliance') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreatePolicy">
          {{ t('performance_policy.new_policy') }}
        </VBtn>
      </div>
    </div>

    <!-- Cycle filter -->
    <VSelect
      v-model="selectedCycle"
      :items="cycles"
      item-title="name"
      item-value="id"
      :label="t('performance_policy.cycle_label')"
      density="compact"
      variant="outlined"
      clearable
      hide-details
      style="max-width: 280px"
      class="mb-5"
      @update:model-value="applyFilter"
    />

    <!-- Stats strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-primary">{{ stats.active_policies }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('performance_policy.stats.active_policies') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-error">{{ stats.open_violations }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('performance_policy.stats.open_violations') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-green">{{ stats.avg_compliance }}%</p>
          <p class="text-caption text-medium-emphasis">{{ t('performance_policy.stats.avg_compliance') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-orange">{{ stats.active_exceptions }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('performance_policy.stats.active_exceptions') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="documents">
        <VIcon start size="16">tabler-file-text</VIcon>
        {{ t('performance_policy.tabs.documents') }}
        <VChip v-if="globalPolicies.length" class="ml-1" size="x-small" color="primary">{{ globalPolicies.length }}</VChip>
      </VTab>
      <VTab value="policies">
        <VIcon start>tabler-shield-check</VIcon>
        {{ t('performance_policy.tabs.policies') }} ({{ policies.length }})
      </VTab>
      <VTab value="violations">
        <VIcon start>tabler-alert-circle</VIcon>
        {{ t('performance_policy.tabs.violations') }}
        <VBadge v-if="openViolations.length" :content="openViolations.length" color="error" inline class="ml-1" />
      </VTab>
      <VTab value="compliance">
        <VIcon start>tabler-chart-donut</VIcon>
        {{ t('performance_policy.tabs.compliance') }} ({{ complianceScores.length }})
      </VTab>
      <VTab value="exceptions">
        <VIcon start>tabler-shield-off</VIcon>
        {{ t('performance_policy.tabs.exceptions') }} ({{ exceptions.length }})
      </VTab>
      <VTab value="escalations">
        <VIcon start>tabler-circle-arrow-up</VIcon>
        {{ t('performance_policy.tabs.escalations') }}
        <VBadge v-if="pendingEscalations.length" :content="pendingEscalations.length" color="warning" inline class="ml-1" />
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Policy Documents (from global Policy module) ──────────────────── -->
      <VTabsWindowItem value="documents">
        <VAlert v-if="!globalPolicies.length" type="info" variant="tonal" class="mb-0">
          <strong>{{ t('performance_policy.documents.empty_title') }}</strong>
          <p class="mb-0 mt-1">{{ t('performance_policy.documents.empty_text') }}</p>
        </VAlert>

        <VRow v-else dense>
          <VCol v-for="gp in globalPolicies" :key="gp.id" cols="12" md="6" lg="4">
            <VCard border flat height="100%">
              <VCardTitle class="text-body-2 font-weight-bold d-flex align-center gap-2 pt-3">
                <VIcon size="16" color="primary">tabler-file-text</VIcon>
                {{ gp.name }}
              </VCardTitle>
              <VCardText>
                <div class="d-flex align-center gap-2 mb-2 flex-wrap">
                  <VChip size="x-small" :color="gp.status === 'published' ? 'green' : 'blue'" variant="tonal">
                    {{ gp.status }}
                  </VChip>
                  <VChip v-if="gp.category" size="x-small" variant="outlined">{{ gp.category }}</VChip>
                </div>
                <p v-if="gp.description" class="text-caption text-medium-emphasis mb-2">{{ gp.description }}</p>
                <div class="text-caption text-medium-emphasis">
                  <span v-if="gp.effective_date">{{ t('performance_policy.documents.effective') }} {{ gp.effective_date }}</span>
                  <span v-if="gp.effective_date && gp.review_date"> · </span>
                  <span v-if="gp.review_date">{{ t('performance_policy.documents.review') }} {{ gp.review_date }}</span>
                </div>
                <div v-if="gp.tags?.length" class="mt-2 d-flex flex-wrap gap-1">
                  <VChip v-for="tag in gp.tags" :key="tag" size="x-small" variant="text">{{ tag }}</VChip>
                </div>
              </VCardText>
              <VCardActions>
                <VBtn
                  size="x-small" variant="tonal" color="primary"
                  :href="route('policies.show', gp.uuid)"
                  target="_blank"
                >
                  <VIcon start size="14">tabler-external-link</VIcon>
                  {{ t('performance_policy.documents.view_policy') }}
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
      </VTabsWindowItem>

      <!-- ── Policies ───────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="policies">
        <VList v-if="policies.length" class="pa-0">
          <VListItem
            v-for="p in policies"
            :key="p.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="levelColor(p.enforcement_level)" variant="tonal" size="38">
                <VIcon size="16">{{ levelIcon(p.enforcement_level) }}</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ p.name }}</span>
              <VChip size="x-small" :color="typeColor(p.policy_type)" variant="tonal" class="ml-2">{{ p.policy_type }}</VChip>
            </template>
            <template #subtitle>
              <span class="text-caption text-medium-emphasis">{{ p.description ?? p.condition_type }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="levelColor(p.enforcement_level)" variant="flat">
                  {{ levelLabel(p.enforcement_level) }}
                </VChip>
                <VBadge v-if="p.open_violations_count" :content="p.open_violations_count" color="error" inline />
                <VSwitch
                  :model-value="p.is_active"
                  color="primary"
                  density="compact"
                  hide-details
                  @update:model-value="togglePolicy(p, $event)"
                />
                <VBtn size="x-small" icon variant="text" @click="openEditPolicy(p)">
                  <VIcon>tabler-pencil</VIcon>
                </VBtn>
                <VBtn size="x-small" icon variant="text" color="error" @click="openDeletePolicy(p)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('performance_policy.policies.no_policies') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Violations ─────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="violations">
        <div class="d-flex ga-2 mb-3">
          <VChipGroup v-model="violationFilter" mandatory>
            <VChip value="open" size="small" variant="tonal">{{ t('performance_policy.violations.filter_open') }}</VChip>
            <VChip value="all"  size="small" variant="tonal">{{ t('performance_policy.violations.filter_all') }}</VChip>
          </VChipGroup>
        </div>
        <VList v-if="filteredViolations.length" class="pa-0">
          <VListItem
            v-for="v in filteredViolations"
            :key="v.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="levelColor(v.policy_level)" variant="tonal" size="38">
                <VIcon size="16">{{ levelIcon(v.policy_level) }}</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ v.employee_name }}</span>
              <span class="text-caption text-medium-emphasis ml-1">· {{ v.policy_name }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ actionLabel(v.action_type) }}</span>
              <span v-if="v.violation_data" class="text-caption text-medium-emphasis">
                <span v-if="v.violation_data.avg_score"> · Avg {{ v.violation_data.avg_score }}%</span>
                <span v-if="v.violation_data.days_open"> · {{ v.violation_data.days_open }} days open</span>
              </span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="statusColor(v.status)" variant="flat">{{ v.status }}</VChip>
                <template v-if="['detected', 'escalated'].includes(v.status)">
                  <VBtn size="x-small" variant="tonal" color="warning" @click="openEscalate(v)">{{ t('performance_policy.violations.escalate') }}</VBtn>
                  <VBtn size="x-small" variant="tonal" color="orange" @click="openExempt(v)">{{ t('performance_policy.violations.exempt') }}</VBtn>
                  <VBtn size="x-small" variant="tonal" color="success" @click="resolveViolation(v.id)">{{ t('performance_policy.violations.resolve') }}</VBtn>
                </template>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="success" variant="tonal">{{ t('performance_policy.violations.no_violations') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Compliance Scores ───────────────────────────────────────────────── -->
      <VTabsWindowItem value="compliance">
        <VRow v-if="complianceScores.length" dense>
          <VCol v-for="cs in complianceScores" :key="cs.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="cs.grade_color" variant="flat" size="42">
                    <span class="text-body-2 font-weight-bold text-white">{{ Math.round(cs.compliance_score) }}</span>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-medium">{{ cs.employee_name }}</VCardTitle>
                <VCardSubtitle>{{ cs.grade_label }}</VCardSubtitle>
                <template #append>
                  <div class="text-right">
                    <p class="text-caption text-medium-emphasis">{{ t('performance_policy.compliance.passed', { passed: cs.passed_checks, total: cs.total_checks }) }}</p>
                    <p v-if="cs.violation_count" class="text-caption text-error">{{ t('performance_policy.compliance.violations_count', { count: cs.violation_count }) }}</p>
                  </div>
                </template>
              </VCardItem>
              <VCardText class="pt-0 pb-3">
                <VProgressLinear
                  :model-value="cs.compliance_score"
                  :color="cs.grade_color"
                  height="6"
                  rounded
                />
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('performance_policy.compliance.no_compliance') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Exceptions ─────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="exceptions">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-shield-plus" @click="exceptionOpen = true">
            {{ t('performance_policy.exceptions.grant_exception') }}
          </VBtn>
        </div>
        <VList v-if="exceptions.length" class="pa-0">
          <VListItem
            v-for="ex in exceptions"
            :key="ex.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar color="orange" variant="tonal" size="38">
                <VIcon size="16">tabler-shield-off</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ ex.employee_name }}</span>
              <span class="text-caption text-medium-emphasis ml-1">· {{ ex.policy_name }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ ex.reason }}</span>
              <span v-if="ex.valid_until" class="text-caption text-medium-emphasis"> · {{ t('performance_policy.exceptions.expires') }} {{ ex.valid_until }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <span v-if="ex.approved_by_name" class="text-caption text-medium-emphasis">
                  {{ t('performance_policy.exceptions.by') }} {{ ex.approved_by_name }}
                </span>
                <VBtn size="x-small" icon variant="text" color="error" @click="revokeException(ex.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('performance_policy.exceptions.no_exceptions') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Escalations ────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="escalations">
        <VList v-if="escalations.length" class="pa-0">
          <VListItem
            v-for="esc in escalations"
            :key="esc.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="escalationLevelColor(esc.escalation_level)" variant="tonal" size="38">
                <span class="text-caption font-weight-bold">L{{ esc.escalation_level }}</span>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ esc.employee_name }}</span>
              <span class="text-caption text-medium-emphasis ml-1">· {{ esc.policy_name }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ t('performance_policy.escalations.escalated_to') }}: {{ esc.escalated_to_name }}</span>
              <span v-if="esc.message" class="text-caption text-medium-emphasis"> · {{ esc.message }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="escalationStatusColor(esc.status)" variant="tonal">
                  {{ esc.status }}
                </VChip>
                <VBtn
                  v-if="esc.status === 'pending'"
                  size="x-small"
                  variant="tonal"
                  color="blue"
                  @click="acknowledgeEscalation(esc.id)"
                >
                  {{ t('performance_policy.escalations.acknowledge') }}
                </VBtn>
                <VBtn
                  v-if="esc.status !== 'resolved'"
                  size="x-small"
                  variant="tonal"
                  color="success"
                  @click="resolveEscalation(esc.id)"
                >
                  {{ t('performance_policy.escalations.resolve') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="success" variant="tonal">{{ t('performance_policy.escalations.no_escalations') }}</VAlert>
      </VTabsWindowItem>

    </VTabsWindow>

    <!-- Create / Edit Policy drawer -->
    <VNavigationDrawer v-model="policyDrawer" location="right" width="500" temporary>
      <VToolbar :color="editingPolicy ? 'secondary' : 'primary'" density="compact">
        <VToolbarTitle class="text-body-1">{{ editingPolicy ? t('performance_policy.drawer.edit_title') : t('performance_policy.drawer.new_title') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="policyDrawer = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>
      <VForm ref="policyForm" @submit.prevent="submitPolicy" class="pa-4">
        <VTextField v-model="pForm.name" :label="t('performance_policy.drawer.policy_name')" density="compact" variant="outlined" :rules="[v => !!v || t('performance_policy.drawer.required')]" class="mb-3" />
        <VTextField
          v-model="pForm.code"
          :label="t('performance_policy.drawer.policy_code')"
          density="compact"
          variant="outlined"
          :hint="t('performance_policy.drawer.policy_code_hint')"
          :rules="[v => !!v || t('performance_policy.drawer.required')]"
          :disabled="!!editingPolicy"
          class="mb-3"
        />
        <VSelect
          v-model="pForm.policy_type"
          :items="policyTypes"
          :label="t('performance_policy.drawer.policy_type')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || t('performance_policy.drawer.required')]"
          class="mb-3"
        />
        <VSelect
          v-model="pForm.condition_type"
          :items="conditionTypes"
          item-title="label"
          item-value="value"
          :label="t('performance_policy.drawer.condition_type')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || t('performance_policy.drawer.required')]"
          class="mb-3"
          @update:model-value="applyConfigTemplate"
        />
        <VTextarea
          v-model="pForm.condition_config_json"
          :label="t('performance_policy.drawer.condition_config')"
          density="compact"
          variant="outlined"
          rows="3"
          :hint="configHint"
          class="mb-3"
        />
        <VSelect
          v-model="pForm.enforcement_level"
          :items="enforcementLevels"
          item-title="label"
          item-value="value"
          :label="t('performance_policy.drawer.enforcement_level')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || t('performance_policy.drawer.required')]"
          class="mb-3"
        />
        <VTextarea v-model="pForm.description" :label="t('performance_policy.drawer.description')" density="compact" variant="outlined" rows="2" class="mb-4" />
        <VBtn type="submit" color="primary" block :loading="savingPolicy">
          {{ editingPolicy ? t('performance_policy.drawer.save') : t('performance_policy.drawer.create') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Delete Policy dialog -->
    <VDialog v-model="delPolicyOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('performance_policy.delete.title') }}</VCardTitle>
        <VCardText>{{ t('performance_policy.delete.confirm') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delPolicyOpen = false">{{ t('performance_policy.delete.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingPolicy" @click="confirmDeletePolicy">{{ t('performance_policy.delete.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Escalate violation dialog -->
    <VDialog v-model="escalateOpen" max-width="440">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('performance_policy.escalate_dialog.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="escalateForm.escalated_to"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('performance_policy.escalate_dialog.escalate_to')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-model="escalateForm.escalation_level"
            :items="escalationLevelOptions"
            item-title="title"
            item-value="value"
            :label="t('performance_policy.escalate_dialog.escalation_level')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextarea v-model="escalateForm.message" :label="t('performance_policy.escalate_dialog.notes')" density="compact" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="escalateOpen = false">{{ t('performance_policy.escalate_dialog.cancel') }}</VBtn>
          <VBtn color="warning" variant="flat" :loading="savingEscalate" :disabled="!escalateForm.escalated_to" @click="submitEscalate">
            {{ t('performance_policy.escalate_dialog.escalate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Exempt violation dialog -->
    <VDialog v-model="exemptOpen" max-width="420">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('performance_policy.exempt_dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="exemptForm.reason" :label="t('performance_policy.exempt_dialog.reason')" density="compact" variant="outlined" rows="3" class="mb-3" />
          <VTextField v-model="exemptForm.valid_until" :label="t('performance_policy.exempt_dialog.valid_until')" type="date" density="compact" variant="outlined" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="exemptOpen = false">{{ t('performance_policy.exempt_dialog.cancel') }}</VBtn>
          <VBtn color="orange" variant="flat" :loading="savingExempt" :disabled="!exemptForm.reason" @click="submitExempt">
            {{ t('performance_policy.exempt_dialog.grant') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Grant Exception dialog (standalone) -->
    <VDialog v-model="exceptionOpen" max-width="440">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('performance_policy.exception_dialog.title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="exForm.policy_id"
            :items="policies"
            item-title="name"
            item-value="id"
            :label="t('performance_policy.exception_dialog.policy')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-model="exForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('performance_policy.exception_dialog.employee')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextarea v-model="exForm.reason" :label="t('performance_policy.exception_dialog.reason')" density="compact" variant="outlined" rows="2" class="mb-3" />
          <VTextField v-model="exForm.valid_until" :label="t('performance_policy.exception_dialog.valid_until')" type="date" density="compact" variant="outlined" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="exceptionOpen = false">{{ t('performance_policy.exception_dialog.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingException" :disabled="!exForm.policy_id || !exForm.employee_id || !exForm.reason" @click="submitException">
            {{ t('performance_policy.exception_dialog.grant') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface Policy {
  id: number; name: string; code: string; description: string | null
  policy_type: string; condition_type: string; condition_config: Record<string, any> | null
  enforcement_level: string; is_active: boolean; open_violations_count: number
}

interface Violation {
  id: number; employee_id: number; employee_name: string
  policy_name: string; policy_level: string
  action_type: string; violation_data: Record<string, any> | null
  status: string; resolved_at: string | null
}

interface ComplianceScore {
  id: number; employee_id: number; employee_name: string
  compliance_score: number; total_checks: number; passed_checks: number
  violation_count: number; exception_count: number
  grade_label: string; grade_color: string
}

interface Exception {
  id: number; employee_id: number; employee_name: string
  policy_id: number; policy_name: string
  reason: string; valid_until: string | null; approved_by_name: string | null
}

interface Escalation {
  id: number; violation_id: number; escalated_to: number; escalated_to_name: string
  escalation_level: number; message: string | null; status: string
  policy_name: string; employee_name: string; action_type: string
}

interface Cycle        { id: number; name: string }
interface Employee     { id: number; name: string }
interface GlobalPolicy {
  id: number; uuid: string; name: string; description: string | null
  status: string; category: string | null
  effective_date: string | null; review_date: string | null; tags: string[]
}

const props = defineProps<{
  policies:         Policy[]
  violations:       Violation[]
  complianceScores: ComplianceScore[]
  exceptions:       Exception[]
  escalations:      Escalation[]
  cycles:           Cycle[]
  employees:        Employee[]
  selectedCycle:    number | null
  stats:            { active_policies: number; open_violations: number; avg_compliance: number; active_exceptions: number }
  globalPolicies:   GlobalPolicy[]
}>()

const tab           = ref('policies')
const selectedCycle = ref<number | null>(props.selectedCycle)
const detecting     = ref(false)
const computing     = ref(false)

// Computed
const openViolations      = computed(() => props.violations.filter(v => ['detected', 'escalated'].includes(v.status)))
const pendingEscalations  = computed(() => props.escalations.filter(e => e.status === 'pending'))
const violationFilter     = ref('open')
const filteredViolations  = computed(() =>
  violationFilter.value === 'open' ? openViolations.value : props.violations
)

function applyFilter() {
  router.get(route('performance.policy.index'), {
    cycle_id: selectedCycle.value ?? undefined,
  }, { preserveState: true, replace: true })
}

function detectViolations() {
  if (!selectedCycle.value) return
  detecting.value = true
  router.post(route('performance.policy.detect'), { cycle_id: selectedCycle.value }, {
    onFinish: () => { detecting.value = false },
  })
}

function computeAll() {
  computing.value = true
  router.post(route('performance.policy.compliance.compute'), { cycle_id: selectedCycle.value }, {
    onFinish: () => { computing.value = false },
  })
}

// ── Policy CRUD ───────────────────────────────────────────────────────────────
const policyDrawer  = ref(false)
const savingPolicy  = ref(false)
const editingPolicy = ref<Policy | null>(null)
const policyForm    = ref()
const delPolicyOpen = ref(false)
const deletingPolicy = ref(false)
const delPolicyItem = ref<Policy | null>(null)

const policyTypes     = ['appraisal', 'probation', 'bonus', 'kpi', 'feedback', 'general']
const enforcementLevels = [
  { label: t('performance_policy.enforcement_levels.warning'), value: 'warning' },
  { label: t('performance_policy.enforcement_levels.soft_block'), value: 'soft_block' },
  { label: t('performance_policy.enforcement_levels.hard_block'), value: 'hard_block' },
]
const conditionTypes = [
  { label: t('performance_policy.condition_types.threshold_check'), value: 'threshold_check' },
  { label: t('performance_policy.condition_types.days_limit'), value: 'days_limit' },
  { label: t('performance_policy.condition_types.evidence_required'), value: 'evidence_required' },
  { label: t('performance_policy.condition_types.sign_off_lock'), value: 'sign_off_lock' },
  { label: t('performance_policy.condition_types.relative_check'), value: 'relative_check' },
  { label: t('performance_policy.condition_types.calibration_required'), value: 'calibration_required' },
]

const escalationLevelOptions = [
  { title: t('performance_policy.escalate_dialog.levels.1'), value: 1 },
  { title: t('performance_policy.escalate_dialog.levels.2'), value: 2 },
  { title: t('performance_policy.escalate_dialog.levels.3'), value: 3 },
]

const configTemplates: Record<string, string> = {
  threshold_check:     '{"threshold": 40}',
  days_limit:          '{"max_days": 90}',
  evidence_required:   '{"min_evidence": 1}',
  sign_off_lock:       '{"locked_statuses": ["signed_off", "approved"]}',
  relative_check:      '{}',
  calibration_required:'{}',
}

const configHints: Record<string, string> = {
  threshold_check:     t('performance_policy.config_hints.threshold_check'),
  days_limit:          t('performance_policy.config_hints.days_limit'),
  evidence_required:   t('performance_policy.config_hints.evidence_required'),
  sign_off_lock:       t('performance_policy.config_hints.sign_off_lock'),
  relative_check:      t('performance_policy.config_hints.relative_check'),
  calibration_required: t('performance_policy.config_hints.calibration_required'),
}

const pForm = reactive({
  name: '', code: '', description: '', policy_type: 'general',
  condition_type: '', condition_config_json: '{}', enforcement_level: 'warning',
})

const configHint = computed(() => configHints[pForm.condition_type] ?? '')

function applyConfigTemplate(type: string) {
  pForm.condition_config_json = configTemplates[type] ?? '{}'
}

function openCreatePolicy() {
  editingPolicy.value = null
  Object.assign(pForm, {
    name: '', code: '', description: '', policy_type: 'general',
    condition_type: '', condition_config_json: '{}', enforcement_level: 'warning',
  })
  policyDrawer.value = true
}

function openEditPolicy(p: Policy) {
  editingPolicy.value = p
  Object.assign(pForm, {
    name: p.name, code: p.code, description: p.description ?? '',
    policy_type: p.policy_type, condition_type: p.condition_type,
    condition_config_json: p.condition_config ? JSON.stringify(p.condition_config, null, 2) : '{}',
    enforcement_level: p.enforcement_level,
  })
  policyDrawer.value = true
}

async function submitPolicy() {
  const { valid } = await policyForm.value.validate()
  if (!valid) return

  let config: Record<string, any> | null = null
  try {
    config = JSON.parse(pForm.condition_config_json || '{}')
  } catch {
    return
  }

  savingPolicy.value = true
  const payload = {
    name: pForm.name, code: pForm.code, description: pForm.description,
    policy_type: pForm.policy_type, condition_type: pForm.condition_type,
    condition_config: config, enforcement_level: pForm.enforcement_level,
  }

  if (editingPolicy.value) {
    router.patch(route('performance.policy.update', editingPolicy.value.id), payload, {
      onSuccess: () => { policyDrawer.value = false },
      onFinish:  () => { savingPolicy.value = false },
    })
  } else {
    router.post(route('performance.policy.store'), payload, {
      onSuccess: () => { policyDrawer.value = false },
      onFinish:  () => { savingPolicy.value = false },
    })
  }
}

function togglePolicy(p: Policy, val: boolean) {
  router.patch(route('performance.policy.update', p.id), { is_active: val })
}

function openDeletePolicy(p: Policy) {
  delPolicyItem.value = p
  delPolicyOpen.value = true
}

function confirmDeletePolicy() {
  if (!delPolicyItem.value) return
  deletingPolicy.value = true
  router.delete(route('performance.policy.destroy', delPolicyItem.value.id), {
    onSuccess: () => { delPolicyOpen.value = false },
    onFinish:  () => { deletingPolicy.value = false },
  })
}

// ── Violation actions ─────────────────────────────────────────────────────────
const escalateOpen   = ref(false)
const savingEscalate = ref(false)
const escalateTarget = ref<Violation | null>(null)
const escalateForm   = reactive<{ escalated_to: number | null; escalation_level: number; message: string }>({
  escalated_to: null, escalation_level: 1, message: '',
})

function openEscalate(v: Violation) {
  escalateTarget.value = v
  Object.assign(escalateForm, { escalated_to: null, escalation_level: 1, message: '' })
  escalateOpen.value = true
}

function submitEscalate() {
  if (!escalateTarget.value) return
  savingEscalate.value = true
  router.post(route('performance.policy.violations.escalate', escalateTarget.value.id), escalateForm as any, {
    onSuccess: () => { escalateOpen.value = false },
    onFinish:  () => { savingEscalate.value = false },
  })
}

const exemptOpen   = ref(false)
const savingExempt = ref(false)
const exemptTarget = ref<Violation | null>(null)
const exemptForm   = reactive({ reason: '', valid_until: '' })

function openExempt(v: Violation) {
  exemptTarget.value = v
  Object.assign(exemptForm, { reason: '', valid_until: '' })
  exemptOpen.value = true
}

function submitExempt() {
  if (!exemptTarget.value) return
  savingExempt.value = true
  router.post(route('performance.policy.violations.exempt', exemptTarget.value.id), exemptForm, {
    onSuccess: () => { exemptOpen.value = false },
    onFinish:  () => { savingExempt.value = false },
  })
}

function resolveViolation(id: number) {
  router.patch(route('performance.policy.violations.resolve', id))
}

// ── Exception management ──────────────────────────────────────────────────────
const exceptionOpen   = ref(false)
const savingException = ref(false)
const exForm          = reactive<{ policy_id: number | null; employee_id: number | null; reason: string; valid_until: string }>({
  policy_id: null, employee_id: null, reason: '', valid_until: '',
})

function submitException() {
  savingException.value = true
  router.post(route('performance.policy.exceptions.store'), exForm as any, {
    onSuccess: () => { exceptionOpen.value = false },
    onFinish:  () => { savingException.value = false },
  })
}

function revokeException(id: number) {
  router.delete(route('performance.policy.exceptions.destroy', id))
}

// ── Escalation management ─────────────────────────────────────────────────────
function acknowledgeEscalation(id: number) {
  router.patch(route('performance.policy.escalations.acknowledge', id))
}

function resolveEscalation(id: number) {
  router.patch(route('performance.policy.escalations.resolve', id))
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function levelColor(level: string): string {
  return ({ warning: 'orange', soft_block: 'deep-orange', hard_block: 'error' } as Record<string, string>)[level] ?? 'grey'
}

function levelIcon(level: string): string {
  return ({ warning: 'tabler-alert-triangle', soft_block: 'tabler-shield-exclamation', hard_block: 'tabler-shield-off' } as Record<string, string>)[level] ?? 'tabler-shield'
}

function levelLabel(level: string): string {
  const labels: Record<string, string> = {
    warning: t('performance_policy.enforcement_levels.warning'),
    soft_block: t('performance_policy.enforcement_levels.soft_block'),
    hard_block: t('performance_policy.enforcement_levels.hard_block'),
  }
  return labels[level] ?? level
}

function typeColor(type: string): string {
  return ({
    appraisal: 'blue', probation: 'orange', bonus: 'green',
    kpi: 'purple', feedback: 'teal', general: 'grey',
  } as Record<string, string>)[type] ?? 'grey'
}

function statusColor(status: string): string {
  return ({
    detected: 'error', escalated: 'warning', exempted: 'orange', resolved: 'success',
  } as Record<string, string>)[status] ?? 'grey'
}

function actionLabel(action: string): string {
  const labels: Record<string, string> = {
    low_performance_no_pip: t('performance_policy.violations.labels.low_performance_no_pip'),
    probation_overdue:      t('performance_policy.violations.labels.probation_overdue'),
    kpi_no_evidence:        t('performance_policy.violations.labels.kpi_no_evidence'),
  }
  return labels[action] ?? action.replace(/_/g, ' ')
}

function escalationLevelColor(level: number): string {
  return (['', 'blue', 'orange', 'error'] as string[])[level] ?? 'grey'
}

function escalationStatusColor(status: string): string {
  return ({ pending: 'warning', acknowledged: 'blue', resolved: 'success' } as Record<string, string>)[status] ?? 'grey'
}
</script>
