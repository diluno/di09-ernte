# iOS Companion — "Paper" Theme (match the web look) — Design

**Date:** 2026-06-01
**Status:** Approved (pending spec review)
**Repo:** `/Users/sam/Documents/_projects/ernte` (target/module `ernte`)

## Summary

Restyle the SwiftUI companion app to match the ernte web app's distinctive visual
identity — a warm "paper" palette, JetBrains Mono typography, a forest-green accent,
and flat, terminal-like density — while keeping native SwiftUI list/navigation
structure (i.e. adopt the *look*, not a full custom-drawn UI).

## Decisions (from brainstorming)

- **Fidelity:** Palette + monospace font applied on top of native SwiftUI lists/nav
  ("Approach A"), not a full custom skin.
- **Font:** Bundle **JetBrains Mono** (Regular/Medium/Bold) — exact match to the web.
- **Theme:** **Paper only** (always light) via `.preferredColorScheme(.light)` —
  ignore system dark mode for now.
- **Rollout:** One plan covering all five screens.

## Design tokens (ported from the web `tokens.css`, paper theme)

| Token | Hex | Use |
|---|---|---|
| paper | `#f5f1ea` | primary background |
| paper2 | `#efe9dc` | secondary surfaces / grouped bg |
| ink | `#1a1a1a` | primary text |
| ink2 | `#3d3d3d` | strong secondary text |
| ink3 | `#6b6b6b` | section headers, captions |
| ink4 | `#9a9a9a` | faint text |
| border | `#e8e1d4` | hairline dividers |
| borderStrong | `#c9c0ad` | stronger borders |
| **forest** | `#2d4a3a` | **accent** (tint, buttons, links) |
| rust | `#c97b3c` | (reserved) |
| red | `#b54834` | over-budget, overdue/expired, errors |
| gold | `#b8941f` | warn band |

Type sizes (web): xs 11, sm 13 (body), md 15, lg 24, xl 36.
Band mapping: `ok → ink3`, `warn → gold`, `over → red`.

## Components

### `Support/Theme.swift`
- `Color(hex:)` initializer.
- `Theme` enum with the color constants above (static `Color`s).
- `Font.ernte(_ size: CGFloat, _ weight: ErnteWeight = .regular)` → `Font.custom`
  on the JetBrains Mono PostScript names; semantic helpers `ernteBody` (13),
  `ernteCaption` (11), `ernteTitle` (24), `ernteLargeTitle` (36).
- `Theme.band(_ band: String) -> Color`.

### `Support/Appearance.swift`
- `Appearance.configure()` — sets UIKit appearance proxies once at launch:
  - `UINavigationBarAppearance`: paper background (no blur), large + inline title
    text attributes using JetBrains Mono in `ink`.
  - `UITabBarAppearance`: paper background.
  - Global tint handled in SwiftUI (`.tint(Theme.forest)`).
- Called from `ernteApp.init()`.

### Fonts
- Add `JetBrainsMono-Regular.ttf`, `-Medium.ttf`, `-Bold.ttf` under a `Fonts/` group,
  registered in `Info.plist` `UIAppFonts`. (OFL-licensed; free to bundle.)
- Referenced by PostScript name (`JetBrainsMono-Regular`, etc.).

## Applying it

- `RootView`: `.preferredColorScheme(.light)` + `.tint(Theme.forest)`.
- Lists: `.scrollContentBackground(.hidden)` + `Theme.paper` background; row
  backgrounds paper/paper2; uppercase mono `ink3` section headers (e.g. "THIS WEEK",
  "ACTIVE PROJECTS").
- Re-skin every screen to `Theme` colors + `.ernte*` fonts:
  - **Login** — paper form, mono fields, forest "Sign In".
  - **Timer** — running-elapsed stays monospaced (now JetBrains Mono); start/stop
    buttons forest; today totals mono.
  - **Projects** list + detail — band colors via `Theme.band`; mono rows; mono headers.
  - **Billing** list + detail — OVERDUE/EXPIRED in `red`; totals mono; segmented
    control tinted forest.
  - **Account** — paper, mono, forest sign-out (destructive stays red).
- Structure stays native (NavigationStack + List); only color, font, and header
  styling change.

## Out of scope

- Dark theme (paper-only for now).
- Custom-drawn cards/borders, pixel-art icons, full web-identical density
  (that was the rejected "full skin" option).
- Sparkline/heatmap visual components (data already returned; rendering them is a
  later enhancement, not part of this restyle).

## Testing

- Visual: build + run, verify each screen reads correctly in the paper theme with
  mono type and forest accent (collaborative — user builds in Xcode).
- Unit: a small test for `Color(hex:)` parsing (3/6-digit hex → expected RGBA).

## Collaboration / build model

Same as prior iOS slices: Claude authors + commits Swift and Info.plist edits; the
user adds new files (incl. the three font `.ttf`s) to the Xcode target and builds.
JetBrains Mono download link provided in the plan.
