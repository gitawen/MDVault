<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="14 24 372 252"
        :class="className"
        v-bind="$attrs"
    >
        <g
            fill="none"
            stroke="currentColor"
            stroke-width="52"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M40 250V50l100 120 100-120v200" />
            <path d="M240 50h20a100 100 0 0 1 0 200h-20" />
        </g>
    </svg>
</template>
