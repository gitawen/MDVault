<script setup lang="ts">
import type { HTMLAttributes, Ref } from "vue"
import { defaultDocument, useEventListener, useMediaQuery, useVModel } from "@vueuse/core"
import { TooltipProvider } from "reka-ui"
import { computed, ref } from "vue"
import { cn } from "@/lib/utils"
import { router } from "@inertiajs/vue3"
import {
  provideSidebarContext,
  SIDEBAR_COOKIE_MAX_AGE,
  SIDEBAR_COOKIE_NAME,
  SIDEBAR_KEYBOARD_SHORTCUT,
  SIDEBAR_WIDTH,
  SIDEBAR_WIDTH_COOKIE_NAME,
  SIDEBAR_WIDTH_ICON,
  SIDEBAR_WIDTH_MAX,
  SIDEBAR_WIDTH_MIN,
  SIDEBAR_WIDTH_STORAGE_KEY,
} from "./utils"

const props = withDefaults(defineProps<{
  defaultOpen?: boolean
  open?: boolean
  defaultWidth?: string
  class?: HTMLAttributes["class"]
}>(), {
  defaultOpen: !defaultDocument?.cookie.includes(`${SIDEBAR_COOKIE_NAME}=false`),
  open: undefined,
  defaultWidth: undefined,
})

const emits = defineEmits<{
  "update:open": [open: boolean]
  "update:width": [width: string]
}>()

const isMobile = useMediaQuery("(max-width: 768px)")
const openMobile = ref(false)

// Close mobile sidebar sheet automatically upon navigation
router.on('navigate', () => {
  openMobile.value = false
})

const open = useVModel(props, "open", emits, {
  defaultValue: props.defaultOpen ?? false,
  passive: (props.open === undefined) as false,
}) as Ref<boolean>

function getStoredWidth(): string {
  if (props.defaultWidth) {
    return props.defaultWidth
  }
  if (typeof document !== 'undefined') {
    const cookieMatch = document.cookie.match(
      new RegExp(`(?:^|; )${SIDEBAR_WIDTH_COOKIE_NAME}=([^;]*)`)
    )
    if (cookieMatch && cookieMatch[1]) {
      return decodeURIComponent(cookieMatch[1])
    }
    try {
      const stored = localStorage.getItem(SIDEBAR_WIDTH_STORAGE_KEY)
      if (stored) {
        return stored
      }
    } catch {
      // ignore
    }
  }
  return SIDEBAR_WIDTH
}

const width = ref(getStoredWidth())
const isResizing = ref(false)

function setWidth(newWidth: number | string) {
  let widthStr: string
  if (typeof newWidth === 'number') {
    const maxWidth = Math.min(
      SIDEBAR_WIDTH_MAX,
      typeof window !== 'undefined' ? Math.round(window.innerWidth * 0.7) : SIDEBAR_WIDTH_MAX
    )
    const clamped = Math.max(SIDEBAR_WIDTH_MIN, Math.min(maxWidth, Math.round(newWidth)))
    widthStr = `${clamped}px`
  } else {
    widthStr = newWidth
  }

  width.value = widthStr
  emits('update:width', widthStr)

  if (typeof document !== 'undefined') {
    document.cookie = `${SIDEBAR_WIDTH_COOKIE_NAME}=${encodeURIComponent(widthStr)}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`
    try {
      localStorage.setItem(SIDEBAR_WIDTH_STORAGE_KEY, widthStr)
    } catch {
      // ignore
    }
  }
}

function resetWidth() {
  setWidth(SIDEBAR_WIDTH)
}

function setOpen(value: boolean) {
  open.value = value // emits('update:open', value)

  // This sets the cookie to keep the sidebar state.
  document.cookie = `${SIDEBAR_COOKIE_NAME}=${open.value}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`
}

function setOpenMobile(value: boolean) {
  openMobile.value = value
}

// Helper to toggle the sidebar.
function toggleSidebar() {
  return isMobile.value ? setOpenMobile(!openMobile.value) : setOpen(!open.value)
}

useEventListener("keydown", (event: KeyboardEvent) => {
  if (event.defaultPrevented) {
    return
  }

  if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()
    toggleSidebar()
  }
})

// We add a state so that we can do data-state="expanded" or "collapsed".
// This makes it easier to style the sidebar with Tailwind classes.
const state = computed(() => open.value ? "expanded" : "collapsed")

provideSidebarContext({
  state,
  open,
  setOpen,
  isMobile,
  openMobile,
  setOpenMobile,
  toggleSidebar,
  width,
  setWidth,
  resetWidth,
  isResizing,
})
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <div
      data-slot="sidebar-wrapper"
      :style="{
        '--sidebar-width': width,
        '--sidebar-width-icon': SIDEBAR_WIDTH_ICON,
      }"
      :data-resizing="isResizing"
      :class="cn('group/sidebar-wrapper has-data-[variant=inset]:bg-sidebar flex min-h-svh w-full', props.class)"
      v-bind="$attrs"
    >
      <slot />
    </div>
  </TooltipProvider>
</template>
