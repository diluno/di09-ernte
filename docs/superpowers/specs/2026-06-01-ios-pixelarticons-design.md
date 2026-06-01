# iOS Companion — pixelarticons icon set — Design

**Date:** 2026-06-01
**Status:** Approved (pending spec review)
**Repos:**
- iOS: `/Users/sam/Documents/_projects/ernte` (target/module `ernte`)
- (No backend changes.)

## Summary

Replace the iOS app's SF Symbols with [pixelarticons](https://pixelarticons.com/)
(MIT-licensed, 24×24 path-based pixel-art icons) so the iconography matches the
app's existing pixel-art leaf logo and JetBrains Mono type. Scope: the 5 tab-bar
icons, the project-task checkbox, and three new icon accents on the Home
dashboard section headers. pixelarticons becomes the app's standard icon set,
accessed through a small `Pixel` name-constant layer.

## Decisions (from brainstorming)

- **Icon mapping:**
  - Tab bar: Home → `home`, Timer → `clock`, Projects → `folder`,
    Billing → `receipt`, Account → `user`.
  - Task checkbox: open → `checkbox`, done → `checkbox-on`.
  - Dashboard section-header accents: Timer → `clock`, Hours & earnings →
    `chart`, Money owed → `wallet`.
- **Integration via the asset catalog**, mirroring the existing `Leaf.imageset`
  (vector-preserving template images), NOT a font or a third-party package.
- **`px-` asset prefix** + a `Pixel` enum of name constants (no raw stringly-typed
  asset names at call sites).
- **Dashboard accents are icon-prefixed headers**, not icons inside each row.
- **Out of scope:** the `Leaf` logo (already pixel-art, untouched), section
  headers on other screens, any backend change, dark mode tuning.

## Assets

Nine SVGs vendored from pixelarticons (available at
`https://unpkg.com/pixelarticons/svg/<name>.svg`):

`home`, `clock`, `folder`, `receipt`, `user`, `checkbox`, `checkbox-on`,
`chart`, `wallet`.

Each becomes an imageset `ernte/Assets.xcassets/px-<name>.imageset/` containing
the SVG plus a `Contents.json` identical in shape to `Leaf.imageset`'s:

```json
{
  "images" : [ { "filename" : "<name>.svg", "idiom" : "universal" } ],
  "info" : { "author" : "xcode", "version" : 1 },
  "properties" : {
    "preserves-vector-representation" : true,
    "template-rendering-intent" : "template"
  }
}
```

The pixelarticons SVGs ship with `fill="currentColor"`; under
`template-rendering-intent: template` the fill is ignored (the shape's coverage
is used and tinted by the view's `foregroundStyle` / the tab bar's tint), so the
SVGs are bundled unmodified.

**Attribution:** add `ernte/Assets.xcassets/Pixelarticons-NOTICE.txt` recording
that these icons are from pixelarticons (© Gerrit Halfmann, MIT License) with the
project URL, satisfying the MIT attribution requirement.

## The `Pixel` layer

`ernte/Support/PixelIcon.swift`:

```swift
import SwiftUI

/// Asset-catalog names for the bundled pixelarticons set. Centralised so a
/// rename or typo is caught in one place rather than scattered string literals.
enum Pixel {
    static let home       = "px-home"
    static let clock      = "px-clock"
    static let folder     = "px-folder"
    static let receipt    = "px-receipt"
    static let user       = "px-user"
    static let checkbox   = "px-checkbox"
    static let checkboxOn = "px-checkbox-on"
    static let chart      = "px-chart"
    static let wallet     = "px-wallet"
}
```

## Edits

### Tab bar — `ernte/ContentView.swift` (`MainTabView`)
Replace each `systemImage:` with `image:` using `Pixel` constants. The five
`.tabItem` labels become:

```swift
.tabItem { Label("Home", image: Pixel.home) }
.tabItem { Label("Timer", image: Pixel.clock) }
.tabItem { Label("Projects", image: Pixel.folder) }
.tabItem { Label("Billing", image: Pixel.receipt) }
.tabItem { Label("Account", image: Pixel.user) }
```
Tags, order, and the `selectedTab` binding are unchanged. The tab bar tints
template images automatically (forest selected via the app `.tint`, grey idle).

### Task checkbox — `ernte/Features/Projects/ProjectDetailView.swift:38`
Replace:
```swift
Image(systemName: task.done ? "checkmark.circle.fill" : "circle")
```
with:
```swift
Image(task.done ? Pixel.checkboxOn : Pixel.checkbox)
    .renderingMode(.template)
    .resizable()
    .frame(width: 18, height: 18)
```
(Sized to sit on the text baseline like the SF Symbol did; keep the surrounding
`foregroundStyle`/layout as-is so the tint still applies.)

### Dashboard headers — `ernte/Features/Home/HomeView.swift`
Add a small private helper and use it for the three section headers:

```swift
@ViewBuilder
private func pixelHeader(_ icon: String, _ title: String) -> some View {
    HStack(spacing: 6) {
        Image(icon)
            .renderingMode(.template)
            .resizable()
            .frame(width: 13, height: 13)
        Text(title)
    }
    .ernteSectionHeader()
}
```
Then the three `header:` closures become:
- Timer: `pixelHeader(Pixel.clock, "Timer")`
- Hours & earnings: `pixelHeader(Pixel.chart, "Hours & earnings")`
- Money owed: `pixelHeader(Pixel.wallet, "Money owed")`

`.ernteSectionHeader()` already styles its content (uppercase, mono, ink3); the
`Image` inherits the same foreground style. If applying `.ernteSectionHeader()`
to an `HStack` (vs `Text`) changes spacing/casing undesirably, fall back to
applying `.font(.ernteCaption).foregroundStyle(Theme.ink3).textCase(.uppercase)`
to the `HStack` directly — same visual result.

## Testing

No iOS UI-test target exists. Verification:
- `xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build` succeeds.
- Manual simulator pass: all five tabs show their pixel icon (correct
  selected/idle tint); the task checkbox toggles between empty and checked
  pixel boxes; the three Home headers show clock / chart / wallet accents.
- A missing or mistyped asset renders blank (no crash); the `Pixel` enum plus
  the build + visual pass are the guard.

## Out of scope

- `Leaf` logo and the Live Activity leaf (already pixel-art).
- Section-header icons on Timer/Projects/Billing/Account screens.
- Replacing JetBrains Mono or any color tokens.
- Backend / API changes.
