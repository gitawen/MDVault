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
        viewBox="88 12 336 488"
        :class="className"
        v-bind="$attrs"
    >
        <mask
            id="app-logo-mark"
            maskUnits="userSpaceOnUse"
            x="0"
            y="0"
            width="512"
            height="512"
        >
            <g
                fill="none"
                stroke="#fff"
                stroke-width="24"
                stroke-linejoin="round"
                stroke-linecap="round"
            >
                <path
                    d="M140 28H316L408 120V448a36 36 0 0 1-36 36H140a36 36 0 0 1-36-36V64a36 36 0 0 1 36-36z"
                />
                <path d="M316 28V96a24 24 0 0 0 24 24H408" />
            </g>
            <path
                d="M206 254V214a50 50 0 0 1 100 0v40"
                fill="none"
                stroke="#fff"
                stroke-width="26"
            />
            <rect x="166" y="240" width="180" height="142" rx="26" fill="#fff" />
            <path
                d="M209 348V280l47 41 47-41v68"
                fill="none"
                stroke="#000"
                stroke-width="23"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </mask>
        <rect
            width="512"
            height="512"
            fill="currentColor"
            mask="url(#app-logo-mark)"
        />
    </svg>
</template>
