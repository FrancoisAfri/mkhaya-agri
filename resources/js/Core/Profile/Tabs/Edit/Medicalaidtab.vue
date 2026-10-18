<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any }>();
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.medical_aid_details') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <VTextField v-model="form.med_start_date" :label="t('profile_edit_tabs.start_date')" type="date" variant="outlined" density="compact" />
            <VTextField v-model="form.med_split" :label="t('profile_edit_tabs.split')" variant="outlined" density="compact" />
            <VTextField v-model="form.med_plan_name" :label="t('profile_edit_tabs.plan_name')" variant="outlined" density="compact" />
            <VTextField v-model="form.med_dep_spouse" :label="t('profile_edit_tabs.dependants_spouse')" variant="outlined" density="compact" />
            <VTextField v-model="form.med_dep_adult" :label="t('profile_edit_tabs.dependants_adult')" variant="outlined" density="compact" />
            <VTextField v-model="form.med_dep_kids" :label="t('profile_edit_tabs.dependants_children')" variant="outlined" density="compact" />
            <VTextField v-model="form.med_amount" :label="t('profile_edit_tabs.amount')" type="number" variant="outlined" density="compact" />
            <VTextField v-model="form.package_med_aid" :label="t('profile_edit_tabs.package_med_aid')" type="number" variant="outlined" density="compact" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
