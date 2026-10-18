<script setup lang="ts">
import { watch, computed } from "vue";
import { useI18n } from 'vue-i18n'
import { useProfileStore } from "./Store/profileStore";
import { type ProfileUser } from "./Type/type";
import PersonalInfoTab from "./Tabs/PersonalInfoTab.vue";
import ContactDetailsTab from "./Tabs/ContactDetailsTab.vue";
import EmergencyContactTab from "./Tabs/EmergencyContactTab.vue";
import TaxBankingTab from "./Tabs/TaxBankingTab.vue";
import MedicalAidTab from "./Tabs/MedicalAidTab.vue";
import ProvidentFundTab from "./Tabs/ProvidentFundTab.vue";
import PackageInfoTab from "./Tabs/PackageInfoTab.vue";
import DrivingTab from "./Tabs/DrivingDetailsTabs.vue";
import DocumentsTab from "./Tabs/Documentstab.vue";

const { t } = useI18n()

interface PositionData { id: number; name: string }
interface CompanyUnitData { id: number; name: string; type?: string; parent_id?: number | null }
interface LeaveProfileData { id: number; name: string }

const props = defineProps<{
  user: ProfileUser | null;
  position?: PositionData | null;
  companyUnits?: CompanyUnitData[];
  leaveProfiles?: LeaveProfileData[];
  canEditDirectly?: boolean;
}>();

const store = useProfileStore();

watch(() => props.user, (val) => { store.setUser(val) }, { immediate: true });

const displayPositionName = computed(() =>
  props.position?.name ?? store.positionName
);

const displayLeaveProfileName = computed(() => {
  if (props.leaveProfiles?.length) return props.leaveProfiles.map(lp => lp.name).join(", ");
  return store.leaveProfileLabel;
});

const displayCompanyUnitsFormatted = computed(() => {
  const units = props.companyUnits || store.user?.company_units;
  if (!units?.length) return "—";
  return units.map(u => `${u.name}${u.type ? ` (${u.type})` : ""}`).join(", ");
});

const isDataLoaded = computed(() => !!store.user);

const tabs = [
  { key: "profile",           label: t('profile.view.tabs.overview'),       icon: "tabler-user" },
  { key: "personal-info",     label: t('profile.view.tabs.personal'),       icon: "tabler-id-badge" },
  { key: "contact-details",   label: t('profile.view.tabs.contact'),        icon: "tabler-phone" },
  { key: "emergency-contact", label: t('profile.view.tabs.emergency'),      icon: "tabler-ambulance" },
  { key: "work-details",      label: t('profile.view.tabs.work'),           icon: "tabler-briefcase" },
  { key: "tax-banking",       label: t('profile.view.tabs.tax_banking'),    icon: "tabler-building-bank" },
  { key: "medical-aid",       label: t('profile.view.tabs.medical_aid'),    icon: "tabler-stethoscope" },
  { key: "provident-fund",    label: t('profile.view.tabs.provident_fund'), icon: "tabler-pig-money" },
  { key: "package-info",      label: t('profile.view.tabs.package'),        icon: "tabler-package" },
  { key: "driving",           label: t('profile.view.tabs.driving'),        icon: "tabler-car" },
] as const;

const sidebarDetails = computed(() => {
  const p = store.user?.person;
  return [
    { icon: "tabler-user",   label: t('profile.view.sidebar.username'),   value: store.user?.name || "—" },
    { icon: "tabler-mail",     label: t('profile.view.sidebar.email'),      value: p?.email || store.user?.email || "—" },
    { icon: "tabler-shield-lock",    label: t('profile.view.sidebar.role'),       value: store.roleLabel },
    { icon: "tabler-briefcase", label: t('profile.view.sidebar.position'),   value: displayPositionName.value },
    { icon: "tabler-phone",     label: t('profile.view.sidebar.cell'),       value: p?.cell_number || "—" },
    { icon: "tabler-building",   label: t('profile.view.sidebar.department'), value: displayCompanyUnitsFormatted.value },
    { icon: "tabler-calendar",  label: t('profile.view.sidebar.joined'),     value: store.joinedDate },
  ];
});

const activityItems = computed(() => [
  {
    color: "primary",
    icon: "tabler-user-plus",
    title: t('profile.view.overview.account_created'),
    desc: t('profile.view.overview.account_created_desc'),
    time: store.joinedDate,
  },
]);
</script>

<template>
  <div class="pa-4">
    <!-- Loading state -->
    <div v-if="!isDataLoaded" class="d-flex justify-center align-center" style="min-height: 400px;">
      <div class="text-center">
        <VProgressCircular indeterminate color="primary" size="48" class="mb-4" />
        <p class="text-medium-emphasis">{{ t('profile.view.loading') }}</p>
      </div>
    </div>

    <!-- Two-column layout -->
    <VRow v-else>
      <!-- ══ LEFT SIDEBAR ══ -->
      <VCol cols="12" md="3">
        <VCard>
          <!-- Avatar + name + chips -->
          <VCardText class="text-center pa-6">
            <VAvatar size="96" class="mb-4" color="primary" rounded="lg" style="border: 3px solid rgba(var(--v-theme-primary), 0.2);">
              <VImg v-if="store.avatarUrl" :src="store.avatarUrl" cover />
              <span v-else class="text-h5 font-weight-bold">{{ store.initials }}</span>
            </VAvatar>

            <h6 class="text-h6 font-weight-bold mb-1 text-high-emphasis">{{ store.fullName }}</h6>
            <p class="text-body-2 text-medium-emphasis mb-3">{{ displayPositionName }}</p>

            <div class="d-flex justify-center gap-2 flex-wrap">
              <VChip size="small" color="primary" variant="tonal">{{ store.roleLabel }}</VChip>
              <VChip size="small" :color="store.isActive ? 'success' : 'default'" variant="tonal">{{ store.userStatus }}</VChip>
            </div>
          </VCardText>

          <VDivider />

          <!-- Details list -->
          <VCardText class="pa-4">
            <p class="text-overline text-medium-emphasis mb-2" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.sidebar.details') }}</p>
            <div class="d-flex flex-column" style="gap: 10px;">
              <div v-for="item in sidebarDetails" :key="item.label" class="d-flex align-start gap-3">
                <VIcon :icon="item.icon" size="15" color="secondary" class="mt-0.5 flex-shrink-0" />
                <div class="min-w-0 flex-1 overflow-hidden">
                  <p class="text-caption text-medium-emphasis mb-0 line-height-tight">{{ item.label }}</p>
                  <p class="text-body-2 font-weight-medium text-high-emphasis mb-0" style="word-break: break-word;" :title="item.value">{{ item.value }}</p>
                </div>
              </div>
            </div>
          </VCardText>

          <VDivider />

          <!-- Edit action -->
          <VCardActions class="pa-4">
            <VBtn :href="store.profileEditUrl ?? undefined" :disabled="!store.canEditProfile" color="primary" variant="elevated" block prepend-icon="tabler-pencil">
              {{ t('profile.view.edit_profile') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- ══ RIGHT CONTENT ══ -->
      <VCol cols="12" md="9">
        <VCard>
          <!-- Tab bar -->
          <VTabs v-model="store.activeTab" color="primary" show-arrows density="comfortable">
            <VTab v-for="tab in tabs" :key="tab.key" :value="tab.key" class="text-body-2" style="min-width: auto;">
              <VIcon :icon="tab.icon" size="16" class="me-1" />{{ tab.label }}
            </VTab>
          </VTabs>

          <VDivider />

          <VCardText class="pa-5">
            <!-- ── Overview ── -->
            <div v-if="store.activeTab === 'profile'">
              <VRow>
                <!-- About -->
                <VCol cols="12" md="6">
                  <p class="text-overline text-medium-emphasis mb-3" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.overview.about') }}</p>
                  <div class="d-flex flex-column" style="gap: 12px;">
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-user" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.full_name') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.fullName }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-circle-filled" size="10" :color="store.isActive ? 'success' : 'error'" class="flex-shrink-0 ms-1" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.status') }}</span>
                      <VChip size="x-small" :color="store.isActive ? 'success' : 'error'" variant="tonal">{{ store.userStatus }}</VChip>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-shield-lock" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.role') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.roleLabel }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-map-pin" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.city') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.res_city || '—' }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-briefcase" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.position') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ displayPositionName }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-gender-bigender" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.gender') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.genderLabel }}</span>
                    </div>
                  </div>

                  <VDivider class="my-5" />

                  <p class="text-overline text-medium-emphasis mb-3" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.overview.contacts') }}</p>
                  <div class="d-flex flex-column" style="gap: 12px;">
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-phone" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.cell') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.cell_number || '—' }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-phone-call" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.overview.phone') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.phone_number || '—' }}</span>
                    </div>
                    <div class="d-flex align-center gap-3">
                      <VIcon icon="tabler-mail" size="18" color="secondary" class="flex-shrink-0" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 90px;">{{ t('profile.view.sidebar.email') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.email || store.user?.email || '—' }}</span>
                    </div>
                  </div>
                </VCol>

                <!-- Activity Timeline -->
                <VCol cols="12" md="6">
                  <p class="text-overline text-medium-emphasis mb-3" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.overview.activity_timeline') }}</p>
                  <div class="position-relative" style="padding-left: 28px;">
                    <div class="position-absolute" style="left: 9px; top: 10px; bottom: 0; width: 2px; background: rgba(var(--v-border-color), var(--v-border-opacity));" />
                    <div v-for="(item, i) in activityItems" :key="i" class="d-flex gap-3 mb-5 position-relative">
                      <VAvatar :color="item.color" size="20" class="position-absolute flex-shrink-0" style="left: -28px; top: 1px; z-index: 1;">
                        <VIcon :icon="item.icon" size="11" color="white" />
                      </VAvatar>
                      <div>
                        <p class="text-body-2 font-weight-semibold text-high-emphasis mb-0">{{ item.title }}</p>
                        <p class="text-caption text-medium-emphasis mb-0">{{ item.desc }}</p>
                        <p class="text-caption text-disabled mb-0">{{ item.time }}</p>
                      </div>
                    </div>
                  </div>

                  <VDivider class="mb-5" />
                  <VRow dense>
                    <VCol cols="6">
                      <VCard variant="tonal" color="primary" rounded="lg">
                        <VCardText class="pa-3">
                          <div class="d-flex align-center gap-2">
                            <VIcon icon="tabler-user-pentagon" size="20" color="primary" />
                            <div>
                              <p class="text-caption text-medium-emphasis mb-0">{{ t('profile.view.overview.user_type') }}</p>
                              <p class="text-body-2 font-weight-bold text-high-emphasis mb-0">{{ store.userType }}</p>
                            </div>
                          </div>
                        </VCardText>
                      </VCard>
                    </VCol>
                    <VCol cols="6">
                      <VCard variant="tonal" :color="store.isActive ? 'success' : 'default'" rounded="lg">
                        <VCardText class="pa-3">
                          <div class="d-flex align-center gap-2">
                            <VIcon icon="tabler-circle-check" size="20" :color="store.isActive ? 'success' : 'default'" />
                            <div>
                              <p class="text-caption text-medium-emphasis mb-0">{{ t('profile.view.overview.status') }}</p>
                              <p class="text-body-2 font-weight-bold text-high-emphasis mb-0">{{ store.userStatus }}</p>
                            </div>
                          </div>
                        </VCardText>
                      </VCard>
                    </VCol>
                  </VRow>
                </VCol>
              </VRow>
            </div>

            <!-- ── Personal Info ── -->
            <PersonalInfoTab v-else-if="store.activeTab === 'personal-info'" />

            <!-- ── Contact Details ── -->
            <ContactDetailsTab v-else-if="store.activeTab === 'contact-details'" />

            <!-- ── Emergency Contact ── -->
            <EmergencyContactTab v-else-if="store.activeTab === 'emergency-contact'" />

            <!-- ── Work Details ── -->
            <div v-else-if="store.activeTab === 'work-details'">
              <VRow>
                <VCol cols="12" md="6">
                  <p class="text-overline text-medium-emphasis mb-3" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.work.employment_info') }}</p>
                  <div class="d-flex flex-column" style="gap: 12px;">
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-id-badge" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.employee_number') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.employee_number || '—' }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-briefcase" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.job_function') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.jobFunctionLabel }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-building" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.position_title') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ displayPositionName }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-chart-line" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.occupational_level') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.occupationalLevelLabel }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-clock" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.employment_type') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.employmentTypeLabel }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-calendar-check" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.date_joined') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.joinedDate }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-calendar-x" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.date_finished') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.finishedDate }}</span>
                    </div>
                  </div>
                </VCol>

                <VCol cols="12" md="6">
                  <p class="text-overline text-medium-emphasis mb-3" style="font-size: 10px; letter-spacing: 1.5px;">{{ t('profile.view.work.organisation_benefits') }}</p>
                  <div class="d-flex flex-column" style="gap: 12px;">
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-user-pentagon" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.manager') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.managerName }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-sitemap" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.company_units') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ displayCompanyUnitsFormatted }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-beach" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.leave_profile') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ displayLeaveProfileName }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-accessible" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.disabled') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.disabledLabel }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-heartbeat" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.nature_of_disability') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">{{ store.user?.person?.nature_of_disability || '—' }}</span>
                    </div>
                    <div class="d-flex align-start gap-3">
                      <VIcon icon="tabler-clock-4" size="18" color="secondary" class="flex-shrink-0 mt-0.5" />
                      <span class="text-body-2 text-medium-emphasis" style="min-width: 140px; flex-shrink: 0;">{{ t('profile.view.work.working_hours') }}</span>
                      <span class="text-body-2 font-weight-medium text-high-emphasis">
                        {{ (store.user?.person?.start_time && store.user?.person?.end_time) ? `${store.user?.person?.start_time} – ${store.user?.person?.end_time}` : '—' }}
                      </span>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- ── Tax & Banking ── -->
            <TaxBankingTab v-else-if="store.activeTab === 'tax-banking'" />

            <!-- ── Medical Aid ── -->
            <MedicalAidTab v-else-if="store.activeTab === 'medical-aid'" />

            <!-- ── Provident Fund ── -->
            <ProvidentFundTab v-else-if="store.activeTab === 'provident-fund'" />

            <!-- ── Package Info ── -->
            <PackageInfoTab v-else-if="store.activeTab === 'package-info'" />

            <!-- ── Driving ── -->
            <DrivingTab v-else-if="store.activeTab === 'driving'" />

            <!-- ── Documents ── -->
            <DocumentsTab v-else-if="store.activeTab === 'documents'" :person-id="store.user?.person?.id ?? 0" :can-edit-directly="props.canEditDirectly ?? false" :documents="store.user?.person?.documents ?? []" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Snackbar -->
    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2">
        <VIcon :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="16" />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>
  </div>
</template>
