<script setup lang="ts">
import { computed } from "vue";
const props = withDefaults(
  defineProps<{ name: string; size?: number; strokeWidth?: number }>(),
  { size: 20, strokeWidth: 1.7 },
);
const paths: Record<string, string[]> = {
  home: ["M3 10 12 3l9 7", "M5 9v12h14V9", "M9 21v-8h6v8"],
  refresh: [
    "M20 7v5h-5",
    "M4 17v-5h5",
    "M6.1 6.2A8 8 0 0 1 20 12",
    "M4 12a8 8 0 0 0 13.9 5.8",
  ],
  play: ["m8 4 12 8-12 8V4Z"],
  database: [
    "M20 6c0 2.2-3.6 4-8 4S4 8.2 4 6s3.6-4 8-4 8 1.8 8 4Z",
    "M4 6v12c0 2.2 3.6 4 8 4s8-1.8 8-4V6",
    "M4 12c0 2.2 3.6 4 8 4s8-1.8 8-4",
  ],
  layers: ["m12 3 10 5-10 5L2 8l10-5Z", "m2 12 10 5 10-5", "m2 16 10 5 10-5"],
  shield: ["m12 2 8 4v6c0 5-4 8.5-8 10-4-1.5-8-5-8-10V6l8-4Z"],
  settings: [
    "m10 3-.7 2.2-2 .9-2.1-.5L3.5 9l1.5 1.7v2.6L3.5 15l1.7 3.4 2.1-.5 2 .9.7 2.2h4l.7-2.2 2-.9 2.1.5 1.7-3.4-1.5-1.7v-2.6L20.5 9l-1.7-3.4-2.1.5-2-.9L14 3h-4Z",
    "M15.2 12a3.2 3.2 0 1 1-6.4 0 3.2 3.2 0 0 1 6.4 0Z",
  ],
  users: [
    "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2",
    "M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z",
    "M22 21v-2a4 4 0 0 0-3-3.9",
    "M16 3.1a4 4 0 0 1 0 7.8",
  ],
  user: [
    "M20 21v-2a6 6 0 0 0-6-6h-4a6 6 0 0 0-6 6v2",
    "M16 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z",
  ],
  code: ["m7 6-6 6 6 6", "m17 6 6 6-6 6", "m14 3-4 18"],
  link: [
    "M10 13a5 5 0 0 0 7 .1l3-3a5 5 0 0 0-7-7l-1.7 1.7",
    "M14 11a5 5 0 0 0-7-.1l-3 3a5 5 0 0 0 7 7l1.7-1.7",
  ],
  key: [
    "M16 2a6 6 0 1 1-4.2 10.2L3 21H1v-4l9.2-9.2A6 6 0 0 1 16 2Z",
    "M17 7h.01",
  ],
  "chevron-right": ["m9 5 7 7-7 7"],
  "chevron-left": ["m15 5-7 7 7 7"],
  "chevron-down": ["m6 9 6 6 6-6"],
  "chevron-up": ["m6 15 6-6 6 6"],
  "arrow-right": ["M4 12h16", "m14 6 6 6-6 6"],
  "arrow-left": ["M20 12H4", "m10 6-6 6 6 6"],
  "arrow-up": ["M12 20V4", "m6 10 6-6 6 6"],
  check: ["m5 12 4 4L19 6"],
  "check-circle": ["M22 11.1V12a10 10 0 1 1-5.9-9.1", "m22 4-10 10-3-3"],
  alert: [
    "m10.3 3.8-8.1 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.8-3.2l-8.1-14a2 2 0 0 0-3.4 0Z",
    "M12 8v5",
    "M12 17h.01",
  ],
  info: ["M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z", "M12 11v6", "M12 7h.01"],
  clock: ["M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z", "M12 6v6l4 2"],
  history: ["M3 3v6h6", "M3 9a9 9 0 1 1-.2 7", "M12 7v5l4 2"],
  file: [
    "M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z",
    "M14 2v6h6",
    "M8 13h8",
    "M8 17h5",
  ],
  search: ["M19 10a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z", "m17 17 5 5"],
  menu: ["M3 6h18", "M3 12h18", "M3 18h18"],
  close: ["m6 6 12 12", "M18 6 6 18"],
  external: [
    "M15 3h6v6",
    "M10 14 21 3",
    "M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5",
  ],
  copy: ["M9 9h12v12H9V9Z", "M5 15H3V3h12v2"],
  upload: ["M12 16V3", "m7 8 5-5 5 5", "M3 16v5h18v-5"],
  download: ["M12 3v13", "m7 11 5 5 5-5", "M3 16v5h18v-5"],
  trash: ["M3 6h18", "M8 6V3h8v3", "M5 6l1 15h12l1-15", "M10 10v7", "M14 10v7"],
  plus: ["M12 5v14", "M5 12h14"],
  branch: [
    "M6 3v12a5 5 0 0 0 5 5h7",
    "M6 7h8a4 4 0 0 0 4-4",
    "M9 3a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z",
    "M21 20a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z",
  ],
  book: [
    "M12 4v18",
    "M12 4C8 1 4 2 2 3v16c3-1 7-1 10 3 3-4 7-4 10-3V3c-2-1-6-2-10 1Z",
  ],
  server: [
    "M3 3h18v7H3V3Z",
    "M3 14h18v7H3v-7Z",
    "M7 6.5h.01",
    "M7 17.5h.01",
    "M11 6.5h6",
    "M11 17.5h6",
  ],
  globe: [
    "M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z",
    "M2 12h20",
    "M12 2a19 19 0 0 1 0 20 19 19 0 0 1 0-20Z",
  ],
  leaf: ["M20 3C8 2 2 8 4 16c8 5 17 0 16-13Z", "M3 21 16 8"],
  lock: ["M5 10h14v12H5V10Z", "M8 10V6a4 4 0 0 1 8 0v4"],
  logout: ["M9 3H4v18h5", "M9 12h13", "m17 7 5 5-5 5"],
  moon: ["M21 13A9 9 0 0 1 11 3a9 9 0 1 0 10 10Z"],
  sun: [
    "M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z",
    "M12 2v2",
    "M12 20v2",
    "M2 12h2",
    "M20 12h2",
    "m4.9 4.9 1.4 1.4",
    "m17.7 17.7 1.4 1.4",
    "m19.1 4.9-1.4 1.4",
    "m6.3 17.7-1.4 1.4",
  ],
  more: ["M12 5h.01", "M12 12h.01", "M12 19h.01"],
};
const aliases: Record<string, string> = {
  "refresh-cw": "refresh",
  "git-branch": "branch",
  x: "close",
  "log-out": "logout",
  "circle-check": "check-circle",
  "alert-triangle": "alert",
  "file-text": "file",
  "book-open": "book",
  "external-link": "external",
};
const resolved = computed(
  () => paths[aliases[props.name] || props.name] || paths.file,
);
</script>
<template>
  <svg
    v-if="name === 'cube'"
    :width="size"
    :height="size"
    viewBox="0 0 24 26"
    fill="none"
    class="ui-icon cube-icon"
    aria-hidden="true"
    focusable="false"
  >
    <path d="m12 1 11 6.3L12 14 1 7.3 12 1Z" fill="#4D8CFF" />
    <path d="m1 7.3 11 6.4V26L1 19.6V7.3Z" fill="currentColor" />
    <path d="m12 13.7 11-6.4v12.3L12 26V13.7Z" fill="#0B2F9A" />
    <path d="m6.6 10.6 5.4-3.2 5.4 3.2-5.4 3.1-5.4-3.1Z" fill="white" />
    <path d="M12 13.7v6.1l5.4-3.2v-6L12 13.7Z" fill="#CBE1FF" />
  </svg>
  <svg
    v-else
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="strokeWidth"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="ui-icon"
    aria-hidden="true"
    focusable="false"
  >
    <path v-for="(path, index) in resolved" :key="index" :d="path" />
  </svg>
</template>
