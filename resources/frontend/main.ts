import { createApp } from "vue";
import { createMemoryHistory, createRouter, createWebHistory } from "vue-router";
import App from "./App.vue";
import { bindRouter, state } from "./services/app";
import "./styles.css";
const router = createRouter({
  // A public maintenance page can live at any host route, outside the Manager.
  // Memory history keeps Vue Router from rewriting /grafico as /_updater/grafico.
  history: state.page === "maintenance"
    ? createMemoryHistory()
    : createWebHistory(state.context.basePath.replace(/\/$/, "") + "/"),
  routes: [{ path: "/:pathMatch(.*)*", component: { template: "<span />" } }],
});
bindRouter(router);
const app = createApp(App).use(router);
router.isReady().then(() => app.mount("#updater-app"));
