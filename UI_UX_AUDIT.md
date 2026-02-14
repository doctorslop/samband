# UI/UX Audit: Sambandscentralen

**Date:** 2026-02-14
**Scope:** Full audit of globals.css (3,668 lines), 13 React components, layout, and pages

---

## Summary

Sambandscentralen is a well-built dark-themed Next.js PWA for real-time Swedish police events with three views (List, Map, Statistics). The glassmorphism aesthetic is cohesive and the component architecture is sound. This audit identifies concrete issues across six categories, prioritized by user impact.

---

## P0 — Critical (Broken or Misleading)

### 1. Error boundary uses undefined CSS variables
**Files:** `globals.css:3619-3635`

The error boundary references `--glass`, `--text-primary`, and `--text-secondary` which do not exist in `:root`. The defined variables are `--glass-bg`, `--text`, and `--text-muted`. When the error boundary renders, its background and text colors fall back to browser defaults — producing a white/unstyled box against a dark page.

**Fix:** Replace the three undefined variables:
```css
/* Before */
background: var(--glass);
color: var(--text-primary);
color: var(--text-secondary);

/* After */
background: var(--glass-bg);
color: var(--text);
color: var(--text-muted);
```

### 2. No expand affordance on event cards
**Files:** `EventCard.tsx:240-252`, `globals.css:389-456`

The entire card header is a click/tap target for expanding details, but there is no chevron, arrow, or any visual indicator that the card is expandable. The "Läs mer" button exists lower in the card, but users scanning the feed have no reason to try clicking the card itself. The `cursor: pointer` on `.event-card` (line 398) helps on desktop but is invisible on touch devices.

**Fix:** Add a chevron icon to the card header that rotates on expand. Place it on the right side of `.event-card-header` with a CSS transition: `transform: rotate(180deg)` when `.expanded`.

### 3. Custom location filter has no submit path
**Files:** `Filters.tsx:193-214`

When a user selects "Annan plats..." from the location dropdown, the dropdown is replaced with a text input. Unlike the search field (which has debounced auto-submit), this custom location input does nothing until the user clicks the main "Filtrera" button. There is no enter-key handler or auto-submit. Users typing a custom location and hitting Enter will submit the form (which works), but users who type and wait will see nothing happen.

**Fix:** Either debounce the custom location input the same way search is debounced, or add an explicit submit button next to the cancel button.

---

## P1 — High (Visual/Interaction Issues Affecting Usability)

### 4. Breakpoint conflict between 769px and 1024px
**Files:** `globals.css:77-104, 197-200, 1851-1864`

Multiple conflicting rules apply between 769px and 1024px:
- `min-width: 769px` (line 197): Shows labels only on the active view-toggle button
- `max-width: 1024px` (line 1863): Hides ALL view-toggle labels with `display: none`

The `max-width: 1024px` rule has higher specificity (appears later) so it wins, making the `min-width: 769px` rule dead code in this range. Additionally, the desktop header compact/collapse scroll behavior activates at 769px+ but the header's own padding is overridden at 1024px, creating a visual disconnect.

**Fix:** Consolidate into a consistent breakpoint system. Use three tiers:
- Mobile: `max-width: 768px`
- Tablet: `769px - 1199px`
- Desktop: `min-width: 1200px`

Remove the stray 900px and 1024px breakpoints by merging them into the nearest tier.

### 5. Settings panel lacks context labels
**Files:** `Header.tsx:203-247`, `globals.css:2673-2756`

The settings dropdown shows three density buttons (Bekväm / Kompakt / Flöde) with no label explaining they control layout density. Below a thin divider, "Expandera notiser" with a toggle switch appears. A new user has no way to know what these Swedish terms mean in context without trying each one.

**Fix:** Add a small label above each settings group:
```
Visningsläge          ← add this
[Bekväm] [Kompakt] [Flöde]
───────────
Expandera notiser  [toggle]
```

### 6. Muted text color fails WCAG AA at small sizes
**Files:** `globals.css:16`

`--text-muted: #94a3b8` on the darkest backgrounds (`--primary: #0a1628`) yields a contrast ratio of ~5.5:1, which passes WCAG AA for normal text (4.5:1) but fails the enhanced criterion AAA (7:1). However, at the font sizes used in the app (10-12px in meta rows, stat labels, timestamps), this color renders as barely legible on typical mobile screens.

More critically, the meta separator `rgba(255, 255, 255, 0.15)` (line 498) yields a contrast ratio of ~1.4:1 — functionally invisible.

**Fix:**
- Bump `--text-muted` to `#a0b0c4` (~6.2:1 ratio) for better legibility at small sizes
- Change meta separators to `rgba(255, 255, 255, 0.3)` minimum for a ~2.5:1 ratio, or better yet use `var(--text-muted)` at reduced opacity

### 7. Timeline controls overflow on small phones
**Files:** `globals.css:1186-1231`

On phones narrower than ~380px, the map timeline controls contain 7 distinct elements (play button + slider + time label + counter + 3 range buttons). With `flex-wrap: wrap` enabled on mobile, these wrap into two rows, consuming ~80px of vertical space — roughly 15% of the viewport on a landscape phone.

**Fix:** On mobile, collapse the range selector into a single button that cycles through 24h → 48h → 72h on tap. This eliminates three buttons from the row and prevents wrapping.

### 8. Inconsistent unit system in error boundary
**Files:** `globals.css:3607-3668`

The error boundary CSS uses `rem` units (`2rem`, `3rem`, `0.75rem`, etc.) while every other component uses `px`. Since the app doesn't set an explicit `html { font-size }`, this works by accident, but any future base-size change would cause the error boundary to scale differently from everything else.

**Fix:** Convert all `rem` values to `px` to match the rest of the codebase: `2rem` → `32px`, `3rem` → `48px`, `0.75rem` → `12px`, `1.5rem` → `24px`, `0.6rem` → `10px`.

---

## P2 — Medium (Polish & Consistency)

### 9. Duplicated base styles for action buttons
**Files:** `globals.css:597-708`

`.show-map-link` and `.expand-details-btn` share a base declaration block (lines 597-621), but `.share-event-btn` (lines 667-708) copy-pastes all 16 identical properties. Any change to the shared base (font-size, padding, border-radius) must be made in two places.

**Fix:** Extract the shared properties into a common class (e.g., `.event-action-btn`) and compose each button variant on top of it:
```css
.event-action-btn { /* shared base */ }
.event-action-btn--map { color: #60a5fa; ... }
.event-action-btn--expand { color: var(--accent); ... }
.event-action-btn--share { color: var(--success); ... }
```

### 10. Event card header padding mixes hardcoded and variable values
**Files:** `globals.css:428, 1910, 2815, 2919, 2969, 2994`

Desktop uses `padding: 16px 18px` (hardcoded), mobile uses `padding: 14px var(--space-card)` (variable), compact desktop uses `padding: 10px 14px` (hardcoded), compact mobile uses `padding: 8px var(--space-card)` (variable). This mix means density changes through CSS variables only partially apply.

**Fix:** Define card padding via CSS variables throughout:
```css
.event-card-header {
    padding: var(--card-padding-y) var(--card-padding-x);
}
```
Set `--card-padding-y` and `--card-padding-x` at each breakpoint/density combination.

### 11. No "all loaded" indicator for pagination
**Files:** `EventList.tsx:264-281`, `globals.css:1756-1758`

When all events are loaded, the "Ladda fler" button simply disappears (`display: none` via `.hidden`). Users cannot tell whether loading failed, more events exist but aren't showing, or all events have been loaded.

**Fix:** Replace the hidden button with a subtle "Alla händelser visas" message when `!hasMore && events.length > 0`.

### 12. Header negative-margin bleed is fragile
**Files:** `globals.css:70-72`

The header uses `margin: 0 calc(-1 * var(--space-page))` and `width: calc(100% + var(--space-page) * 2)` to bleed full-width while the container has padding. This breaks if the container ever has `overflow: hidden` and doesn't account for scrollbar width (the header is 17px wider than the viewport on OS's with visible scrollbars).

**Fix:** Move the header outside the `.container` element, or use `width: 100vw; margin-left: calc(-50vw + 50%);` which handles scrollbar offset. Better yet, restructure so the header is a sibling of `.container` rather than a child.

### 13. Stats view visual hierarchy is flat
**Files:** `StatsView.tsx:27-66`, `globals.css:1268-1320`

All nine hero metrics use identical card styling with the same padding, border-radius, and visual weight. The "Totalt antal händelser" card gets `grid-column: 1 / -1` on mobile (full-width) but on desktop it shrinks to a single column cell, losing its visual prominence. The primary metric should remain clearly dominant at all sizes.

**Fix:** Give `.stats-metric--primary` a larger font size, distinct background gradient, and keep it spanning across at least 2 columns at all breakpoints above mobile.

### 14. Map modal height is not responsive
**Files:** `globals.css:745, 733`

`.map-modal-body` has a fixed `height: 400px`. On landscape phones (e.g., 667×375 viewport), the modal's `max-height: 90vh` constrains the outer shell to ~337px, but the inner body still wants 400px, causing overflow. The body should use a flexible height.

**Fix:** Change to `height: clamp(250px, 50vh, 500px)` or use `flex: 1` within a flexbox modal layout.

### 15. `display: contents` on filter-selects-row
**Files:** `globals.css:219, 1898`

On desktop, `.filter-selects-row { display: contents }` makes the selects participate in the parent flex layout. On mobile, it switches to `display: flex`. The `display: contents` approach breaks the DOM containment model — the selects lose their parent for focus management and screen readers may not announce them as a group.

**Fix:** Remove `display: contents` and use a consistent `display: flex` with `flex-wrap: wrap` at all breakpoints. Adjust the parent `.search-form` layout accordingly.

---

## P3 — Low (Minor Polish)

### 16. Inconsistent BEM naming
**Files:** `globals.css` throughout

The CSS mixes naming conventions:
- BEM: `.stream-item__content`, `.stats-metric__value`, `.top-list__item--clickable`
- Flat: `.event-card`, `.event-type`, `.filter-tag`
- Concatenated: `.header-compact`, `.header-collapsed`, `.map-loading`

This isn't a user-facing issue but increases maintenance friction.

**Fix:** Adopt BEM consistently for new CSS. Migrate existing selectors incrementally. No immediate action needed.

### 17. Leaflet CSS loaded from external CDN
**Files:** `layout.tsx:45-49`

Leaflet CSS is loaded from `unpkg.com`. If the CDN is slow or blocked (corporate firewalls, China, etc.), the map renders with broken layout until styles arrive. The integrity hash ensures security but not availability.

**Fix:** Bundle Leaflet CSS locally via `import 'leaflet/dist/leaflet.css'` in the map component, or copy it to the `/public` directory.

### 18. `prefers-reduced-motion` uses near-zero duration instead of none
**Files:** `globals.css:2003`

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
```

Using `0.01ms` still briefly triggers the animation (a single frame flash). Users who enable reduced motion typically want `animation: none`.

**Fix:** Use `animation: none !important; transition: none !important;` or at minimum add `animation-iteration-count: 1 !important;` to prevent looping pulse effects.

### 19. Footer is underutilized
**Files:** `Footer.tsx:1-21`, `globals.css:1760-1808`

The footer only shows "Senast uppdaterad: HH:MM" with a pulsing dot. This is a minimal amount of content for a sticky bottom element. There is no data source attribution, no keyboard shortcut reference, and no link back to the police data source.

**Fix:** Add secondary links (data source: Polisen.se, keyboard shortcuts help, project info) to the footer. This also improves SEO and provides useful context without cluttering the main UI.

### 20. Settings panel horizontal position drifts across breakpoints
**Files:** `globals.css:2654-2655`

The panel uses `right: var(--space-page)` which changes from `40px` → `24px` → `12px` → `8px` across breakpoints. While the vertical position is dynamically computed via JS, the horizontal position is purely CSS-driven. On a 768px viewport where `--space-page` is `12px`, the panel's right edge nearly touches the viewport edge.

**Fix:** Position the panel relative to the `.settings-wrapper` element instead of using `position: fixed`. Use `position: absolute` with `right: 0; top: 100%;` on the wrapper, and add `margin-top: 8px` for spacing.

### 21. No skeleton loading state
**Files:** `page.tsx:82-94`

The Suspense fallback is a bare spinner centered in the viewport. Users see a blank dark page with a spinning circle, getting no indication of what content structure to expect.

**Fix:** Create a lightweight skeleton component that mimics the header + 3-4 card placeholders with pulsing backgrounds. This reduces perceived load time.

### 22. View toggle labels have redundant hide/show rules
**Files:** `globals.css:197-200, 1863, 1895, 1947`

The `.view-toggle button span.label` visibility is controlled by four separate media queries:
- `min-width: 769px`: hide inactive labels, show active
- `max-width: 1024px`: hide all labels
- `max-width: 768px`: hide all labels (redundant with 1024px rule)

The 769px rule is completely overridden by the 1024px rule and never takes effect.

**Fix:** Remove the dead `min-width: 769px` label rule (lines 197-200). Labels should either be always-hidden (icon-only tabs) or visible above a specific width threshold.

---

## Responsiveness Summary

| Viewport | Status | Notes |
|----------|--------|-------|
| < 360px | Adequate | Tight but functional, extra-small overrides exist |
| 360-768px | Good | Well-handled mobile styles, safe-area support |
| 769-900px | Fair | Stray breakpoint at 900px, subtitle disappears abruptly |
| 901-1024px | Weak | Another stray breakpoint, conflicting label visibility |
| 1025-1439px | Good | Clean single-column layout |
| 1440-1919px | Good | 2-col event grid activates |
| 1920-2559px | Good | 3-col events, wider stats |
| 2560px+ | Adequate | 4-col events, but text lines may be too wide |
| Landscape phone | Fair | Map max-height handled, but timeline wraps |

---

## Prioritized Action Plan

| Priority | Issue # | Effort | Impact |
|----------|---------|--------|--------|
| P0 | #1 Error boundary variables | ~5 min | Broken error display |
| P0 | #2 Card expand affordance | ~30 min | Primary interaction discoverability |
| P0 | #3 Custom location submit | ~20 min | Broken filter workflow |
| P1 | #4 Breakpoint consolidation | ~2h | Eliminates dead/conflicting CSS |
| P1 | #5 Settings labels | ~15 min | Feature discoverability |
| P1 | #6 Color contrast | ~15 min | Accessibility compliance |
| P1 | #7 Timeline overflow | ~45 min | Mobile map usability |
| P1 | #8 Error boundary units | ~10 min | Consistency |
| P2 | #9 Button style dedup | ~20 min | Maintainability |
| P2 | #10 Padding consistency | ~30 min | Density system reliability |
| P2 | #11 All-loaded indicator | ~15 min | User feedback |
| P2 | #12 Header bleed fix | ~30 min | Layout robustness |
| P2 | #13 Stats hierarchy | ~30 min | Data readability |
| P2 | #14 Modal responsive height | ~15 min | Mobile modal usability |
| P2 | #15 display:contents removal | ~20 min | Accessibility |
| P3 | #16-22 Polish items | ~2-3h | Long-term quality |
