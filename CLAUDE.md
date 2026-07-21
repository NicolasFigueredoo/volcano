# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Laravel 12 (PHP 8.2) + Inertia v2 + Vue 3 + TypeScript + Tailwind. SQLite database. Pest v3 for tests, Pint for PHP formatting, ESLint/Prettier for the frontend.

## Commands

```bash
# Run everything (server + queue listener + vite) in one terminal
composer run dev

# Frontend only
npm run dev            # Vite dev server
npm run build           # Production build (required if you don't see UI changes reflected without `dev` running)

# Frontend quality
npm run lint            # eslint --fix
npm run format           # prettier --write resources/
npm run format:check

# Backend tests
php artisan test --compact                       # full suite
php artisan test --compact --filter=testName      # single test
vendor/bin/pest --filter=testName                 # equivalent via pest directly

# PHP formatting — run after any PHP edit, before finishing a task
vendor/bin/pint --dirty --format agent
```

Database is SQLite (`database/database.sqlite`); CI creates it with `touch` and runs `php artisan migrate`/`ziggy:generate` before tests.

## Architecture

### Two parallel route trees, one SPA shell

- `routes/web.php` — Inertia page routes. Each one just does `Inertia::render('PageName')` with **no server-side props**; the Vue page component is a self-contained app view (`resources/js/pages/*.vue`) that fetches its own data on mount.
- `routes/api.php` — the actual JSON API the pages talk to, under `Route::middleware(['web', 'auth'])` (session/cookie-based, not token-based — same login session as the Inertia app).

Frontend pages call the API via `resources/js/composables/useApi.ts`, a thin `fetch()` wrapper that reads the `XSRF-TOKEN` cookie and sends it as a header (Sanctum's stateful SPA auth), not via Inertia forms/props. When adding a page feature, add/extend an `Api\*Controller` + route in `api.php`, and call it with `useApi()` from the Vue component — don't reach for `Inertia::render` props for data that changes after the initial page load.

### Roles and authorization

`User.role` is either `admin` or `cajero` (see `User::isAdmin()`). Two enforcement mechanisms coexist:
- Route-level: the `es_admin` middleware (`App\Http\Middleware\EsAdmin`, aliased in `bootstrap/app.php`) guards the `/admin`, `/usuarios` web routes and the `admin/*`, `usuarios/*` API groups. It returns JSON 403 for `api/*` requests and redirects to `pos` otherwise.
- Controller-level: most of `CajaController`'s write actions (`update`, `destroy`, `cerrarManual`, `editarVenta`, `anularVenta`, `agregarVenta`, etc.) are **not** behind `es_admin` route middleware — they check `Auth::user()->isAdmin()` manually inside the method and return a 403 JSON response. Follow this existing pattern for new admin-only Caja/Venta actions rather than adding route middleware.

### Domain model: Caja (till) → Venta (sale) → DetalleVenta / PagoVenta

- `Caja` represents one till session for one **operating day** (`fecha_operativa`), not a calendar day: `Caja::fechaOperativaActual()` treats anything before 6am as still belonging to the previous day. `Caja::abiertaActual()` finds the currently open till for today's operating day.
- A `cajero` opens/closes the till (`abrir`/`cerrar` in `CajaController`); an `admin` can only view it, plus manually close, edit, delete, or retroactively add/edit/void orders in any till (open or closed) — see `cerrarManual`, `update`, `destroy`, `editarVenta`, `anularVenta`, `agregarVenta`.
- `Venta` (a pedido/order) belongs to a `Caja`, has many `DetalleVenta` (line items, each snapshotting `precio_snapshot`/`costo_snapshot` at sale time so later price changes don't rewrite history) and `PagoVenta` (payments, method `efectivo`/`transferencia`, a sale can be split across both).
- Sales are created two ways:
  - `Venta::registrar()` — normal POS flow (`PosController::registrarVenta`), always requires `Caja::abiertaActual()` and auto-assigns `numero_orden` scoped to today.
  - `Venta::registrarEnCaja()` — admin retroactive-entry path (`CajaController::agregarVenta`), takes an explicit `Caja` (open or closed) and scopes `numero_orden` to that caja instead of "today".
  - Both deduct stock via `Variante::recetas` (`Insumo` + quantity), writing a `MovimientoStock` row, but only for insumos flagged `descuenta_stock`.
- `estado` lifecycle for a venta: `pendiente → preparacion → pagado → entregado`, plus a terminal `anulado` (voided) that always excludes it from totals. `PosController::actualizarEstado` enforces the forward-only transition map; `CajaController::actualizarEstadoVenta` (admin) sets it freely.
- Money/stat aggregation is centralized in `CajaController::calcularStats()` — always filters out `anulado` ventas, and only computes cost/profit/`separacion` (suggested cash split: reponer_insumos / ahorro 10% / retiro 40% / negocio 50%) when called with admin context. Reuse this method (or `recalcularTotalesSolo` / `totalesDeCajas`) instead of re-deriving totals elsewhere — a closed caja's totals are **stored** on the row (frozen at close time), while an open caja's stats are recomputed live from its ventas on every request.

### Frontend conventions

- Path alias `@` → `resources/js` (set in both `vite.config.ts` and `tsconfig.json`).
- UI primitives live in `resources/js/components/ui` (shadcn-vue style, built on `reka-ui`/`radix-vue` + `class-variance-authority`); prefer these over new bare HTML controls.
- Icons via `lucide-vue-next`.
- Vue components must have a single root element (Inertia/Vue 3 requirement already relied on throughout `resources/js/pages`).

### Conventions carried over from AGENTS.md (Laravel Boost guidelines)

- Check sibling files for structure/naming before adding new files; don't create new top-level directories.
- Use PHP 8 constructor promotion, explicit return types/param type hints, curly braces always.
- Prefer Artisan generators (`php artisan make:model|make:test --pest|...`) over hand-rolled files.
- Tests: Pest, feature tests by default (`--unit` for unit tests), use model factories.
- Run `vendor/bin/pint --dirty --format agent` after editing PHP, before calling a change done.
