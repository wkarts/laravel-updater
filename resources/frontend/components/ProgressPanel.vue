<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from "vue";
import { jsonGet, route } from "../services/app";
import ActionForm from "./ActionForm.vue";
const props = withDefaults(
  defineProps<{
    endpoint?: string;
    title?: string;
    cancelRoute?: string;
    upload?: boolean;
  }>(),
  { title: "Andamento da operação" },
);
const progress = ref<any>(null);
const error = ref("");
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
const percent = computed(() =>
  Math.max(0, Math.min(100, Number(progress.value?.progress || 0))),
);
async function poll() {
  if (!props.endpoint) return;
  try {
    progress.value = await jsonGet(props.endpoint);
    error.value = "";
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    if (!disposed) timer = setTimeout(poll, document.hidden ? 12000 : 4000);
  }
}
onMounted(poll);
onUnmounted(() => {
  disposed = true;
  clearTimeout(timer);
});
</script>
<template>
  <section v-if="endpoint" class="panel progress-panel">
    <div class="panel-heading">
      <h2>{{ title }}</h2>
      <span v-if="progress?.active" class="badge running">Em execução</span>
    </div>
    <p v-if="error" class="notice warning" role="alert">
      {{ error }}
      <button class="text-button" @click="poll">Tentar novamente</button>
    </p>
    <template v-else-if="progress"
      ><p role="status">
        {{ progress.message || "Nenhuma operação em andamento." }}
      </p>
      <progress :value="percent" max="100" :aria-label="title">
        {{ percent }}%
      </progress>
      <div v-if="progress.jobs" class="stack">
        <div v-for="(job, id) in progress.jobs" :key="id">
          <strong>Backup #{{ id }} · {{ job.status }}</strong>
          <p>{{ job.message }}</p>
          <progress :value="job.progress || 0" max="100">
            {{ job.progress || 0 }}%
          </progress>
        </div>
      </div>
      <ul v-if="progress.logs?.length" class="event-list">
        <li v-for="(log, i) in progress.logs.slice(-5)" :key="i">
          <span class="badge">{{ log.level }}</span
          >{{ log.message }}
        </li>
      </ul>
      <div class="actions">
        <a
          v-if="progress.run?.id && route('updater.runs.show')"
          class="button subtle"
          :href="route('updater.runs.show', { id: progress.run.id })"
          >Ver execução #{{ progress.run.id }}</a
        ><ActionForm
          v-if="cancelRoute && progress.can_cancel"
          :action="cancelRoute"
          confirm="Cancelar a operação em andamento?"
          ><button class="button danger">Cancelar operação</button></ActionForm
        >
      </div></template
    >
    <p v-else class="muted" role="status">Consultando o servidor…</p>
  </section>
</template>
