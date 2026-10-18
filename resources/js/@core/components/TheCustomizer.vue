<script setup lang="ts">
import { useStorage } from '@vueuse/core'
import { useForm, usePage } from '@inertiajs/vue3'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { useTheme } from 'vuetify'
import { darkenHex, staticPrimaryColor, staticPrimaryDarkenColor } from '@/plugins/vuetify/theme'
import { Direction, Layout, Skins, Theme } from '@core/enums'
import { useConfigStore } from '@core/stores/config'
import { AppContentLayoutNav, ContentWidth } from '@layouts/enums'
import { cookieRef, namespaceConfig } from '@layouts/stores/config'
import { themeConfig } from '@themeConfig'

import borderSkin from '@images/customizer-icons/border-light.svg'
import collapsed from '@images/customizer-icons/collapsed-light.svg'
import compact from '@images/customizer-icons/compact-light.svg'
import defaultSkin from '@images/customizer-icons/default-light.svg'
import ltrSvg from '@images/customizer-icons/ltr-light.svg'
import rtlSvg from '@images/customizer-icons/rtl-light.svg'
import wideSvg from '@images/customizer-icons/wide-light.svg'
import horizontalLight from '@images/customizer-icons/horizontal-light.svg'

const isNavDrawerOpen = ref(false)
const configStore = useConfigStore()
const vuetifyTheme = useTheme()
const page = usePage()

// ── Save form ───────────────────────────────────────────────────────────────
const saveForm = useForm({
  theme: '',
  primaryColor: '',
  skin: '',
  contentWidth: '',
  contentLayoutNav: '',
  isVerticalNavCollapsed: false,
  isVerticalNavSemiDark: false,
  navbarBlur: true,
})

const saveSuccess = ref(false)

function saveToDb() {
  saveForm.theme = configStore.theme as string
  saveForm.primaryColor = vuetifyTheme.current.value.colors.primary
  saveForm.skin = configStore.skin as string
  saveForm.contentWidth = configStore.appContentWidth as string
  saveForm.contentLayoutNav = configStore.appContentLayoutNav as string
  saveForm.isVerticalNavCollapsed = configStore.isVerticalNavCollapsed
  saveForm.isVerticalNavSemiDark = configStore.isVerticalNavSemiDark
  saveForm.navbarBlur = configStore.isNavbarBlurEnabled

  saveForm.patch(route('settings.theme.update'), {
    preserveScroll: true,
    onSuccess: () => {
      saveSuccess.value = true
      setTimeout(() => { saveSuccess.value = false }, 3000)
    },
  })
}

// ── Primary Color ───────────────────────────────────────────────────────────
const colors: { main: string; darken: string }[] = [
  { main: staticPrimaryColor,  darken: staticPrimaryDarkenColor },
  { main: '#0D9394', darken: '#0C8485' },
  { main: '#FFB400', darken: '#E6A200' },
  { main: '#FF4C51', darken: '#E64449' },
  { main: '#16B1FF', darken: '#149FE6' },
]

const customPrimaryColor = ref('#663131')

watch(
  () => configStore.theme,
  () => {
    const saved = cookieRef(`${vuetifyTheme.name.value}ThemePrimaryColor`, null).value
    if (saved && !colors.some(c => c.main === saved))
      customPrimaryColor.value = saved
  },
  { immediate: true },
)

const setPrimaryColor = useDebounceFn((color: { main: string; darken: string }) => {
  vuetifyTheme.themes.value[vuetifyTheme.name.value].colors.primary = color.main
  vuetifyTheme.themes.value[vuetifyTheme.name.value].colors['primary-darken-1'] = color.darken
  cookieRef<string | null>(`${vuetifyTheme.name.value}ThemePrimaryColor`, null).value = color.main
  cookieRef<string | null>(`${vuetifyTheme.name.value}ThemePrimaryDarkenColor`, null).value = color.darken
  useStorage<string | null>(namespaceConfig('initial-loader-color'), null).value = color.main
}, 100)

// ── Theme mode ──────────────────────────────────────────────────────────────
const themeMode = computed(() => [
  { icon: 'tabler-sun',  value: Theme.Light,  label: 'Light' },
  { icon: 'tabler-moon',  value: Theme.Dark,   label: 'Dark' },
  { icon: 'tabler-device-desktop',        value: Theme.System, label: 'System' },
])

// ── Skin ────────────────────────────────────────────────────────────────────
const themeSkin = computed(() => [
  { bgImage: defaultSkin, value: Skins.Default,  label: 'Default' },
  { bgImage: borderSkin,  value: Skins.Bordered, label: 'Bordered' },
])

// ── Layout ──────────────────────────────────────────────────────────────────
const currentLayout = ref<'vertical' | 'collapsed' | 'horizontal'>(
  configStore.isVerticalNavCollapsed ? 'collapsed' : configStore.appContentLayoutNav,
)

const layouts = computed(() => [
  { bgImage: defaultSkin,    value: Layout.Vertical,   label: 'Vertical' },
  { bgImage: collapsed,      value: Layout.Collapsed,  label: 'Collapsed' },
  { bgImage: horizontalLight, value: Layout.Horizontal, label: 'Horizontal' },
])

watch(currentLayout, () => {
  if (currentLayout.value === 'collapsed') {
    configStore.isVerticalNavCollapsed = true
    configStore.appContentLayoutNav = AppContentLayoutNav.Vertical
  } else {
    configStore.isVerticalNavCollapsed = false
    configStore.appContentLayoutNav = currentLayout.value as any
  }
})

watch(() => configStore.isVerticalNavCollapsed, () => {
  currentLayout.value = configStore.isVerticalNavCollapsed
    ? 'collapsed'
    : configStore.appContentLayoutNav as any
})

// ── Content width ───────────────────────────────────────────────────────────
const contentWidth = computed(() => [
  { bgImage: compact, value: ContentWidth.Boxed,  label: 'Compact' },
  { bgImage: wideSvg, value: ContentWidth.Fluid,  label: 'Wide' },
])

// ── Direction ───────────────────────────────────────────────────────────────
const currentDir = ref(configStore.isAppRTL ? 'rtl' : 'ltr')
const direction = computed(() => [
  { bgImage: ltrSvg, value: Direction.Ltr, label: 'Left to right' },
  { bgImage: rtlSvg, value: Direction.Rtl, label: 'Right to left' },
])
watch(currentDir, () => { configStore.isAppRTL = currentDir.value === 'rtl' })

// ── Reset ───────────────────────────────────────────────────────────────────
const isCookieHasAnyValue = ref(false)

watch([
  () => vuetifyTheme.current.value.colors.primary,
  configStore.$state,
], () => {
  const initial = [
    staticPrimaryColor,
    themeConfig.app.theme,
    themeConfig.app.skin,
    themeConfig.verticalNav.isVerticalNavSemiDark,
    themeConfig.verticalNav.isVerticalNavCollapsed,
    themeConfig.app.contentWidth,
    themeConfig.app.contentLayoutNav,
  ]
  const current = [
    vuetifyTheme.current.value.colors.primary,
    configStore.theme,
    configStore.skin,
    configStore.isVerticalNavSemiDark,
    configStore.isVerticalNavCollapsed,
    configStore.appContentWidth,
    configStore.appContentLayoutNav,
  ]
  isCookieHasAnyValue.value = JSON.stringify(current) !== JSON.stringify(initial)
}, { deep: true, immediate: true })

async function resetCustomizer() {
  if (!isCookieHasAnyValue.value) return
  vuetifyTheme.themes.value.light.colors.primary = staticPrimaryColor
  vuetifyTheme.themes.value.dark.colors.primary = staticPrimaryColor
  vuetifyTheme.themes.value.light.colors['primary-darken-1'] = staticPrimaryDarkenColor
  vuetifyTheme.themes.value.dark.colors['primary-darken-1'] = staticPrimaryDarkenColor
  configStore.theme = themeConfig.app.theme as any
  configStore.skin = themeConfig.app.skin as any
  configStore.isVerticalNavSemiDark = themeConfig.verticalNav.isVerticalNavSemiDark
  configStore.appContentLayoutNav = themeConfig.app.contentLayoutNav as any
  configStore.appContentWidth = themeConfig.app.contentWidth as any
  configStore.isVerticalNavCollapsed = themeConfig.verticalNav.isVerticalNavCollapsed
  configStore.isAppRTL = false
  currentLayout.value = themeConfig.app.contentLayoutNav as any
  currentDir.value = 'ltr'
  cookieRef('lightThemePrimaryColor', staticPrimaryColor).value = staticPrimaryColor
  cookieRef('darkThemePrimaryColor', staticPrimaryColor).value = staticPrimaryColor
  useStorage<string | null>(namespaceConfig('initial-loader-color'), null).value = staticPrimaryColor
  await nextTick()
  isCookieHasAnyValue.value = false
}
</script>

<template>
  <!-- Floating toggle button fixed to right edge -->
  <VBtn
    icon
    class="app-customizer-toggler rounded-s-lg rounded-e-0"
    aria-label="Open theme settings"
    elevation="0" border
    size="40"
    style="z-index: 1001; position: fixed; inset-block-start: 50%; inset-inline-end: 0; transform: translateY(-50%);"
    @click="isNavDrawerOpen = true"
  >
    <VIcon size="20" icon="tabler-settings" />
  </VBtn>

  <VNavigationDrawer
    v-model="isNavDrawerOpen"
    data-allow-mismatch
    temporary
    touchless
    border="none"
    location="end"
    width="400"
    elevation="0"
    :scrim="false"
    class="app-customizer"
  >
    <!-- Header -->
    <div class="customizer-heading d-flex align-center justify-space-between">
      <div>
        <h6 class="text-h6">Theme Customizer</h6>
        <p class="text-body-2 mb-0 text-medium-emphasis">Customize &amp; Preview in Real Time</p>
      </div>
      <div class="d-flex align-center gap-1">
        <VBtn icon variant="text" size="small" color="medium-emphasis" aria-label="Reset theme settings" @click="resetCustomizer">
          <VBadge v-show="isCookieHasAnyValue" dot color="error" offset-x="-29" offset-y="-14" />
          <VIcon size="22" icon="tabler-refresh" />
        </VBtn>
        <VBtn icon variant="text" size="small" color="medium-emphasis" aria-label="Close theme settings" @click="isNavDrawerOpen = false">
          <VIcon size="22" icon="tabler-x" />
        </VBtn>
      </div>
    </div>

    <VDivider />

    <PerfectScrollbar tag="div" class="customizer-body" :options="{ wheelPropagation: false }">

      <!-- SECTION: Theming -->
      <CustomizerSection title="Theming" :divider="false">

        <!-- Primary Color -->
        <div class="d-flex flex-column gap-2">
          <h6 class="text-h6">Primary Color</h6>
          <div class="d-flex flex-wrap" style="gap: 0.75rem; margin-block-start: 2px;">
            <div
              v-for="color in colors"
              :key="color.main"
              class="color-swatch cursor-pointer"
              :class="{ active: vuetifyTheme.current.value.colors.primary === color.main }"
              :style="vuetifyTheme.current.value.colors.primary === color.main
                ? `outline-color: ${color.main}; outline-width: 2px;`
                : ''"
              @click="setPrimaryColor(color)"
            >
              <div class="color-swatch-inner" :style="{ backgroundColor: color.main }" />
            </div>

            <!-- Custom color picker -->
            <div
              class="color-swatch cursor-pointer d-flex align-center justify-center"
              :class="{ active: vuetifyTheme.current.value.colors.primary === customPrimaryColor }"
              :style="vuetifyTheme.current.value.colors.primary === customPrimaryColor
                ? `outline-color: ${customPrimaryColor}; outline-width: 2px;`
                : ''"
            >
              <VBtn
                icon size="34"
                :color="vuetifyTheme.current.value.colors.primary === customPrimaryColor ? customPrimaryColor : undefined"
                variant="flat"
                aria-label="Choose a custom primary colour"
                style="border-radius: 0.375rem;"
              >
                <VIcon size="18" icon="tabler-palette" />
              </VBtn>
              <VMenu activator="parent" :close-on-content-click="false">
                <VList>
                  <VListItem>
                    <VColorPicker
                      v-model="customPrimaryColor"
                      mode="hex"
                      :modes="['hex']"
                      @update:model-value="setPrimaryColor({ main: customPrimaryColor, darken: darkenHex(customPrimaryColor) })"
                    />
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </div>
        </div>

        <!-- Mode -->
        <div class="d-flex flex-column gap-2">
          <h6 class="text-h6">Mode</h6>
          <div class="d-flex gap-2">
            <div
              v-for="mode in themeMode"
              :key="mode.value"
              class="mode-card flex-grow-1 cursor-pointer rounded-lg pa-3 d-flex flex-column align-center gap-2"
              :class="{ 'mode-card--active': configStore.theme === mode.value }"
              @click="configStore.theme = mode.value as any"
            >
              <VIcon size="28" :icon="mode.icon" />
              <span class="text-xs text-medium-emphasis">{{ mode.label }}</span>
            </div>
          </div>
        </div>

        <!-- Skin -->
        <div class="d-flex flex-column gap-2">
          <h6 class="text-h6">Skins</h6>
          <CustomRadiosWithImage
            :key="configStore.skin"
            v-model:selected-radio="configStore.skin"
            :radio-content="themeSkin"
            :grid-column="{ cols: '6' }"
          >
            <template #label="item">
              <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
            </template>
          </CustomRadiosWithImage>
        </div>

        <!-- Semi Dark (vertical + light only) -->
        <div
          v-if="vuetifyTheme.global.name.value === 'light' && configStore.appContentLayoutNav === AppContentLayoutNav.Vertical"
          class="d-flex align-center justify-space-between"
        >
          <VLabel for="customizer-semi-dark" class="text-h6 text-high-emphasis">
            Semi Dark Menu
          </VLabel>
          <VSwitch id="customizer-semi-dark" v-model="configStore.isVerticalNavSemiDark" />
        </div>

      </CustomizerSection>

      <!-- SECTION: Layout -->
      <CustomizerSection title="Layout">

        <!-- Layout type -->
        <div class="d-flex flex-column gap-2">
          <h6 class="text-base font-weight-medium">Layout</h6>
          <CustomRadiosWithImage
            :key="currentLayout"
            v-model:selected-radio="currentLayout"
            :radio-content="layouts"
            :grid-column="{ cols: '4' }"
          >
            <template #label="item">
              <span class="text-sm text-medium-emphasis">{{ item.label }}</span>
            </template>
          </CustomRadiosWithImage>
        </div>

        <!-- Content Width -->
        <div class="d-flex flex-column gap-2">
          <h6 class="text-base font-weight-medium">Content</h6>
          <CustomRadiosWithImage
            :key="configStore.appContentWidth"
            v-model:selected-radio="configStore.appContentWidth"
            :radio-content="contentWidth"
            :grid-column="{ cols: '6' }"
          >
            <template #label="item">
              <span class="text-sm text-medium-emphasis">{{ item.label }}</span>
            </template>
          </CustomRadiosWithImage>
        </div>

        <!-- Navbar Blur -->
        <div class="d-flex align-center justify-space-between">
          <VLabel for="navbar-blur" class="text-base font-weight-medium text-high-emphasis">
            Navbar Blur
          </VLabel>
          <VSwitch id="navbar-blur" v-model="configStore.isNavbarBlurEnabled" />
        </div>

      </CustomizerSection>

      <!-- Save to DB -->
      <div class="pa-5 d-flex flex-column gap-3">
        <VDivider class="mb-2" />
        <VBtn
          block
          color="primary"
          :loading="saveForm.processing"
          prepend-icon="tabler-device-floppy"
          @click="saveToDb"
        >
          Save My Preferences
        </VBtn>

        <Transition name="fade">
          <VAlert
            v-if="saveSuccess"
            type="success"
            variant="tonal"
            density="compact"
            icon="tabler-circle-check"
          >
            Theme preferences saved!
          </VAlert>
        </Transition>

        <p class="text-caption text-center text-medium-emphasis mb-0">
          Your preferences are applied on every login.
        </p>
      </div>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>

<style lang="scss">
.app-customizer {
  &.v-navigation-drawer--temporary:not(.v-navigation-drawer--active) {
    transform: translateX(110%) !important;
  }

  .customizer-heading {
    padding-block: 1rem;
    padding-inline: 1.25rem;
  }

  .customizer-body {
    flex: 1;
    overflow-y: auto;
  }

  .customizer-section {
    display: flex;
    flex-direction: column;
    padding: 1.25rem;
    gap: 1.25rem;
  }

  .v-navigation-drawer__content {
    display: flex;
    flex-direction: column;
  }
}

// Color swatches
.color-swatch {
  border-radius: 0.375rem;
  outline: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding: 0.5rem 0.6rem;
  transition: outline-color 0.15s;

  &.active {
    outline-width: 2px;
  }

  &:not(.active):hover {
    outline-color: rgba(var(--v-border-color), 0.4) !important;
  }

  .color-swatch-inner {
    border-radius: 0.25rem;
    block-size: 2rem;
    inline-size: 1.75rem;
  }
}

// Mode cards
.mode-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  transition: border-color 0.15s, background-color 0.15s;

  &:hover {
    background-color: rgba(var(--v-theme-primary), 0.04);
  }

  &--active {
    border-color: rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.08);

    .v-icon {
      color: rgb(var(--v-theme-primary));
    }
  }
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
