<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { onBeforeUnmount, ref } from "vue"
import { SIDEBAR_WIDTH_MAX, SIDEBAR_WIDTH_MIN, useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const railRef = ref<HTMLButtonElement | null>(null)
const {
  open,
  setOpen,
  width,
  setWidth,
  resetWidth,
  isResizing,
  isMobile,
} = useSidebar()

let startX = 0
let startWidth = 0
let hasMoved = false

function onPointerDown(event: PointerEvent) {
  if (event.button !== 0 || isMobile.value) {
    return
  }

  event.preventDefault()
  event.stopPropagation()

  startX = event.clientX
  hasMoved = false

  // Measure current rendered width from the sidebar fixed element, or parse width state
  const sidebarFixedEl = railRef.value?.closest('[data-slot="sidebar"]')?.querySelector('.fixed') as HTMLElement | null
  if (sidebarFixedEl) {
    startWidth = sidebarFixedEl.getBoundingClientRect().width
  } else {
    const parsed = parseFloat(width.value)
    startWidth = width.value.endsWith('rem') ? parsed * 16 : (parsed || 256)
  }

  isResizing.value = true
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'

  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', onPointerUp)
  window.addEventListener('pointercancel', onPointerUp)
}

function onPointerMove(event: PointerEvent) {
  const deltaX = event.clientX - startX
  if (!hasMoved && Math.abs(deltaX) > 2) {
    hasMoved = true
  }

  if (hasMoved) {
    // If collapsed and user drags right, expand it
    if (!open.value && deltaX > 20) {
      setOpen(true)
    }

    if (open.value) {
      const maxWidth = Math.min(
        SIDEBAR_WIDTH_MAX,
        typeof window !== 'undefined' ? Math.round(window.innerWidth * 0.7) : SIDEBAR_WIDTH_MAX
      )
      const targetWidth = Math.max(
        SIDEBAR_WIDTH_MIN,
        Math.min(maxWidth, Math.round(startWidth + deltaX))
      )
      setWidth(targetWidth)
    }
  }
}

function onPointerUp() {
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
  window.removeEventListener('pointercancel', onPointerUp)

  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  isResizing.value = false

  if (!hasMoved) {
    // A single click on the rail while collapsed expands the sidebar
    if (!open.value) {
      setOpen(true)
    }
  }
}

function onDoubleClick(event: MouseEvent) {
  event.preventDefault()
  event.stopPropagation()
  resetWidth()
}

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
  window.removeEventListener('pointercancel', onPointerUp)
  if (typeof document !== 'undefined' && document.body) {
    document.body.style.cursor = ''
    document.body.style.userSelect = ''
  }
})
</script>

<template>
  <button
    ref="railRef"
    data-sidebar="rail"
    data-slot="sidebar-rail"
    aria-label="Toggle or resize sidebar"
    :tabindex="-1"
    title="Drag to resize • Double-click to reset"
    :class="cn(
      'absolute inset-y-0 z-20 hidden w-3 transition-colors duration-150 sm:flex select-none touch-none',
      'group-data-[side=left]:-right-1.5 group-data-[side=right]:-left-1.5',
      'cursor-col-resize',
      'after:absolute after:inset-y-0 after:left-1/2 after:-translate-x-1/2 after:w-[2px] after:transition-all after:duration-150',
      'hover:after:bg-primary/70 hover:after:w-[3px]',
      isResizing && 'after:bg-primary after:w-[3px]',
      '[[data-side=left][data-collapsible=offcanvas]_&]:-right-2',
      '[[data-side=right][data-collapsible=offcanvas]_&]:-left-2',
      props.class,
    )"
    @pointerdown="onPointerDown"
    @dblclick="onDoubleClick"
  >
    <slot />
  </button>
</template>
