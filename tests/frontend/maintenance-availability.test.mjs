import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("../../resources/frontend/services/availability.ts", import.meta.url), "utf8");
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2022 } }).outputText;
const { applicationRootUrl, probeApplication } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`);

function browser(t, fetchResponse) {
  const previousWindow = globalThis.window;
  const previousFetch = globalThis.fetch;
  globalThis.window = { location: { origin: "https://app.example", pathname: "/grafico" }, setTimeout, clearTimeout };
  globalThis.fetch = fetchResponse;
  t.after(() => { globalThis.window = previousWindow; globalThis.fetch = previousFetch; });
}

test("uses the real application root without inventing a Manager grafico endpoint", async (t) => {
  browser(t, async (url, options) => {
    assert.equal(url, "https://app.example/");
    assert.equal(options.method, "GET");
    assert.equal(options.cache, "no-store");
    assert.equal(options.credentials, "same-origin");
    assert.equal(options.headers["X-Updater-SPA"], undefined);
    return { status: 503, ok: false, url };
  });
  const result = await probeApplication({ basePath: "/_updater", applicationRootPath: "/" });
  assert.equal(result.available, false);
  assert.match(result.message, /continua em manutenção/);
  assert.equal(result.destination, undefined);
});

test("same-origin root redirects to a real host page only after a successful response", async (t) => {
  browser(t, async () => ({ status: 200, ok: true, url: "https://app.example/grafico" }));
  const result = await probeApplication({ basePath: "/_updater", applicationRootPath: "/" });
  assert.equal(result.available, true);
  assert.equal(result.destination, "https://app.example/grafico");
});

test("supports application subdirectories and composite configurable Manager prefixes", async (t) => {
  browser(t, async (url) => {
    assert.equal(url, "https://app.example/erp/");
    return { status: 200, ok: true, url: "https://app.example/erp/login" };
  });
  const result = await probeApplication({ basePath: "/erp/control/updater", applicationRootPath: "/erp/" });
  assert.equal(result.available, true);
  assert.equal(result.destination, "https://app.example/erp/login");
});

test("cannot mistake an exempt Manager login or an external redirect for application availability", async (t) => {
  browser(t, async () => ({ status: 200, ok: true, url: "https://app.example/_updater/login" }));
  assert.equal((await probeApplication({ basePath: "/_updater" })).available, false);
  globalThis.fetch = async () => ({ status: 200, ok: true, url: "https://external.example/login" });
  assert.equal((await probeApplication({ basePath: "/_updater" })).available, false);
});

test("HTTP errors and opaque redirects never release navigation", async (t) => {
  browser(t, async () => ({}));
  for (const status of [0, 401, 404, 500, 503]) {
    globalThis.fetch = async () => ({ status, ok: false, url: "https://app.example/" });
    const result = await probeApplication({ basePath: "/_updater" });
    assert.equal(result.available, false, String(status));
    assert.equal(result.destination, undefined);
  }
});

test("network failures and timeouts keep the maintenance screen with feedback", async (t) => {
  browser(t, async () => { throw new TypeError("offline"); });
  const disconnected = await probeApplication({ basePath: "/_updater" });
  assert.equal(disconnected.available, false);
  assert.match(disconnected.message, /conexão/);
  globalThis.fetch = async (_url, { signal }) => new Promise((_resolve, reject) => {
    signal.addEventListener("abort", () => reject(new DOMException("timeout", "AbortError")), { once: true });
  });
  const timeout = await probeApplication({ basePath: "/_updater" }, 1);
  assert.equal(timeout.available, false);
  assert.match(timeout.message, /demorou/);
});

test("unsafe supplied roots cannot select a different origin or carry URL instructions", (t) => {
  browser(t, async () => ({}));
  for (const applicationRootPath of ["javascript:alert(1)", "//external.example/", "https://user:pass@external.example/", "/\\external.example/", "/?next=external", "/#external"]) {
    assert.equal(applicationRootUrl({ basePath: "/_updater", applicationRootPath }), "https://app.example/");
  }
});
