# Login Page Redesign — Design Spec

**Date:** 2026-05-28
**Status:** Approved (design), pending implementation plan

## Problem

The Breeze-scaffolded `resources/js/Pages/Auth/Login.vue` and its wrapper
`resources/js/Layouts/GuestLayout.vue` still carry the default Tailwind classes
(`bg-gray-100`, `text-green-600`, `sm:rounded-lg`, …). The ernte app ships **no
Tailwind** — it uses a custom CSS token system (`resources/css/tokens.css` +
`resources/css/base.css`). As a result the login page renders essentially
unstyled and off-brand. The goal is a clean, "nice" login page that matches the
ernte monospace/paper aesthetic.

## Scope

Two files, **no backend changes**:

- `resources/js/Pages/Auth/Login.vue` — rebuild with the design system
- `resources/js/Layouts/GuestLayout.vue` — restyle the centered wrapper using
  design tokens (also benefits the other auth pages that share it)

Out of scope: backend auth, the other auth pages' internals (ForgotPassword,
ResetPassword, etc.), restyling the shared Breeze primitive components.

## Design Decisions (from brainstorming)

- **Layout:** minimal centered card on the paper background.
- **Keep:** "remember me" checkbox.
- **Remove:** register link, forgot-password link, theme toggle, the broken
  logo→home link.

## Visual Design

A single centered card on the `--bg` (paper) background:

```
        ╭───────────────────────╮
        │        ernte          │
        │  ───────────────────  │
        │  EMAIL                │
        │  ┌─────────────────┐  │
        │  └─────────────────┘  │
        │  PASSWORD             │
        │  ┌─────────────────┐  │
        │  └─────────────────┘  │
        │  [ ] remember me      │
        │  ┌─────────────────┐  │
        │  │   sign in   →   │  │
        │  └─────────────────┘  │
        ╰───────────────────────╯
```

- **Wordmark:** "ernte" in `--font-mono`, followed by a thin `1px solid
  --border` divider.
- **Fields:** the app's `.field` pattern — a `<label class="field">` containing
  a `<span>` (uppercase `--fs-xs` label) and an `<input class="input">`.
- **Submit:** full-width `<button class="btn primary">` reading `sign in →`.
  Note: `.btn.primary` is **ink-on-paper (dark)**, not forest — this is the
  design-system default and we follow it. Disabled + dimmed while
  `form.processing`.
- **Errors:** session `status` message (when present) and per-field validation
  errors rendered with the existing `.error` class (`--red`, `--fs-xs`).
- **Card chrome (`.auth-card`):** `background: var(--paper)`, `1px solid
  var(--border)`, padding, `max-width: ~360px`, small border-radius, subtle
  shadow. Inputs use `--bg-2` (slightly darker) so they read inside the card.
  These card-specific rules live in a scoped `<style>` block in `Login.vue` to
  stay self-contained.
- **Dark theme:** works automatically — everything resolves through CSS custom
  properties.

## Component / Data Flow

- Keep the Inertia `useForm({ email, password, remember })` logic and the
  `email` / `password` / `remember` field names unchanged.
- Drop the Breeze primitive imports (`TextInput`, `PrimaryButton`, `InputLabel`,
  `InputError`, `Checkbox`) — they are Tailwind-styled. Use native
  `<input>` / `<button>` / `<label>` elements with `base.css` classes, matching
  how the rest of the app builds forms.
- Submit POSTs to the **literal path `/login`** (per the app's literal-path
  convention, not Ziggy `route()`).
- `GuestLayout.vue` becomes a token-based, full-height, centered flex container
  on `--bg`; remove the Tailwind classes and the logo→home `<Link>`.
- The `canResetPassword` prop may remain declared (unused) or be removed; either
  is harmless. The `status` prop is retained.

## Error Handling

Unchanged from Breeze: invalid credentials surface as `form.errors`;
rate-limiting (5 attempts) already lives in `app/Http/Requests/Auth/LoginRequest.php`.

## Testing

- **Existing tests stay green:** `tests/Feature/Auth/AuthenticationTest.php`
  exercises `GET /login` (status 200), `POST /login` with `email`/`password`
  (redirect to `/projects`), invalid-password rejection, and logout. The
  redesign preserves the route, the Inertia component name (`Auth/Login`), and
  the field names, so these continue to pass.
- **Manual verification:** build the frontend, view `/login` in the browser,
  confirm it renders on-brand in both paper and dark themes, and confirm a real
  login succeeds and a bad password shows an error.
