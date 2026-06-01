# iOS pixelarticons Icon Set Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the iOS app's SF Symbols (5 tab-bar icons + the project-task checkbox) with pixelarticons, and add pixel-icon accents to the three Home dashboard section headers, matching the app's pixel-art aesthetic.

**Architecture:** Nine pixelarticons SVGs are vendored into the asset catalog as vector-preserving template imagesets (mirroring the existing `Leaf.imageset`). A `Pixel` enum centralises the asset names. Call sites swap `systemImage:`/`Image(systemName:)` for the bundled `Image(Pixel.x)`.

**Tech Stack:** Swift / SwiftUI, Xcode asset catalog. iOS repo only: `/Users/sam/Documents/_projects/ernte` (module `ernte`). No backend changes.

**Notes for the implementer:**
- The Xcode project uses **file-system-synchronized groups** — new files under `ernte/...` (including `.imageset` folders in `Assets.xcassets`) are picked up automatically. **Do not edit `project.pbxproj`.**
- No iOS unit-test target for UI, so verification per task is "build succeeds" + (final task) a manual simulator pass. A malformed asset `Contents.json` **fails the build**, so the build is a real guard for Task 1.
- Build command (substitute an available iPhone sim if 17 is missing — `xcrun simctl list devices available`):
  `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build -quiet`
- Work on branch `feat/ios-pixelarticons` (created in Task 0).

---

## Task 0: Branch

**Files:** none.

- [ ] **Step 1: Create the feature branch**

```bash
cd /Users/sam/Documents/_projects/ernte
git checkout main
git checkout -b feat/ios-pixelarticons
git branch --show-current   # expect: feat/ios-pixelarticons
```

---

## Task 1: Vendor the 9 icons + `Pixel` layer

**Files:**
- Create: `ernte/Assets.xcassets/px-home.imageset/{Contents.json, home.svg}`
- Create: `ernte/Assets.xcassets/px-clock.imageset/{Contents.json, clock.svg}`
- Create: `ernte/Assets.xcassets/px-folder.imageset/{Contents.json, folder.svg}`
- Create: `ernte/Assets.xcassets/px-receipt.imageset/{Contents.json, receipt.svg}`
- Create: `ernte/Assets.xcassets/px-user.imageset/{Contents.json, user.svg}`
- Create: `ernte/Assets.xcassets/px-checkbox.imageset/{Contents.json, checkbox.svg}`
- Create: `ernte/Assets.xcassets/px-checkbox-on.imageset/{Contents.json, checkbox-on.svg}`
- Create: `ernte/Assets.xcassets/px-chart.imageset/{Contents.json, chart.svg}`
- Create: `ernte/Assets.xcassets/px-wallet.imageset/{Contents.json, wallet.svg}`
- Create: `ernte/Support/PixelIcon.swift`
- Create: `ernte/Assets.xcassets/Pixelarticons-NOTICE.txt`

- [ ] **Step 1: Create each imageset's `Contents.json`**

For every imageset, the `Contents.json` is identical except for the `filename`. Use this template, replacing `<name>.svg` with the matching SVG filename (e.g. `home.svg`, `checkbox-on.svg`):

```json
{
  "images" : [
    {
      "filename" : "<name>.svg",
      "idiom" : "universal"
    }
  ],
  "info" : {
    "author" : "xcode",
    "version" : 1
  },
  "properties" : {
    "preserves-vector-representation" : true,
    "template-rendering-intent" : "template"
  }
}
```

So: `px-home.imageset/Contents.json` has `"filename" : "home.svg"`, `px-checkbox-on.imageset/Contents.json` has `"filename" : "checkbox-on.svg"`, etc.

- [ ] **Step 2: Create each SVG file** (verbatim — these are the exact pixelarticons sources)

`px-home.imageset/home.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M4 20h16v2H4zm16-10h2v10h-2zM2 10h2v10H2zm2-2h2v2H4zm2-2h2v2H6zm2-2h2v2H8zm2-2h4v2h-4zm4 2h2v2h-2zm2 2h2v2h-2zm2 2h2v2h-2zM8 14h2v6H8zm2-2h4v2h-4zm4 2h2v6h-2z"/>
</svg>
```

`px-clock.imageset/clock.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M6 2h12v2H6zM2 6h2v12H2zm18 0h2v12h-2zm-2-2h2v2h-2zM4 4h2v2H4zm2 18h12v-2H6zm12-2h2v-2h-2zM4 20h2v-2H4zm7-14h2v7h-2zm2 7h2v2h-2zm2 2h2v2h-2z"/>
</svg>
```

`px-folder.imageset/folder.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M4 4h6v2H4zm0 14h16v2H4zM20 8h2v10h-2zM2 6h2v12H2zm8 0h10v2H10z"/>
</svg>
```

`px-receipt.imageset/receipt.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M3 2h2v18H3zm16 0h2v18h-2zM5 4h2v2H5zm4 0h2v2H9zM5 20h14v2H5zm8-16h2v2h-2zM7 2h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2zm2 2h2v2h-2zM7 8h10v2H7zm0 4h10v2H7zm0 4h4v2H7z"/>
</svg>
```

`px-user.imageset/user.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M9 2h6v2H9zm0 8h6v2H9zm6-6h2v6h-2zM7 4h2v6H7zM4 18h2v4H4zm14 0h2v4h-2zM8 14h8v2H8zm-2 2h2v2H6zm10 0h2v2h-2z"/>
</svg>
```

`px-checkbox.imageset/checkbox.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M4 2h16v2H4zm0 18h16v2H4zM2 4h2v16H2zm18 0h2v16h-2z"/>
</svg>
```

`px-checkbox-on.imageset/checkbox-on.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M4 2h16v2H4zm0 18h16v2H4zM2 4h2v16H2zm18 0h2v16h-2zM7 12h2v2H7zm2 2h2v2H9zm2-2h2v2h-2zm2-2h2v2h-2zm2-2h2v2h-2z"/>
</svg>
```

`px-chart.imageset/chart.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M4 2h16v2H4zm0 18h16v2H4zM2 4h2v16H2zm18 0h2v16h-2zM7 11h2v6H7zm4-4h2v10h-2zm4 6h2v4h-2z"/>
</svg>
```

`px-wallet.imageset/wallet.svg`:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path d="M18 5h2v2h-2zM4 3h14v2H4zM2 5h2v14H2zm2 14h16v2H4zm12-4h6v2h-6zm0-4h6v2h-6zm-2 0h2v6h-2z"/>
  <path d="M20 7h2v12h-2zM4 7h16v2H4z"/>
</svg>
```

- [ ] **Step 3: Create the `Pixel` name layer** — `ernte/Support/PixelIcon.swift`:

```swift
import SwiftUI

/// Asset-catalog names for the bundled pixelarticons set (https://pixelarticons.com,
/// MIT). Centralised so a rename or typo is caught in one place rather than as
/// scattered string literals at call sites.
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

- [ ] **Step 4: Create the attribution file** — `ernte/Assets.xcassets/Pixelarticons-NOTICE.txt`:

```text
Icons prefixed "px-" in this asset catalog are from pixelarticons
(https://pixelarticons.com, https://github.com/halfmage/pixelarticons),
© Gerrit Halfmann, MIT License.
```

- [ ] **Step 5: Build to verify the catalog compiles**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build -quiet`
Expected: `** BUILD SUCCEEDED **`. (Xcode compiles the asset catalog during the build; a malformed `Contents.json` or missing SVG would fail here. Nothing references the assets yet, so only the catalog validity is exercised.)

- [ ] **Step 6: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Assets.xcassets/px-*.imageset ernte/Assets.xcassets/Pixelarticons-NOTICE.txt ernte/Support/PixelIcon.swift
git commit -m "feat(ios): vendor pixelarticons icon set + Pixel name layer

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Tab-bar icons

**Files:**
- Modify: `ernte/ContentView.swift` (`MainTabView`)

- [ ] **Step 1: Swap the five `tabItem` images**

In `MainTabView`'s `TabView`, change each `.tabItem { Label(..., systemImage: ...) }` to use `image:` with `Pixel` constants. The five tab items become:

```swift
            HomeView(session: session, selectedTab: $selectedTab)
                .tabItem { Label("Home", image: Pixel.home) }
                .tag(0)

            TimerView(session: session)
                .tabItem { Label("Timer", image: Pixel.clock) }
                .tag(1)

            ProjectsView(session: session)
                .tabItem { Label("Projects", image: Pixel.folder) }
                .tag(2)

            BillingView(session: session)
                .tabItem { Label("Billing", image: Pixel.receipt) }
                .tag(3)

            AccountView(session: session)
                .tabItem { Label("Account", image: Pixel.user) }
                .tag(4)
```

Leave `@State private var selectedTab`, `TabView(selection:)`, tags, and the `HomeView(session:selectedTab:)` initializer exactly as they are. Do not touch other structs in the file.

- [ ] **Step 2: Build to verify**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/ContentView.swift
git commit -m "feat(ios): pixelarticons tab-bar icons

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Project-task checkbox

**Files:**
- Modify: `ernte/Features/Projects/ProjectDetailView.swift` (the task row, ~line 38)

- [ ] **Step 1: Replace the SF Symbol checkbox**

Find this block inside the tasks `ForEach`:

```swift
                            Image(systemName: task.done ? "checkmark.circle.fill" : "circle")
                                .foregroundStyle(task.done ? Theme.forest : Theme.ink4)
```

Replace it with (keep the trailing `.foregroundStyle(...)` so the tint still applies to the template image):

```swift
                            Image(task.done ? Pixel.checkboxOn : Pixel.checkbox)
                                .renderingMode(.template)
                                .resizable()
                                .frame(width: 18, height: 18)
                                .foregroundStyle(task.done ? Theme.forest : Theme.ink4)
```

Leave the surrounding `HStack`, `Text(task.name)`, `Spacer()`, and hours `Text` unchanged.

- [ ] **Step 2: Build to verify**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

- [ ] **Step 3: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Features/Projects/ProjectDetailView.swift
git commit -m "feat(ios): pixelarticons task checkbox

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Dashboard header accents

**Files:**
- Modify: `ernte/Features/Home/HomeView.swift`

- [ ] **Step 1: Add the `pixelHeader` helper**

Add this private helper method inside `struct HomeView` (e.g. just before the existing `elapsed(since:)` method):

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

- [ ] **Step 2: Use it for the three section headers**

In `HomeView`, replace the three `header:` closures:

- In `timerSection`, change:
  ```swift
        } header: {
            Text("Timer").ernteSectionHeader()
        }
  ```
  to:
  ```swift
        } header: {
            pixelHeader(Pixel.clock, "Timer")
        }
  ```

- In `hoursSection(hours:today:)`, change:
  ```swift
        } header: {
            Text("Hours & earnings").ernteSectionHeader()
        }
  ```
  to:
  ```swift
        } header: {
            pixelHeader(Pixel.chart, "Hours & earnings")
        }
  ```

- In `moneySection(_:)`, change:
  ```swift
        } header: {
            Text("Money owed").ernteSectionHeader()
        }
  ```
  to:
  ```swift
        } header: {
            pixelHeader(Pixel.wallet, "Money owed")
        }
  ```

Do not change the error-section header or any row content.

- [ ] **Step 3: Build to verify**

Run: `cd /Users/sam/Documents/_projects/ernte && xcodebuild -project ernte.xcodeproj -scheme ernte -destination 'platform=iOS Simulator,name=iPhone 17' build -quiet`
Expected: `** BUILD SUCCEEDED **`.

**If the icon doesn't render or the header casing/spacing looks wrong** (because `.ernteSectionHeader()` is now applied to an `HStack` rather than a `Text`), replace the helper's modifier `.ernteSectionHeader()` with the equivalent applied directly:
```swift
        .font(.ernteCaption)
        .foregroundStyle(Theme.ink3)
        .textCase(.uppercase)
```
Rebuild and confirm. (`.ernteSectionHeader()` is defined in `ernte/Support/Theme.swift` as exactly those three modifiers.)

- [ ] **Step 4: Commit**

```bash
cd /Users/sam/Documents/_projects/ernte
git add ernte/Features/Home/HomeView.swift
git commit -m "feat(ios): pixelarticons accents on Home section headers

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Manual verification

**Files:** none (verification only).

- [ ] **Step 1: Run the app in the simulator**

Open the project in Xcode (or `xcodebuild ... -destination 'platform=iOS Simulator,name=iPhone 17'` then launch in Simulator), sign in, and confirm:
1. **Tab bar** — all five tabs show their pixel icon; the selected tab tints forest, the others grey. Labels (Home/Timer/Projects/Billing/Account) unchanged.
2. **Home headers** — Timer shows a clock accent, Hours & earnings a chart accent, Money owed a wallet accent; icons are crisp (vector-preserved) and ink3-toned like the header text.
3. **Project detail** — open a project with tasks; done tasks show a filled pixel checkbox (forest), open tasks an empty pixel box (ink4).

- [ ] **Step 2: Note any rendering issues**

If any icon renders blurry, mis-tinted, or blank, report it. Blank = asset name mismatch (check the `Pixel` constant vs the `.imageset` folder name). Blurry = `preserves-vector-representation` missing from that icon's `Contents.json`.

---

## Self-review notes (verified against the spec)

- **Spec coverage:** 9 vendored assets + `Contents.json` template (Task 1) ✓; `Pixel` enum (Task 1, Step 3) ✓; MIT attribution NOTICE (Task 1, Step 4) ✓; tab bar 5-icon swap (Task 2) ✓; task checkbox open/done (Task 3) ✓; three dashboard header accents clock/chart/wallet (Task 4) ✓ with the `.ernteSectionHeader()`-on-`HStack` fallback from the spec (Task 4, Step 3) ✓; verification = build + manual pass (Tasks 1–5) ✓. Out-of-scope items (Leaf, other screens' headers, backend) are untouched.
- **Placeholder scan:** every SVG and `Contents.json` is given verbatim; no TBD/TODO; the one conditional ("if casing looks wrong") includes the exact fallback code.
- **Type/name consistency:** `Pixel.home/clock/folder/receipt/user/checkbox/checkboxOn/chart/wallet` defined in Task 1 are the exact symbols used in Tasks 2–4; asset folder names `px-<name>.imageset` match the `Pixel` string values; `checkboxOn` (Swift) ↔ `px-checkbox-on` (asset) mapping is consistent.
