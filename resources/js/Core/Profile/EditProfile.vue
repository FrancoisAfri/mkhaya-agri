<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useI18n } from 'vue-i18n'
import { useProfileStore } from "./Store/profileStore";
import type { ProfileUser, EmployeeDocumentData } from "./Type/type";

import PersonalInfoTab from "./Tabs/Edit/Personalinfotab.vue";
import ContactDetailsTab from "./Tabs/Edit/Contactdetailstab.vue";
import ResidentialTab from "./Tabs/Edit/Residentialtab.vue";
import EmergencyContactTab from "./Tabs/Edit/Emergencycontacttab.vue";
import WorkDetailsTab from "./Tabs/Edit/Workdetailstab.vue";
import DrivingTab from "./Tabs/Edit/Drivingtab.vue";
import TaxBankingTab from "./Tabs/Edit/Taxbankingtab.vue";
import MedicalAidTab from "./Tabs/Edit/Medicalaidtab.vue";
import ProvidentFundTab from "./Tabs/Edit/Providentfundtab.vue";
import PackageInfoTab from "./Tabs/Edit/Packageinfotab.vue";
import DocumentsTab from "./Tabs/Edit/Documentstab.vue";
import SettingsTab from "./Tabs/Edit/Settingstab.vue";

const { t } = useI18n()

interface CompanyUnitFromServer { id: number; name: string; parent_id: number | null; type: string; }

interface Props {
    user: ProfileUser | null;
    managers: { id: number; email: string; person: { first_name: string; surname: string } }[];
    roles: { id: number; name: string }[];
    positions: { id: number; name: string }[];
    provinces: { id: number; name: string }[];
    countries: { id: number; name: string }[];
    leaves: { id: number; name: string }[];
    documents: EmployeeDocumentData[];
    companyUnits: { divisions: CompanyUnitFromServer[]; departments: CompanyUnitFromServer[]; teams: CompanyUnitFromServer[]; sections: CompanyUnitFromServer[]; units: CompanyUnitFromServer[] };
    selectedCompanyIds: number[];
    canEditDirectly: boolean;
    isOwnProfile: boolean;
}

const props = defineProps<Props>();

const store = useProfileStore();
watch(() => props.user, (val) => store.setUser(val), { immediate: true });

const timestampToDateInput = (value: number | string | null | undefined): string => {
    if (!value && value !== 0) return "";
    const strValue = String(value);
    if (strValue.includes("T")) return strValue.split("T")[0];
    if (/^\d{4}-\d{2}-\d{2}$/.test(strValue)) return strValue;
    const ts = Number(strValue);
    if (!isFinite(ts) || ts <= 0) return "";
    const ms = ts > 1000000000000 ? ts : ts * 1000;
    const date = new Date(ms);
    if (isNaN(date.getTime())) return "";
    const year = date.getFullYear();
    if (year < 1900 || year > 2100) return "";
    return date.toISOString().split("T")[0];
};

const p = props.user?.person;

const form = useForm({
    email: props.user?.email ?? "", status: props.user?.status ?? 1, role: props.user?.roles?.[0]?.name ?? "",
    first_name: p?.first_name ?? "", surname: p?.surname ?? "", middle_name: p?.middle_name ?? "",
    maiden_name: p?.maiden_name ?? "", aka: p?.aka ?? "", initial: p?.initial ?? "", known_as: p?.known_as ?? "",
    title: p?.title ?? "", gender: p?.gender ?? 1, date_of_birth: timestampToDateInput(p?.date_of_birth),
    id_number: p?.id_number ?? "", passport_number: p?.passport_number ?? "", marital_status: p?.marital_status ?? "",
    ethnicity: p?.ethnicity ?? "", citizenship: p?.citizenship ?? "", cell_number: p?.cell_number ?? "",
    phone_number: p?.phone_number ?? "", alternate_number: p?.alternate_number ?? "", email_person: p?.email ?? "",
    res_address: p?.res_address ?? "", res_suburb: p?.res_suburb ?? "", res_city: p?.res_city ?? "",
    res_postal_code: p?.res_postal_code ?? "", res_province_id: p?.res_province_id ?? "", res_country_id: p?.res_country_id ?? "",
    next_of_kin: p?.next_of_kin ?? "", next_of_kin_number: p?.next_of_kin_number ?? "", next_of_kin_work_number: p?.next_of_kin_work_number ?? "",
    income_tax_number: p?.income_tax_number ?? "", tax_office: p?.tax_office ?? "", account_type: p?.account_type ?? "",
    account_holder_name: p?.account_holder_name ?? "", bank_name: p?.bank_name ?? "", branch_name: p?.branch_name ?? "",
    account_number: p?.account_number ?? "", med_start_date: timestampToDateInput(p?.med_start_date ?? ""),
    med_split: p?.med_split ?? "", med_plan_name: p?.med_plan_name ?? "", med_dep_spouse: p?.med_dep_spouse ?? "",
    med_dep_adult: p?.med_dep_adult ?? "", med_dep_kids: p?.med_dep_kids ?? "", med_amount: p?.med_amount ?? "",
    package_med_aid: p?.package_med_aid ?? "", provident_start_date: timestampToDateInput(p?.provident_start_date),
    provident_name: p?.provident_name ?? "", provident_amount: p?.provident_amount ?? "", provident_fund_product: p?.provident_fund_product ?? "",
    employee_number: p?.employee_number ?? "", position_id: p?.position_id ?? (null as number | null),
    date_joined: timestampToDateInput(p?.date_joined), date_left: timestampToDateInput(p?.date_left),
    start_time: p?.start_time ?? "", end_time: p?.end_time ?? "", leave_profile: p?.leave_profile ?? "",
    role_id: p?.role_id ?? "", manager_id: p?.manager_id ?? "", employment_type: p?.employment_type ?? "",
    occupational_level: p?.occupational_level ?? "", job_function: p?.job_function ?? "", disabled: p?.disabled ?? "",
    nature_of_disability: p?.nature_of_disability ?? "", can_drive: p?.can_drive ?? "",
    drivers_licence_number: p?.drivers_licence_number ?? "", drivers_licence_code: p?.drivers_licence_code ?? "",
    drivers_licence_exp_date: timestampToDateInput(p?.drivers_licence_exp_date || null),
    proof_drive_permit: p?.proof_drive_permit || null, proof_drive_permit_exp_date: timestampToDateInput(p?.proof_drive_permit_exp_date || null),
    own_transport: p?.own_transport ?? "", package_components: p?.package_components ?? "", monthly_package: p?.monthly_package ?? "",
    package_cash: p?.package_cash ?? "", package_travel: p?.package_travel ?? "", package_incentive: p?.package_incentive ?? "",
    package_cell_phone: p?.package_cell_phone ?? "", complex_name: p?.complex_name ?? "", unit_number: p?.unit_number ?? "",
    password: "", profile_pic: null as File | null, company_ids: props.selectedCompanyIds ?? ([] as number[]),
});

const avatarPreview = ref<string | null>(store.avatarUrl);
const dropInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);
const MAX_SIZE_MB = 2;

const onAvatarFile = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    if (file.size / 1024 / 1024 > MAX_SIZE_MB) { store.showSnack(t('profile.edit.snack_image_size', { size: MAX_SIZE_MB }), "error"); return; }
    const allowed = ["image/jpeg", "image/png", "image/webp", "image/gif"];
    if (!allowed.includes(file.type)) { store.showSnack(t('profile.edit.snack_image_type'), "error"); return; }
    form.profile_pic = file; avatarPreview.value = URL.createObjectURL(file);
};

const onDrop = (e: DragEvent) => { dragOver.value = false; const file = e.dataTransfer?.files?.[0]; if (!file) return; form.profile_pic = file; avatarPreview.value = URL.createObjectURL(file); };

const fullName = computed(() => `${form.first_name} ${form.surname}`.trim() || store.fullName);
const pageTitle = computed(() => props.isOwnProfile ? t('profile.edit.page_title_own') : t('profile.edit.page_title_other', { name: fullName.value }));

const pendingAlert = ref(false);
const onSubmit = () => {
    form.post(route('user.updated', props.user?.person?.uuid), {
        preserveScroll: true,
        onSuccess: (page: any) => {
            const flash = page.props.flash as any;
            if (flash?.pending) { pendingAlert.value = true; store.showSnack(t('profile.edit.snack_pending'), "success"); }
            else { store.showSnack(t('profile.edit.snack_updated'), "success"); }
        },
        onError: () => { store.showSnack(t('profile.edit.snack_failed'), "error"); },
    });
};
const goBack = () => window.history.back();

const activeTab = ref("personal-info");
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6 pb-24">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn variant="text" color="primary" prepend-icon="tabler-arrow-left" size="small" @click="goBack">{{ t('profile.edit.back') }}</VBtn>
        <div><h3 class="text-h5 font-weight-bold mb-0">{{ pageTitle }}</h3><p class="text-caption text-medium-emphasis mb-0">{{ store.user?.email }}</p></div>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip v-if="!canEditDirectly" color="warning" size="small" variant="tonal" prepend-icon="tabler-clock">{{ t('profile.edit.pending_approval') }}</VChip>
        <VChip v-else color="success" size="small" variant="tonal" prepend-icon="tabler-circle-check">{{ t('profile.edit.direct_edit') }}</VChip>
      </div>
    </div>

    <VAlert v-if="!canEditDirectly" type="warning" variant="tonal" rounded="lg" class="mb-4" closable>{{ t('profile.edit.alert_pending') }}</VAlert>
    <VAlert v-if="!isOwnProfile" type="info" variant="tonal" rounded="lg" class="mb-4">{{ t('profile.edit.alert_admin', { name: fullName }) }}</VAlert>
    <VAlert v-if="pendingAlert" type="success" variant="tonal" rounded="lg" class="mb-4" closable @click:close="pendingAlert = false">{{ t('profile.edit.alert_submitted') }}</VAlert>

    <VCard elevation="0" border rounded="lg">
      <div class="d-flex align-center gap-4 px-6 py-5" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="avatar-upload rounded-lg flex-shrink-0" :class="{ 'drag-active': dragOver }" @click="dropInput?.click()" @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
          <img v-if="avatarPreview" :src="avatarPreview" class="avatar-img" alt="Avatar" />
          <span v-else class="avatar-initials">{{ store.initials }}</span>
          <div class="avatar-overlay"><VIcon icon="tabler-camera" color="white" size="20" /></div>
        </div>
        <input ref="dropInput" type="file" accept="image/*" class="d-none" @change="onAvatarFile" />
        <div><p class="text-body-1 font-weight-semibold mb-0">{{ fullName || store.user?.email }}</p><p class="text-caption text-medium-emphasis mb-0">{{ store.user?.email }}</p></div>
      </div>

      <VTabs v-model="activeTab" color="primary" density="compact" class="px-4 pt-2">
        <VTab value="personal-info"><VIcon icon="tabler-id-badge" size="16" class="me-1" />{{ t('profile.edit.tabs.personal') }}</VTab>
        <VTab value="contact-details"><VIcon icon="tabler-phone" size="16" class="me-1" />{{ t('profile.edit.tabs.contact') }}</VTab>
        <VTab value="residential"><VIcon icon="tabler-home" size="16" class="me-1" />{{ t('profile.edit.tabs.address') }}</VTab>
        <VTab value="emergency-contact"><VIcon icon="tabler-ambulance" size="16" class="me-1" />{{ t('profile.edit.tabs.emergency') }}</VTab>
        <VTab value="work-details"><VIcon icon="tabler-briefcase" size="16" class="me-1" />{{ t('profile.edit.tabs.work') }}</VTab>
        <VTab value="driving"><VIcon icon="tabler-car" size="16" class="me-1" />{{ t('profile.edit.tabs.driving') }}</VTab>
        <VTab value="tax-banking"><VIcon icon="tabler-building-bank" size="16" class="me-1" />{{ t('profile.edit.tabs.tax_banking') }}</VTab>
        <VTab value="medical-aid"><VIcon icon="tabler-stethoscope" size="16" class="me-1" />{{ t('profile.edit.tabs.medical') }}</VTab>
        <VTab value="provident-fund"><VIcon icon="tabler-pig-money" size="16" class="me-1" />{{ t('profile.edit.tabs.provident') }}</VTab>
        <VTab value="package-info"><VIcon icon="tabler-cash" size="16" class="me-1" />{{ t('profile.edit.tabs.package') }}</VTab>
        <VTab value="documents"><VIcon icon="tabler-file-text" size="16" class="me-1" />{{ t('profile.edit.tabs.documents') }}</VTab>
        <VTab value="settings"><VIcon icon="tabler-settings" size="16" class="me-1" />{{ t('profile.edit.tabs.settings') }}</VTab>
      </VTabs>

      <VDivider />
      <VCardText class="pa-6">
        <PersonalInfoTab v-if="activeTab === 'personal-info'" :form="form" :roles="roles" :can-edit-directly="canEditDirectly" :avatar-preview="avatarPreview" :drag-over="dragOver" @avatar-file="onAvatarFile" @drop="onDrop" @drag-over="(val) => (dragOver = val)" @click-upload="dropInput?.click()" />
        <ContactDetailsTab v-if="activeTab === 'contact-details'" :form="form" />
        <ResidentialTab v-if="activeTab === 'residential'" :form="form" :provinces="provinces" :countries="countries" />
        <EmergencyContactTab v-if="activeTab === 'emergency-contact'" :form="form" />
        <WorkDetailsTab v-if="activeTab === 'work-details'" :form="form" :managers="managers" :positions="positions" :leaves="leaves" :can-edit-directly="canEditDirectly" :company-units="companyUnits" :selected-company-ids="props.selectedCompanyIds" @update:company-ids="form.company_ids = $event" />
        <DrivingTab v-if="activeTab === 'driving'" :form="form" />
        <TaxBankingTab v-if="activeTab === 'tax-banking'" :form="form" />
        <MedicalAidTab v-if="activeTab === 'medical-aid'" :form="form" />
        <ProvidentFundTab v-if="activeTab === 'provident-fund'" :form="form" />
        <PackageInfoTab v-if="activeTab === 'package-info'" :form="form" />
        <DocumentsTab v-if="activeTab === 'documents'" :person-id="props.user?.person?.id ?? 0" :can-edit-directly="canEditDirectly" :documents="documents" />
        <SettingsTab v-if="activeTab === 'settings'" :form="form" />
      </VCardText>
    </VCard>

    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2"><VIcon :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="16" />{{ store.snackMsg }}</div>
    </VSnackbar>
  </VContainer>

  <div v-if="activeTab !== 'documents'" class="save-footer">
    <div class="save-footer-inner">
      <span class="text-caption text-medium-emphasis">{{ canEditDirectly ? t('profile.edit.footer.direct_save') : t('profile.edit.footer.approval_required') }}</span>
      <div class="d-flex gap-3">
        <VBtn variant="tonal" rounded="lg" :disabled="form.processing" @click="goBack">{{ t('profile.edit.footer.cancel') }}</VBtn>
        <VBtn :color="canEditDirectly ? 'primary' : 'warning'" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('profile.edit.footer.saving') }}</span></template>
          <VIcon :icon="canEditDirectly ? 'tabler-device-floppy' : 'tabler-send'" size="16" class="me-2" />
          {{ canEditDirectly ? t('profile.edit.footer.save_changes') : t('profile.edit.footer.submit_approval') }}
        </VBtn>
      </div>
    </div>
  </div>
</template>

<style scoped>
.avatar-upload { position: relative; width: 72px; height: 72px; background: rgba(var(--v-theme-primary), 0.1); cursor: pointer; overflow: hidden; border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity)); transition: border-color .15s; }
.avatar-upload:hover { border-color: rgb(var(--v-theme-primary)); }
.avatar-upload.drag-active { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.18); }
.avatar-img { width: 100%; height: 100%; object-fit: cover; }
.avatar-initials { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: rgb(var(--v-theme-primary)); }
.avatar-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; }
.avatar-upload:hover .avatar-overlay { opacity: 1; }
.save-footer { position: fixed; bottom: 0; left: 0; right: 0; z-index: 100; background: rgb(var(--v-theme-surface)); border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); box-shadow: 0 -4px 16px rgba(0,0,0,.08); }
.save-footer-inner { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 12px 24px; }
</style>
