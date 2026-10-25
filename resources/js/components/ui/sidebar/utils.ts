import type { ComputedRef, Ref } from "vue"
import { createContext } from "reka-ui"

export const SIDEBAR_COOKIE_NAME = "sidebar_state"
export const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7
export const SIDEBAR_WIDTH_COOKIE_NAME = "sidebar_width"
export const SIDEBAR_WIDTH_STORAGE_KEY = "sidebar_width"
export const SIDEBAR_WIDTH = "16rem"
export const SIDEBAR_WIDTH_DEFAULT_PX = 256
export const SIDEBAR_WIDTH_MIN = 200
export const SIDEBAR_WIDTH_MAX = 800
export const SIDEBAR_WIDTH_MOBILE = "18rem"
export const SIDEBAR_WIDTH_ICON = "3rem"
export const SIDEBAR_KEYBOARD_SHORTCUT = "b"

export interface SidebarContext {
  state: ComputedRef<"expanded" | "collapsed">
  open: Ref<boolean>
  setOpen: (value: boolean) => void
  isMobile: Ref<boolean>
  openMobile: Ref<boolean>
  setOpenMobile: (value: boolean) => void
  toggleSidebar: () => void
  width: Ref<string>
  setWidth: (width: number | string) => void
  resetWidth: () => void
  isResizing: Ref<boolean>
}

export const [useSidebar, provideSidebarContext] = createContext<SidebarContext>("Sidebar")
