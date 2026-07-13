# Changelog

All notable changes to the Maildeno PHP SDK are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.0.1] - 2026-07-13

### Fixed

- **Resolved missing border radius on the outer MJML table row.** The outer `<td>` wrapper now correctly preserves border radius during MJML rendering, ensuring consistent rounded corners in the rendered email output.

## [2.0.0] - 2026-07-10

### Added

- **`NativeEngine`** — the render engine. Runs a small platform-native
  `maildeno-engine` executable (via `symfony/process`), bundled with the
  package under `bin/<platform>/`. `MaildenoClient` resolves and uses it
  automatically — no configuration required for normal use.
- **`RenderEngine`** — the interface `NativeEngine` implements, so
  `MaildenoClient` (and its 3rd constructor argument, for injecting a
  pre-built or stub engine) isn't coupled to one concrete implementation.
- **`MaildenoClient`** accepts an optional `enginePath` config key with an
  exact binary path, for the rare case of overriding auto-detection (a
  non-standard binary location, or testing against a stub).

### Changed

- **Rendering moved off wasm/FFI entirely.** Previous builds rendered through
  an embedded `engine.wasm` via PHP FFI bindings to wasmtime
  (`ext-ffi` + a ~15–25MB wasmtime shared library per platform). That path —
  `MaildenoEngine`, `engine.wasm`, the `wasmPath`/`libPath` config keys, and
  the `lib/` directory — is removed. `NativeEngine` is now the only engine:
  no FFI extension, no wasmtime runtime to distribute, and a bundled binary
  typically well under 1MB per platform.
- `ext-ffi` is no longer referenced anywhere in `composer.json`.
- `symfony/process` is now a hard `require` (`^6.4 || ^7.0`), not a
  `suggest` — it's needed for every render, not an optional integration.

---

## [1.0.0] - 2026-07-06

Initial release. Full feature parity with the Maildeno JS SDK (`sdk-js` v2.1.2).

### Added

- **`MaildenoClient`** — fetches template JSON from the Maildeno API, caches it,
  and renders via the embedded Wasm engine. Public surface mirrors the JS SDK:
  `render()`, `renderHtml()`, `renderReact()`, `renderMjml()`, `listCached()`,
  `deleteCached()`, `clearCache()`, and the deprecated `invalidate()` alias.
  Requests are `GET /v1/sdk/template/{id}?target={target}` with a
  `Authorization: Bearer` header and a configurable timeout.

- **Caching** — `MemoryStore` (in-process, TTL, oldest-entry eviction) and
  `DiskStore` (one atomic JSON file per template, survives restarts) behind a
  `TemplateCache` facade. Defaults: 5-minute TTL, 50 entries.

- **Stale-on-error fallback** — when the TTL has expired and the API is
  unreachable, the last known-good template is used and
  `RenderResult::$fromStaleCache` is set to `true`.

- **`MaildenoError`** — single error type with a string `code`, HTTP `status`,
  and optional validation `issues`. Status mapping matches the JS SDK
  (401/403/404/422 → codes; network → `NETWORK_ERROR`; timeout → `TIMEOUT`).

- **`Minify`** — whitespace compaction for `html`, `mjml`, and `react-email`,
  a faithful port of `minify.ts` with shared golden test vectors.

- **`MaildenoEngine`** — FFI-to-wasmtime bridge running the same `engine.wasm`
  as the other SDKs; rendered output is byte-for-byte identical.

- **Injectable HTTP transport** (`HttpTransport`, default `CurlTransport`) and
  optional engine injection, for custom clients and testing.

- **Zero-dependency autoloader** and a 97-test framework-free suite.
