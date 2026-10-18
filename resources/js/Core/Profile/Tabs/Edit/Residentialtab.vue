<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any; provinces: { id: number; name: string }[]; countries: { id: number; name: string }[] }>();
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.residential_address') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <VTextarea v-model="form.res_address" :error-messages="form.errors.res_address" :label="t('profile_edit_tabs.address')" :placeholder="t('profile_edit_tabs.address_placeholder')" variant="outlined" density="compact" rows="2" class="sm:col-span-2" />
            <VTextField v-model="form.res_suburb" :label="t('profile_edit_tabs.suburb')" variant="outlined" density="compact" />
            <VTextField v-model="form.res_city" :label="t('profile_edit_tabs.city')" variant="outlined" density="compact" />
            <VTextField v-model="form.res_postal_code" :label="t('profile_edit_tabs.postal_code')" variant="outlined" density="compact" />
            <VSelect v-model="form.res_province_id" :label="t('profile_edit_tabs.province')" :items="provinces" item-title="name" item-value="id" variant="outlined" density="compact" />
            <VSelect v-model="form.res_country_id" :label="t('profile_edit_tabs.country')" :items="countries" item-title="name" item-value="id" variant="outlined" density="compact" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
