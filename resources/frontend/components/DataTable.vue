<script setup lang="ts">
import { computed, ref } from "vue";
import { dateTime, statusLabel } from "../services/format";
export interface Column {
  key: string;
  label: string;
  format?: (value: any, row: any) => string;
}
const props = withDefaults(
  defineProps<{
    rows?: any[];
    columns: Column[];
    search?: boolean;
    empty?: string;
  }>(),
  { rows: () => [], search: true, empty: "Nenhum registro encontrado." },
);
const query = ref("");
const sortKey = ref("");
const descending = ref(false);
const filtered = computed(() => {
  const rows = props.rows.filter(
    (row) =>
      !query.value ||
      props.columns.some((column) =>
        String(row[column.key] ?? "")
          .toLocaleLowerCase("pt-BR")
          .includes(query.value.toLocaleLowerCase("pt-BR")),
      ),
  );
  if (sortKey.value)
    rows.sort(
      (a, b) =>
        String(a[sortKey.value] ?? "").localeCompare(
          String(b[sortKey.value] ?? ""),
          "pt-BR",
          { numeric: true },
        ) * (descending.value ? -1 : 1),
    );
  return rows;
});
function sort(key: string) {
  descending.value = sortKey.value === key ? !descending.value : false;
  sortKey.value = key;
}
function value(row: any, column: Column): string {
  const current = row[column.key];
  if (column.format) return column.format(current, row);
  if (column.key === "status") return statusLabel(current);
  if (/(?:_at|timestamp)$/.test(column.key)) return dateTime(current);
  if (typeof current === "boolean") return current ? "Sim" : "Não";
  if (Array.isArray(current)) return current.join(", ");
  if (current && typeof current === "object") return JSON.stringify(current);
  return current === null || current === undefined || current === ""
    ? "—"
    : String(current);
}
</script>
<template>
  <div class="data-table">
    <div v-if="search && rows.length" class="table-tools">
      <label
        >Buscar nesta lista<input
          v-model="query"
          type="search"
          placeholder="Digite para filtrar" /></label
      ><span class="muted">{{ filtered.length }} registro(s)</span>
    </div>
    <div v-if="!filtered.length" class="empty-state">
      <span class="empty-mark" aria-hidden="true">∅</span>
      <h3>{{ empty }}</h3>
      <p class="muted">
        {{
          query
            ? "Tente outro termo de busca."
            : "Os registros aparecerão aqui assim que estiverem disponíveis."
        }}
      </p>
    </div>
    <div v-else class="table-scroll">
      <table>
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              :aria-sort="
                sortKey === column.key
                  ? descending
                    ? 'descending'
                    : 'ascending'
                  : 'none'
              "
            >
              <button
                type="button"
                class="sort-button"
                @click="sort(column.key)"
              >
                {{ column.label
                }}<span aria-hidden="true">{{
                  sortKey === column.key ? (descending ? " ↓" : " ↑") : " ↕"
                }}</span>
              </button>
            </th>
            <th v-if="$slots.actions">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, index) in filtered"
            :key="row.id ?? row.migration ?? index"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              :data-label="column.label"
            >
              <slot :name="column.key" :row="row" :value="row[column.key]"
                ><span
                  :class="
                    column.key === 'status'
                      ? ['badge', String(row.status).toLowerCase()]
                      : ''
                  "
                  >{{ value(row, column) }}</span
                ></slot
              >
            </td>
            <td v-if="$slots.actions" data-label="Ações">
              <div class="row-actions"><slot name="actions" :row="row" /></div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
