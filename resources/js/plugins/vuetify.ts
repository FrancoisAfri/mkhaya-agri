import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { h } from 'vue'
import type { IconSet, IconProps } from 'vuetify'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'
import { aliases, mdi } from 'vuetify/iconsets/mdi'
import { Icon as IconifyIcon, addCollection } from '@iconify/vue'
import tablerIcons from '@iconify-json/tabler/icons.json'

// Register Tabler icons locally so they render without CDN access.
addCollection(tablerIcons as any)

// Iconify icon set bridge for Vuetify.
// Lets us write <VIcon>tabler-foo-bar</VIcon> → renders iconify "tabler:foo-bar".
// "mdi-foo" continues to use the bundled MDI font (no network fetch); any other
// "<prefix>-<rest>" (e.g. "bx-cog", "fa-user") falls back to iconify CDN.
const iconify: IconSet = {
  component: (props: IconProps) => {
    const raw = String(props.icon ?? '')
    if (raw.startsWith('mdi-')) {
      // Delegate to the MDI font icon set so it uses the bundled webfont.
      return h('i', { class: ['mdi', raw], 'aria-hidden': true })
    }
    // Convert "tabler-foo-bar" → "tabler:foo-bar"
    const name = raw.includes(':') ? raw : raw.replace(/^([^-]+)-/, '$1:')
    return h(IconifyIcon, { icon: name, style: { width: '1em', height: '1em' } })
  },
}

export default createVuetify({
  components,
  directives,
  icons: {
    defaultSet: 'iconify',
    aliases,
    sets: { mdi, iconify },
  },
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        dark: false,
        colors: {
          // Sneat primary purple
          primary: '#7367F0',
          'on-primary': '#FFFFFF',

          secondary: '#808390',
          'on-secondary': '#FFFFFF',

          success: '#28C76F',
          'on-success': '#FFFFFF',

          info: '#00CFE8',
          'on-info': '#FFFFFF',

          warning: '#FF9F43',
          'on-warning': '#FFFFFF',

          error: '#EA5455',
          'on-error': '#FFFFFF',

          background: '#F4F5FA',
          'on-background': '#32475C',

          surface: '#FFFFFF',
          'on-surface': '#32475C',

          'grey-50': '#FAFAFA',
          'grey-100': '#F5F5F5',
          'grey-200': '#EEEEEE',
          'grey-300': '#E0E0E0',
          'grey-400': '#BDBDBD',
          'grey-500': '#9E9E9E',
          'grey-600': '#757575',
          'grey-700': '#616161',
          'grey-800': '#424242',
          'grey-900': '#212121',

          'perfect-scrollbar-thumb': '#DBDADE',
        },
        variables: {
          'border-color': '#32475C',
          'border-opacity': 0.12,
          'high-emphasis-opacity': 0.87,
          'medium-emphasis-opacity': 0.6,
          'disabled-opacity': 0.45,
          'idle-opacity': 0.08,
          'hover-opacity': 0.04,
          'focus-opacity': 0.12,
          'selected-opacity': 0.12,
          'activated-opacity': 0.12,
          'pressed-opacity': 0.16,
          'dragged-opacity': 0.08,
          'chip-height': 32,
          'expansion-panel-content-padding': 0,
          'overlay-scrim-background': '#32475C',
          'overlay-scrim-opacity': 0.5,
          'shadow-key-umbra-opacity': 'rgba(var(--v-theme-on-surface), 0.08)',
          'shadow-key-penumbra-opacity': 'rgba(var(--v-theme-on-surface), 0.12)',
          'shadow-key-ambient-opacity': 'rgba(var(--v-theme-on-surface), 0.04)',
        },
      },
      dark: {
        dark: true,
        colors: {
          primary: '#7367F0',
          'on-primary': '#FFFFFF',

          secondary: '#7983BB',
          'on-secondary': '#FFFFFF',

          success: '#28C76F',
          'on-success': '#FFFFFF',

          info: '#00CFE8',
          'on-info': '#FFFFFF',

          warning: '#FF9F43',
          'on-warning': '#FFFFFF',

          error: '#EA5455',
          'on-error': '#FFFFFF',

          background: '#25293C',
          'on-background': '#D0D4F1',

          surface: '#2F3349',
          'on-surface': '#D0D4F1',

          'grey-50': '#26293B',
          'grey-100': '#2E3047',
          'grey-200': '#3A3F5A',
          'grey-300': '#454B6A',
          'grey-400': '#515778',
          'grey-500': '#7983BB',
          'grey-600': '#9AA3CE',
          'grey-700': '#B5BBD9',
          'grey-800': '#CDD1E8',
          'grey-900': '#E7E9F4',

          'perfect-scrollbar-thumb': '#4A5072',
        },
        variables: {
          'border-color': '#D0D4F1',
          'border-opacity': 0.12,
          'high-emphasis-opacity': 0.87,
          'medium-emphasis-opacity': 0.6,
          'disabled-opacity': 0.45,
          'idle-opacity': 0.1,
          'hover-opacity': 0.04,
          'focus-opacity': 0.12,
          'selected-opacity': 0.12,
          'activated-opacity': 0.12,
          'pressed-opacity': 0.16,
          'dragged-opacity': 0.1,
          'chip-height': 32,
          'expansion-panel-content-padding': 0,
          'overlay-scrim-background': '#171925',
          'overlay-scrim-opacity': 0.6,
        },
      },
    },
  },
  defaults: {
    VCard: {
      elevation: 0,
    },
    VBtn: {
      elevation: 0,
    },
  },
})
