import { createApp } from "vue";
import { createRouter, createWebHistory } from "vue-router";
import App from "./App.vue";
import { bindRouter, state } from "./services/app";
import "./styles.css";
const router = createRouter({
  history: createWebHistory(state.context.basePath.replace(/\/$/, "") + "/"),
  routes: [{ path: "/:pathMatch(.*)*", component: { template: "<span />" } }],
});
bindRouter(router);
const app = createApp(App).use(router);
router.isReady().then(() => app.mount("#updater-app"));
