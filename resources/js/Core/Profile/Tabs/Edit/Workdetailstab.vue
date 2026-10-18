<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface CompanyUnitFromServer {
    id: number; name: string; parent_id: number | null; type: string;
}

interface Level { selectedId: number | null; children: CompanyUnitFromServer[]; }

const props = defineProps<{
    form: any; positions: { id: number; name: string }[];
    managers: { id: number; person: { first_name: string; surname: string } }[];
    leaves: { id: number; name: string }[]; canEditDirectly: boolean;
    companyUnits: { divisions: CompanyUnitFromServer[]; departments: CompanyUnitFromServer[]; teams: CompanyUnitFromServer[]; sections: CompanyUnitFromServer[]; units: CompanyUnitFromServer[] };
    selectedCompanyIds: number[];
}>();

const emit = defineEmits<{ (e: "update:companyIds", ids: number[]): void }>();

const typeConfig: Record<string, { label: string; icon: string; color: string; bg: string }> = {
    division: { label: t('profile_edit_tabs.select_division').replace('Sélectionner la ', '').replace('Select ', '').replace('Kies ', '').replace('Khetha ', '').replace('Tlhopha ', ''), icon: "tabler-building", color: "#6366f1", bg: "#eef2ff" },
    department: { label: "Department", icon: "tabler-sitemap", color: "#0d9488", bg: "#f0fdfa" },
    team: { label: "Team", icon: "tabler-users", color: "#f59e0b", bg: "#fffbeb" },
    section: { label: "Section", icon: "tabler-layout-dashboard", color: "#ec4899", bg: "#fdf2f8" },
    unit: { label: "Unit", icon: "tabler-cube", color: "#64748b", bg: "#f8fafc" },
};

const capitalize = (value: string | undefined) => value ? value.replace(/_/g, " ").replace(/\b\w/g, (match) => match.toUpperCase()) : "";
const getTypeConfig = (type: string | undefined) => typeConfig[type ?? ""] ?? { label: capitalize(type ?? "Unit"), icon: "tabler-cube", color: "#64748b", bg: "#f8fafc" };

const allUnits = computed<CompanyUnitFromServer[]>(() => [...(props.companyUnits.divisions ?? []), ...(props.companyUnits.departments ?? []), ...(props.companyUnits.teams ?? []), ...(props.companyUnits.sections ?? []), ...(props.companyUnits.units ?? [])]);
const roots = computed(() => allUnits.value.filter((u) => u.parent_id === null));
const levels = ref<Level[]>([]);

const buildChain = (parentId: number | null, selectedSet: Set<number>): void => {
    const children = parentId === null ? roots.value : allUnits.value.filter((u) => u.parent_id === parentId);
    if (!children.length) return;
    const selectedChild = children.find((c) => selectedSet.has(c.id));
    levels.value.push({ selectedId: selectedChild?.id ?? null, children });
    if (selectedChild) { const hasChildren = allUnits.value.some((u) => u.parent_id === selectedChild.id); if (hasChildren) buildChain(selectedChild.id, selectedSet); }
};

const rebuildFromSelected = (ids: number[]) => { if (!allUnits.value.length || !roots.value.length) return; levels.value = []; buildChain(null, new Set(ids)); };
const initLevels = () => { if (!roots.value.length) return; if (props.selectedCompanyIds?.length) { rebuildFromSelected(props.selectedCompanyIds); } else { levels.value = [{ selectedId: null, children: roots.value }]; } };

const onLevelSelect = (levelIndex: number, selectedId: number | null) => {
    levels.value[levelIndex].selectedId = selectedId; levels.value = levels.value.slice(0, levelIndex + 1);
    if (selectedId !== null) { const children = allUnits.value.filter((u) => u.parent_id === selectedId); if (children.length > 0) { levels.value.push({ selectedId: null, children }); } }
    const ids = levels.value.map((l) => l.selectedId).filter((id): id is number => id !== null);
    emit("update:companyIds", ids);
};

const today = new Date().toISOString().split("T")[0];

watch([() => props.companyUnits, () => props.selectedCompanyIds], ([_, ids]) => { if (ids?.length) { rebuildFromSelected(ids); } else if (roots.value.length) { levels.value = [{ selectedId: null, children: roots.value }]; } }, { immediate: true, deep: true });

const employmentTypeOptions = [
    { text: t('profile_edit_tabs.permanent'), value: 1 },
    { text: t('profile_edit_tabs.temporary'), value: 2 },
];
const occupationalLevelOptions = [
    { text: t('profile_edit_tabs.senior_management'), value: 1 },
    { text: t('profile_edit_tabs.middle_management'), value: 2 },
    { text: t('profile_edit_tabs.junior_management'), value: 3 },
    { text: t('profile_edit_tabs.semi_skilled'), value: 4 },
    { text: t('profile_edit_tabs.unskilled'), value: 5 },
    { text: t('profile_edit_tabs.professional'), value: 6 },
    { text: t('profile_edit_tabs.skilled'), value: 7 },
];
const jobFunctionOptions = [
    { text: t('profile_edit_tabs.core_operational'), value: 1 },
    { text: t('profile_edit_tabs.support'), value: 2 },
];
const yesNoOptions = [
    { text: t('profile_edit_tabs.yes'), value: 1 },
    { text: t('profile_edit_tabs.no'), value: 2 },
];

const formattedManagers = computed(() => (props.managers ?? []).map((m) => ({ title: `${m.person?.first_name ?? ""} ${m.person?.surname ?? ""}`.trim(), value: m.id })));
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="section-label">{{ t('profile_edit_tabs.employment_information') }}</p>
            <div class="grid grid-cols-1 gap-4">
                <VTextField v-model="form.employee_number" :label="t('profile_edit_tabs.employee_number')" variant="outlined" density="compact" />
                <VTextField v-model="form.date_joined" :label="t('profile_edit_tabs.date_joined')" type="date" variant="outlined" density="compact" :max="form.date_left || undefined" />
                <VTextField v-model="form.date_left" :label="t('profile_edit_tabs.date_left')" type="date" variant="outlined" density="compact" :min="form.date_joined || today" />
                <div class="grid grid-cols-2 gap-4">
                    <VTextField v-model="form.start_time" :label="t('profile_edit_tabs.start_time')" type="time" variant="outlined" density="compact" />
                    <VTextField v-model="form.end_time" :label="t('profile_edit_tabs.end_time')" type="time" variant="outlined" density="compact" />
                </div>
                <VSelect v-model="form.employment_type" :items="employmentTypeOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.employment_type')" variant="outlined" density="compact" />
                <VSelect v-model="form.occupational_level" :items="occupationalLevelOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.occupational_level')" variant="outlined" density="compact" />
                <VSelect v-model="form.job_function" :items="jobFunctionOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.job_function')" variant="outlined" density="compact" />
                <VSelect v-model="form.disabled" :items="yesNoOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.disabled')" variant="outlined" density="compact" />
                <VTextField v-if="form.disabled == 1" v-model="form.nature_of_disability" :label="t('profile_edit_tabs.nature_of_disability')" variant="outlined" density="compact" />
                <VSelect v-if="canEditDirectly" v-model="form.position_id" :items="positions" item-title="name" item-value="id" :label="t('profile_edit_tabs.position')" variant="outlined" density="compact" clearable />
                <VSelect v-model="form.leave_profile" :items="leaves" item-title="name" item-value="id" :label="t('profile_edit_tabs.leave_profile')" variant="outlined" density="compact" />
            </div>
        </div>

        <div class="space-y-5">
            <div v-if="canEditDirectly" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                <p class="section-label">{{ t('profile_edit_tabs.organisation_units') }}</p>
                <template v-if="levels.length > 0">
                    <div v-for="(level, index) in levels" :key="index" class="mb-3">
                        <div class="d-flex align-center gap-2 mb-1">
                            <i v-if="index > 0" class="fa-solid fa-arrow-turn-down-right" style="font-size: 10px; color: #94a3b8" />
                            <span class="level-badge" :style="{ background: getTypeConfig(level.children[0]?.type).bg, color: getTypeConfig(level.children[0]?.type).color }">{{ getTypeConfig(level.children[0]?.type).label }}</span>
                        </div>
                        <VSelect :model-value="level.selectedId" :items="level.children.map(u => ({ id: u.id, name: u.name, type: u.type, title: `${u.name} (${getTypeConfig(u.type).label})` }))" item-title="title" item-value="id" :label="`${t('profile_edit_tabs.select_division').split(' ')[0]} ${getTypeConfig(level.children[0]?.type).label}`" :prepend-inner-icon="getTypeConfig(level.children[0]?.type).icon" variant="outlined" density="compact" clearable @update:model-value="onLevelSelect(index, $event)" />
                    </div>
                </template>
                <p v-else class="text-caption text-medium-emphasis text-center py-2">{{ t('profile_edit_tabs.no_units') }}</p>
            </div>

            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                <p class="section-label">{{ t('profile_edit_tabs.report_to') }}</p>
                <div class="grid grid-cols-1 gap-4">
                    <VSelect v-model="form.manager_id" :items="formattedManagers" item-title="title" item-value="value" :label="t('profile_edit_tabs.manager')" variant="outlined" density="compact" clearable />
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                <p class="section-label">{{ t('profile_edit_tabs.driving') }}</p>
                <div class="grid grid-cols-1 gap-4">
                    <VSelect v-model="form.can_drive" :items="yesNoOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.eligible_to_drive')" variant="outlined" density="compact" />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
.level-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: capitalize; letter-spacing: 0.3px; }
</style>
