# Deep Verification Report — Sambandscentralen

**Date**: 2026-02-07
**Stack**: Next.js 16.1.6 · React 19.2.4 · TypeScript 5.9.3 · better-sqlite3 11.10.0 · Leaflet 1.9.4

## Verification Summary

| Check                     | Result |
|---------------------------|--------|
| Clean install (npm ci)    | PASS — 0 vulnerabilities, 709 packages |
| ESLint                    | PASS — 0 errors, 0 warnings |
| TypeScript (--noEmit)     | PASS — 0 errors |
| Tests (28 tests / 2 suites) | PASS — all green |
| Production build          | PASS — compiles in 4.7s |
| Production server         | PASS — all routes respond correctly |
| npm audit                 | PASS — 0 vulnerabilities |

---

## BLOCKERS — must fix before production

### B1. Hydration mismatch: `new Date()` rendered during SSR

**File**: `src/components/OperationalDashboard.tsx:89`
```tsx
Updated: {new Date().toLocaleString('sv-SE')}
```
`new Date()` executes at different times on server vs client. This **will** produce a React hydration mismatch warning on every page load of `/stats`. The server-rendered timestamp will differ from the client hydration pass.

**Fix**: Move the timestamp into a `useEffect` / `useState` pattern, or render it only on the client.

---

### B2. `EventSkeleton` component is dead code — never imported

**File**: `src/components/EventSkeleton.tsx`

`EventSkeleton` is exported but never imported anywhere in the codebase. It ships in the bundle as dead weight. This is a minor issue on its own, but signals that the loading skeleton UX path is broken — no component renders a skeleton during data loading. If the skeleton was intended as a loading state for events, it's not wired up.

**Impact**: Missing loading UX; dead code in bundle.

---

### B3. `react-leaflet` is an unused production dependency

**Package**: `react-leaflet@5.0.0` (+ `@react-leaflet/core@3.0.0`)

`react-leaflet` is declared in `dependencies` and `transpilePackages`, but **zero imports** exist anywhere in the source code. All map code uses raw Leaflet via `import('leaflet')`. This adds unnecessary package weight and an unnecessary React 19 compatibility surface. react-leaflet 5.0.0 was designed for React 18; while it installs cleanly with React 19, it is untested baggage.

**Fix**: Remove from `package.json` dependencies and from `transpilePackages` in `next.config.js`.

---

### B4. CSP allows `'unsafe-eval'` and `'unsafe-inline'` for scripts

**File**: `next.config.js:21`
```js
"script-src 'self' 'unsafe-eval' 'unsafe-inline'"
```
This effectively disables the script CSP. `unsafe-eval` allows `eval()` execution and `unsafe-inline` allows inline script injection — both defeat the purpose of a Content-Security-Policy. If an XSS vector exists, CSP provides no defense.

**Fix**: Remove `'unsafe-eval'`. For `'unsafe-inline'`, use nonce-based script loading if needed by Next.js. Next.js 16 supports CSP nonces via `next.config.js` experimental settings.

---

## RISKS — could cause instability later

### R1. Hydration risk: locale-dependent formatting in client components

**Files**: `src/components/StatsView.tsx` (lines 30, 165, 197), `src/components/OperationalDashboard.tsx` (multiple)

`toLocaleString('sv-SE')` and `toLocaleDateString('sv-SE')` produce output that depends on the runtime's ICU data. If the server Node.js environment has different locale data than the browser, numbers/dates will render differently, causing hydration warnings.

**Mitigation**: These are in client-only components (StatsView renders only when `isActive`, OperationalDashboard is `force-dynamic`), so the risk is low but not zero. The `/stats` page is especially vulnerable because `force-dynamic` means full server rendering on every request.

---

### R2. `formatRelativeTime` runs on server with `new Date()` — stale relative times

**File**: `src/lib/utils.ts:25` called from `src/app/page.tsx:55`

`formatEventForUi` creates `new Date()` and computes relative time strings ("5 min sedan") on the server. These are baked into the initial HTML. Because the page has `revalidate = 1800` (30 min), relative times can be up to 30 minutes stale. A user seeing "Just nu" may be viewing an event that happened 30 minutes ago.

**Impact**: Misleading freshness indicators.

---

### R3. `getFilterOptions` uses string interpolation in SQL

**File**: `src/lib/db.ts:384`
```ts
`SELECT DISTINCT ${column} AS value FROM events WHERE ${column} != '' ORDER BY ${column} ASC`
```
While `column` is typed as `'location_name' | 'type'` (a union literal), the value comes from internal callers only. If this function were ever exposed to user input or refactored with a wider type, it would be an SQL injection vector. Parameterized queries cannot be used for column names in SQLite, but a safelist check at runtime would eliminate the risk.

---

### R4. In-memory rate limiter doesn't survive restarts and leaks across cold starts

**File**: `src/lib/rateLimit.ts`

The rate limiter uses a `Map` stored in module scope. In serverless/edge deployments (Vercel, etc.), each cold start gets a fresh Map, meaning rate limits reset constantly. The `setInterval` cleanup also creates a persistent timer that can keep the Node process alive during graceful shutdown.

**Impact**: Rate limiting is ineffective in serverless environments; potential process leak.

---

### R5. Service Worker caches aggressively with no version invalidation strategy

**File**: `public/sw.js`

The SW uses cache name `samband-v1` and caches all successful 200 responses. There's no mechanism to bust the cache version on deployment. Users could be served stale HTML/JS after a deploy until they hard-refresh or the SW is updated. The `STATIC_ASSETS` array references `/icons/icon.svg`, which exists, but any path additions won't be pre-cached.

---

### R6. Test coverage is minimal — only utility functions tested

**Files**: `src/__tests__/utils.test.ts`, `src/__tests__/htmlEntities.test.ts`

Only 28 tests exist, covering only:
- `formatRelativeTime` (4 tests)
- `sanitizeInput/Location/Type/Search` (8 tests)
- `decodeHtmlEntities` (14 tests, duplicated implementation rather than importing)

**Not tested**:
- Zero component rendering tests
- Zero API route tests
- Zero database operation tests (`db.ts` — 701 lines, completely untested)
- `policeApi.ts` — fetch logic, retry, URL validation
- `escapeLikeWildcards` — not tested
- `formatEventForUi` — not tested
- Edge cases in `extractEventTime` — complex date parsing, untested

The `htmlEntities.test.ts` file duplicates the `decodeHtmlEntities` function rather than importing it from `policeApi.ts`, meaning the test doesn't actually validate the production code. If the production implementation diverges, tests will still pass.

---

### R7. `deprecated` npm warnings for transitive dependencies

`npm ci` reports deprecation warnings for:
- `whatwg-encoding@3.1.1` — memory leak potential
- `inflight@1.0.6` — known memory leak, no longer supported
- `glob@7.2.3` and `glob@10.5.0` — security vulnerabilities in old versions

These are transitive dependencies (likely from `jest-environment-jsdom`). Not a production runtime risk since they're dev-only, but could affect CI stability.

---

## OPTIMIZATIONS — worthwhile improvements

### O1. Dynamic import for `OperationalDashboard` and `StatsView`

Both components are loaded in the initial bundle but only visible when their respective views are active. Using `next/dynamic` with `{ ssr: false }` for OperationalDashboard (which has the hydration risk from B1) and lazy loading StatsView would reduce the initial JS payload.

Current largest chunks: 224KB, 156KB, 148KB, 112KB (uncompressed). Moving StatsView and the operational dashboard to dynamic imports would reduce initial load.

---

### O2. Remove `react-leaflet` to shrink bundle

Removing the unused `react-leaflet` dependency eliminates `@react-leaflet/core` and `react-leaflet` from the bundle entirely. Since all map code uses raw Leaflet dynamic imports, this is free bundle savings with zero code changes needed (beyond `package.json` and `next.config.js`).

---

### O3. Memoize `OperationalDashboard` bar chart max calculations

**File**: `src/components/OperationalDashboard.tsx:142, 293, 316`

`Math.max(...array)` is recomputed inside `.map()` on every render iteration:
```tsx
{operationalStats.hourlyFetches.map((count, hour) => {
  const maxCount = Math.max(...operationalStats.hourlyFetches, 1);
```
This recomputes max 24 times per chart. Hoist to a variable outside the map.

---

### O4. `EventList` auto-refresh `useEffect` has unstable dependency: `events`

**File**: `src/components/EventList.tsx:109`
```ts
}, [currentView, filters, events]);
```
The `events` array is a state variable that changes on every fetch, causing the effect to tear down and recreate the interval and visibility listener on every data update. This doesn't cause bugs but creates unnecessary listener churn.

**Fix**: Use a ref for events inside the effect instead of including it as a dependency.

---

### O5. `escapeHtml` in EventMap could be a module-level function

**File**: `src/components/EventMap.tsx:24-31`

`escapeHtml` is wrapped in `useCallback` but has zero dependencies — it's a pure function. It's re-created only when the component remounts, so the impact is negligible, but it would be cleaner as a plain module-level function.

---

### O6. `htmlEntities.test.ts` should import from production code

The test file duplicates the `decodeHtmlEntities` function and `HTML_ENTITIES` map (36 lines). It should import from `src/lib/policeApi.ts` instead. The comment says "This avoids issues with better-sqlite3 native module" — this can be solved by mocking `better-sqlite3` in jest.setup.js (similar to how Leaflet is already mocked) rather than duplicating production code.

---

### O7. Footer shows inaccurate "Visar X av Y" count

**File**: `src/components/Footer.tsx:17`
```tsx
Visar: {shown} av {total}
```
`shown` is `initialEvents.length` (page 1 = up to 40 events) and `total` is the grand total of all events. After loading more via the "Ladda fler" button, the footer still shows the initial count because `shown` is passed as a static prop from the server. The count never updates client-side.

---
