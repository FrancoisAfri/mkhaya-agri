<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any }>();
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.package_information') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <VTextField v-model="form.package_components" :label="t('profile_edit_tabs.components')" variant="outlined" density="compact" />
            <VTextField v-model="form.monthly_package" :label="t('profile_edit_tabs.monthly_package')" type="number" variant="outlined" density="compact" />
            <VTextField v-model="form.package_cash" :label="t('profile_edit_tabs.package_cash')" type="number" variant="outlined" density="compact" />
            <VTextField v-model="form.package_travel" :label="t('profile_edit_tabs.package_travel')" type="number" variant="outlined" density="compact" />
            <VTextField v-model="form.package_incentive" :label="t('profile_edit_tabs.package_incentive')" type="number" variant="outlined" density="compact" />
            <VTextField v-model="form.package_cell_phone" :label="t('profile_edit_tabs.package_cell_phone')" type="number" variant="outlined" density="compact" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
