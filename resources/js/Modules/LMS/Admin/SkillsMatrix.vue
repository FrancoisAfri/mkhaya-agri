<template>
  <div class="p-3">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_skills_matrix.page_title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_skills_matrix.page_description') }}</div>
      </div>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="mdi-refresh" @click="applyFilters">
        {{ t('lms_skills_matrix.refresh') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap pa-3">
        <VSelect
          v-model="filterPosition"
          :items="[{ id: null, name: t('lms_skills_matrix.all_positions') }, ...positions]"
          item-title="name"
          item-value="id"
          :label="t('lms_skills_matrix.table.job_title')"
          density="compact"
          hide-details
          style="max-width:220px"
        />
        <VSelect
          v-model="filterCategory"
          :items="[{ value: null, title: t('lms_skills_matrix.all_skill_categories') }, ...categoryItems]"
          item-title="title"
          item-value="value"
          :label="t('lms_skills_matrix.page_title')"
          density="compact"
          hide-details
          style="max-width:200px"
        />
        <VBtn color="primary" density="compact" @click="applyFilters">
          {{ t('lms_skills_matrix.apply') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Empty state -->
    <VCard v-if="!matrix.skills.length || !matrix.employees.length">
      <VCardText class="text-center py-10 text-medium-emphasis">
        <VIcon size="48" class="mb-2">mdi-table-off</VIcon>
        <div>{{ t('lms_skills_matrix.no_data') }}</div>
      </VCardText>
    </VCard>

    <!-- Matrix table -->
    <VCard v-else class="overflow-x-auto">
      <!-- Summary row -->
      <VCardText class="pa-3 pb-0">
        <div class="d-flex gap-4 flex-wrap text-caption text-medium-emphasis mb-2">
          <span><VIcon size="12" color="success">mdi-circle</VIcon> {{ t('lms_skills_matrix.legend.meets') }}</span>
          <span><VIcon size="12" color="warning">mdi-circle</VIcon> {{ t('lms_skills_matrix.legend.partial') }}</span>
          <span><VIcon size="12" color="error">mdi-circle</VIcon> {{ t('lms_skills_matrix.legend.gap') }}</span>
          <span><VIcon size="12" color="primary">mdi-circle</VIcon> {{ t('lms_skills_matrix.legend.has') }}</span>
          <span><VIcon size="12" color="grey">mdi-circle</VIcon> {{ t('lms_skills_matrix.legend.na') }}</span>
        </div>
      </VCardText>

      <div class="overflow-x-auto">
        <table class="skills-matrix-table">
          <thead>
            <tr>
              <th class="sticky-col employee-col text-left pa-2">{{ t('lms_skills_matrix.table.employee') }}</th>
              <th class="sticky-col title-col text-left pa-2">{{ t('lms_skills_matrix.table.job_title') }}</th>
              <th
                v-for="skill in matrix.skills"
                :key="skill.id"
                class="skill-header pa-2 text-center"
                :title="skill.name"
              >
                <div class="skill-name-rotated">{{ skill.name }}</div>
                <VChip
                  v-if="matrix.summary[skill.id]"
                  :color="matrix.summary[skill.id].gap_rate > 30 ? 'error' : matrix.summary[skill.id].gap_rate > 10 ? 'warning' : 'success'"
                  size="x-small"
                  class="mt-1"
                  :title="`${matrix.summary[skill.id].gap_rate}% gap rate`"
                >
                  {{ matrix.summary[skill.id].gap_rate }}%
                </VChip>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="emp in matrix.employees" :key="emp.user_id" class="matrix-row">
              <td class="sticky-col employee-col pa-2">
                <div class="font-weight-medium text-body-2">{{ emp.name }}</div>
              </td>
              <td class="sticky-col title-col pa-2">
                <div class="text-caption text-medium-emphasis">{{ emp.job_title }}</div>
              </td>
              <td
                v-for="skill in matrix.skills"
                :key="skill.id"
                class="skill-cell text-center pa-1"
              >
                <SkillCell :data="emp.skills[skill.id]" :max="skill.max_level" />
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="summary-row">
              <td class="sticky-col pa-2 text-caption font-weight-medium">{{ t('lms_skills_matrix.table.summary') }}</td>
              <td class="sticky-col pa-2"></td>
              <td
                v-for="skill in matrix.skills"
                :key="skill.id"
                class="pa-2 text-center"
              >
                <div v-if="matrix.summary[skill.id]" class="text-caption">
                  <div class="text-success">{{ t('lms_skills_matrix.summary.meets', { count: matrix.summary[skill.id].meets }) }}</div>
                  <div class="text-error">{{ t('lms_skills_matrix.summary.gaps', { count: matrix.summary[skill.id].gaps }) }}</div>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </VCard>

    <!-- Employee gap detail panel -->
    <VDialog v-model="gapDialog" max-width="520" scrollable>
      <VCard v-if="selectedEmployee">
        <VCardTitle class="pa-4">{{ t('lms_skills_matrix.gap_dialog.title', { name: selectedEmployee.name }) }}</VCardTitle>
        <VCardText>
          <VList density="compact">
            <VListItem
              v-for="skill in matrix.skills"
              :key="skill.id"
              rounded="lg"
              class="mb-1"
            >
              <template #prepend>
                <VAvatar
                  :color="statusColor(selectedEmployee.skills[skill.id]?.status)"
                  variant="tonal"
                  size="28"
                >
                  <span class="text-caption font-weight-bold">
                    {{ selectedEmployee.skills[skill.id]?.current ?? 0 }}
                  </span>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2">{{ skill.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ t('lms_skills_matrix.gap_dialog.current') }}: {{ selectedEmployee.skills[skill.id]?.current ?? 0 }} /
                <span v-if="selectedEmployee.skills[skill.id]?.required != null">
                  {{ t('lms_skills_matrix.gap_dialog.required') }}: {{ selectedEmployee.skills[skill.id]?.required }}
                </span>
                <span v-else>{{ t('lms_skills_matrix.gap_dialog.no_requirement') }}</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="gapDialog = false">{{ t('lms_skills_matrix.gap_dialog.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, defineComponent, h } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  matrix: { skills: any[]; employees: any[]; summary: Record<number, any> }
  positions: any[]
  allSkills: any[]
  categories: Record<string, string>
  filters: Record<string, any>
}>()

const filterPosition = ref(props.filters.position_id ?? null)
const filterCategory = ref(props.filters.category ?? null)
const gapDialog      = ref(false)
const selectedEmployee = ref<any>(null)

const categoryItems = computed(() =>
  Object.entries(props.categories).map(([value, title]) => ({ value, title }))
)

function applyFilters() {
  router.get(
    route('lms.admin.skills-matrix'),
    { position_id: filterPosition.value, category: filterCategory.value },
    { preserveState: true, replace: true }
  )
}

function statusColor(s: string) {
  return s === 'meets' ? 'success' : s === 'partial' ? 'warning' : s === 'gap' ? 'error' : s === 'has' ? 'primary' : 'grey'
}

function openGap(emp: any) {
  selectedEmployee.value = emp
  gapDialog.value = true
}

// Inline component for matrix cell
const SkillCell = defineComponent({
  props: { data: Object, max: Number },
  setup(p) {
    return () => {
      const d = p.data
      if (!d || d.status === 'na') return h('span', { class: 'text-grey-lighten-1 text-caption' }, '—')

      const colorMap: Record<string, string> = { meets: '#4CAF50', partial: '#FF9800', gap: '#F44336', has: '#2196F3' }
      const color = colorMap[d.status] ?? '#9E9E9E'

      return h('div', { class: 'd-flex flex-column align-center gap-1' }, [
        h('div', {
          style: {
            width: '28px', height: '28px', borderRadius: '6px',
            background: color, color: 'white', display: 'flex',
            alignItems: 'center', justifyContent: 'center',
            fontSize: '11px', fontWeight: 'bold',
          },
          title: `Level ${d.current}${d.required != null ? ` / ${d.required} required` : ''}`,
        }, String(d.current || 0)),
        d.verified
          ? h('div', { style: 'font-size:9px;color:#4CAF50' }, '✓ verified')
          : null,
      ])
    }
  },
})
</script>

<style scoped>
.skills-matrix-table {
  border-collapse: collapse;
  min-width: 100%;
  font-size: 13px;
}

.skills-matrix-table th,
.skills-matrix-table td {
  border: 1px solid rgba(var(--v-border-color), 0.12);
  white-space: nowrap;
  vertical-align: middle;
}

.sticky-col {
  position: sticky;
  background: rgb(var(--v-theme-surface));
  z-index: 1;
}

.employee-col { left: 0; min-width: 160px; }
.title-col    { left: 160px; min-width: 130px; border-right: 2px solid rgba(var(--v-border-color), 0.24); }

.skill-header {
  min-width: 56px;
  max-width: 56px;
  height: 110px;
  vertical-align: bottom;
  padding-bottom: 6px !important;
}

.skill-name-rotated {
  writing-mode: vertical-rl;
  transform: rotate(180deg);
  font-size: 11px;
  max-height: 80px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.skill-cell { min-width: 56px; max-width: 56px; }

.summary-row td {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  font-size: 11px;
}

.matrix-row:hover td { background: rgba(var(--v-theme-primary), 0.04); }
</style>
