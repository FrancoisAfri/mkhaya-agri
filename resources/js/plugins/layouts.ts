import type { App } from 'vue'
import { createLayouts } from '@layouts'
import { layoutConfig } from '@themeConfig'

export default function (app: App) {
  app.use(createLayouts(layoutConfig))
}
