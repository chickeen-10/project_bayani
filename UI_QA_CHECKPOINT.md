# Project BAYANI UI QA checkpoint

Updated: 2026-10-28

## Confirmed implementation

- Active renderer: `resources/js/bayani-phase12.js`; active CSS entry: `resources/css/app.css`, importing `phase14.css`.
- Workspace renderer uses a dedicated rail/content grid. Homepage has an editorial hero, visible CTA text, layered secondary panel, and dashboard metric treatments.
- Mobile navigation toggle has `aria-label="Toggle main navigation"`; shared styling gives it a surface and theme-aware icon treatment, and shared focus/disabled states are defined.
- Vite production build passes. Warning: some bundles exceed 500 kB minified; investigate chunk composition/performance rather than suppressing the warning.
- Earlier Playwright captures: `.playwright-mcp/redesign-home-desktop.png`, `.playwright-mcp/redesign-contributor-desktop.png`. Homepage and contributor dashboard at 1440px and homepage at 390px showed no horizontal overflow; homepage CTAs had visible labels.

## Current QA evidence

- `php artisan route:list --except-vendor` reports 106 routes.
- Prior visible-action scan found no visible action without text, `aria-label`, or `title`; this did not establish adequate text contrast.
- Browser session is authenticated as a Contributor. Guest routes redirect to the contributor dashboard. No authorized Administrator test session is established.
- Follow-up contrast check: contributor dashboard primary action is ivory on forest green at opacity 1 in Light Mode. Forcing `data-theme="dark"` did not update saved app theme; Dark Mode was not verified through actual app preference. Screenshot: `.playwright-mcp/contributor-dark-390.png` is only a forced-attribute visual sample.
- `php artisan test`: PASS, 79 tests / 848 assertions.
- `npm test`: PASS, 53 tests. One prior failure was a stale assertion for the pre-redesign hero background; test now verifies the existing layered green treatment and passes.
- `npm run build`: PASS previously and after shared style changes; warning remains for minified chunks >500 kB, primarily PDF/Excel preview dependencies. No warning suppression or dependency change.
- `PROGRESS.md` is 131,993 bytes, invalid UTF-8, no BOM. It was inspected read-only and left unchanged; this file is the UTF-8 continuation checkpoint.

## Route checklist

Status key: VERIFIED means opened and inspected in Playwright; BLOCKED means access requires a role/account/context not available; NOT VERIFIED means no adequate visual QA yet.

| Route/group | Status | Notes |
|---|---|---|
| `/` | VERIFIED (partial) | Desktop and 390px checked; CTA text present, no overflow. Recheck theme and button contrast. |
| `/contributor` | VERIFIED (partial) | Desktop checked; dashboard renders without overflow. Continue mobile/theme and component inspection. |
| Guest routes (`/login`, `/register`, `/repository`, `/about`, `/help`, `/help/feedback`, `/materials/{id}`) | NOT VERIFIED | Need unauthenticated context; sample detail depends on approved record. |
| Contributor upload/edit/submissions/drafts/archive/detail/profile/settings/favorites/notifications/history | NOT VERIFIED | Authenticated contributor context available; inspect route-by-route. |
| Administrator dashboard/review/manage/archive/catalog/users/reports/activity/settings/cleanup | BLOCKED | Requires existing authorized admin session; do not elevate account. |
| Preview/file/thumbnail routes | NOT VERIFIED | Need safe approved test fixture and permitted file operations. |

## Exact next actions

1. Create an isolated unauthenticated Playwright context; the MCP browser provides only one shared tab/context and its current session is authenticated. Do not log out of the user's existing session.
2. Inspect contributor routes using the existing authenticated session and record per-route evidence; do not repeat `/` or `/contributor` beyond regression checks.
3. No authorized Admin account/context is available in the browser inventory. Keep Administrator routes BLOCKED until owner provides an authorized session.
4. Inspect actual theme toggle interaction and theme persistence, then update the route checklist with exact screenshots and responsive results.
5. Final rerun tests and build after any further implementation.

## Contributor route pass (2026-10-28)

- Opened `/contributor/upload`, `/contributor/submissions`, `/contributor/drafts`, `/contributor/archived`, `/demo/profile`, `/demo/settings`, `/favorites`, `/notifications`, `/recently-viewed`, and `/download-history` in the authenticated Contributor browser. Each rendered the expected page title/content, had zero horizontal overflow at 390px, and showed no visible actionable control without text or an accessible label. This is route smoke/overflow QA, not full visual acceptance.
- `/contributor/upload`: inspected actual form controls and required title; did not submit or save to avoid creating/modifying real records.
- `/demo/settings`: used actual Dark radio control and Save preferences action. Dark theme applied immediately and persisted across navigation to `/contributor/upload`. Primary/secondary upload actions remained legible; at 390px page overflow was zero. Original preference was Light; restore it before session handoff if still active.
- Favorites displayed an account-backed saved item. Notifications displayed persisted review feedback. Recently viewed and download history rendered intentional empty states.
- Remaining: inspect route screenshots and interactive controls in detail; restore saved Light preference; Guest routes require a separate unauthenticated context or an explicitly safe logout/login handoff; Admin browser checks remain blocked absent an authorized session. Preview/download routes depend on safe approved file fixtures.

## Confirmed dashboard contrast fix (2026-10-28)

- Reproduced the Total Resources failure on `/contributor`: its card background was forest green (`rgb(36,84,61)`) while label/value remained dark inherited colors (`rgb(95,112,102)` and `rgb(24,57,45)`). Root cause was shared `.stat-card` text tokens winning within the special first metric treatment.
- Updated shared dashboard metric styles in `resources/css/phase14.css` so the highlighted metric label, number, and icon render ivory; Dark Mode uses a matching high-contrast ivory foreground on the lighter green surface.
- Rebuilt Vite assets and cleared compiled Blade views so the running Laravel page loaded current manifest assets. Playwright verified updated foreground `rgb(255,253,245)` on green `rgb(36,84,61)` for label and value after reload. Other neutral metrics retain dark text on sage surfaces.
- Build passed; existing large chunk warning remains. Targeted visual verification is complete for Contributor dashboard metric in Light Mode; Admin metric rendering remains browser BLOCKED pending authorized Admin session.

## Global button visibility repair (2026-10-29)

- Reproduced `/contributor/upload` “Save draft” issue: the label existed in the DOM and was readable at rest, but hover rendered foreground and background both `rgb(36, 84, 61)`. `.btn.btn-outline-primary` pinned its normal foreground while Bootstrap changed the hover background. The homepage secondary CTA had a later, higher-specificity hero rule that also overrode the shared state.
- Fixed the shared outline-primary foreground/background pair for hover, focus-visible, and active states in `resources/css/app.css`; constrained the homepage secondary CTA treatment in `resources/css/phase14.css` to non-interactive states. No form geometry or layout changed.
- Added `tests/js/button-contrast.test.js` for theme token contrast and state-rule regression; updated the prior highlighted-KPI expectation in `tests/js/production-render.test.js` to use semantic tokens.
- Playwright confirmed the upload label in Light and Dark Mode, default/hover/focus, and a temporary disabled state; no submit action was triggered. Also checked hover on primary, outline-primary, light, outline-secondary, and outline-danger variants in both themes. No visible unlabeled controls were found on the checked routes.
- Button-route sweep opened `/`, `/repository`, `/search`, `/about`, `/help`, `/help/feedback`, `/contributor`, `/contributor/upload`, `/contributor/submissions`, `/contributor/drafts`, `/contributor/archived`, `/contributor/submissions/8`, `/favorites`, `/notifications`, `/recently-viewed`, `/download-history`, `/demo/profile`, `/demo/settings`, `/materials/8`, and `/materials/8/preview`; no horizontal overflow was detected at the current desktop viewport. Screenshots: `.playwright-mcp/button-bug-upload-light-after.png`, `.playwright-mcp/button-bug-upload-dark-after.png`, `.playwright-mcp/button-bug-home-light-after.png`, `.playwright-mcp/button-bug-home-after-dark.png`.
- `/login` and `/register` redirected to the authenticated Contributor dashboard; guest-only navigation remains NOT VERIFIED. `/admin` returned 403 and Admin routes remain BLOCKED without an authorized account. `/contributor/submissions/8/edit` returned 403; `/profile` is not a GET page (405).
- Verification: Laravel 79 tests / 848 assertions PASS; JavaScript 55 tests PASS; Vite build PASS with the existing >500 kB chunk warning. The actual application theme was restored to saved Light Mode after testing. Button press/active interaction was not triggered because it would submit the form; active styles are covered by the CSS regression check.
