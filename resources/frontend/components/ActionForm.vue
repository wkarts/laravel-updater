<script setup lang="ts">
import { nextTick, ref } from "vue";
import { mutation } from "../services/app";
defineOptions({ inheritAttrs: false });
const props = withDefaults(
  defineProps<{
    action: string;
    method?: string;
    confirm?: string;
    submitLabel?: string;
  }>(),
  { method: "POST" },
);
const emit = defineEmits<{ success: [payload: any] }>();
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});
const result = ref<any>(null);
const form = ref<HTMLFormElement>();
const dialog = ref<HTMLDialogElement>();
let pendingBody: FormData | null = null;
async function submit(event: SubmitEvent) {
  if (!form.value || busy.value) return;
  pendingBody = new FormData(form.value);
  if (event.submitter instanceof HTMLButtonElement && event.submitter.name)
    pendingBody.set(event.submitter.name, event.submitter.value);
  if (props.confirm) {
    dialog.value?.showModal();
    await nextTick();
    dialog.value?.querySelector<HTMLButtonElement>("button")?.focus();
  } else await perform();
}
async function perform() {
  dialog.value?.close();
  if (!pendingBody) return;
  busy.value = true;
  errors.value = {};
  result.value = null;
  try {
    const response = await mutation(props.action, pendingBody, props.method);
    errors.value = response.errors ?? {};
    if (!response.errors) {
      result.value = response.result;
      form.value
        ?.querySelectorAll<HTMLInputElement>("input[type=password]")
        .forEach((input) => {
          input.value = "";
        });
      emit("success", response.result);
    }
    busy.value = false;
    await nextTick();
    for (const element of Array.from(form.value?.elements ?? [])) {
      if (!(
        element instanceof HTMLInputElement ||
        element instanceof HTMLSelectElement ||
        element instanceof HTMLTextAreaElement
      ))
        continue;
      element.removeAttribute("aria-invalid");
      element.parentElement
        ?.querySelector(`[data-error-for="${CSS.escape(element.name)}"]`)
        ?.remove();
      const messages = errors.value[element.name];
      if (messages) {
        element.setAttribute("aria-invalid", "true");
        const message = document.createElement("span");
        message.className = "field-error";
        message.dataset.errorFor = element.name;
        message.id = `error-${element.name.replace(/\W/g, "-")}`;
        message.textContent = messages.join(" ");
        element.setAttribute("aria-describedby", message.id);
        element.insertAdjacentElement("afterend", message);
      }
    }
    if (Object.keys(errors.value).length)
      form.value?.querySelector<HTMLElement>("[aria-invalid=true]")?.focus();
  } catch {
    errors.value = {
      action: [
        "Não foi possível enviar. Verifique sua conexão e tente novamente.",
      ],
    };
  } finally {
    busy.value = false;
    pendingBody = null;
  }
}
</script>
<template>
  <form
    v-bind="$attrs"
    ref="form"
    class="action-form"
    method="POST"
    :action="action || undefined"
    :aria-busy="busy"
    @submit.prevent="submit"
  >
    <p v-if="!action" class="notice warning" role="status">Operação indisponível nesta instalação.</p>
    <div v-if="Object.keys(errors).length" class="notice danger" role="alert">
      <strong>Revise os campos para continuar.</strong>
      <ul>
        <li v-for="(messages, key) in errors" :key="key">
          {{ Array.isArray(messages) ? messages.join(" ") : messages }}
        </li>
      </ul>
    </div>
    <fieldset :disabled="busy || !action">
      <slot :errors="errors" :busy="busy" />
    </fieldset>
    <p v-if="busy" class="muted" role="status">
      Enviando solicitação com segurança…
    </p>
    <div
      v-if="result?.secret || result?.token"
      class="notice success"
      role="status"
    >
      <strong>Copie agora. Este segredo não será exibido novamente.</strong
      ><code class="one-time-secret">{{ result.secret || result.token }}</code
      ><button class="button subtle" type="button" @click="result = null">
        Ocultar segredo
      </button>
    </div>
    <p v-else-if="result && !result.page" class="notice success" role="status">
      {{ result.message || "Solicitação concluída." }}
    </p>
  </form>
  <dialog ref="dialog" class="confirmation-dialog" @cancel="pendingBody = null">
    <h2>Confirmar operação</h2>
    <p>{{ confirm }}</p>
    <div class="actions">
      <button
        class="button subtle"
        type="button"
        @click="
          dialog?.close();
          pendingBody = null;
        "
      >
        Cancelar</button
      ><button class="button primary" type="button" @click="perform">
        Confirmar
      </button>
    </div>
  </dialog>
</template>
