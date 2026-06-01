# iOS Companion — "Paper" Theme Implementation Plan

> **For agentic workers:** Executed collaboratively — Claude authors + commits Swift/Info.plist; the **user** adds new files (incl. three font `.ttf`s) to the Xcode target and builds/runs at the BUILD CHECKPOINT. App repo: `/Users/sam/Documents/_projects/ernte`, target/module `ernte`. Steps use checkbox (`- [ ]`).

**Goal:** Restyle the SwiftUI app to match the ernte web look — warm "paper" palette, JetBrains Mono type, forest-green accent, flat density — on top of native SwiftUI lists/navigation.

**Architecture:** A `Theme` token file (colors + fonts) + a UIKit `Appearance` config for nav/tab bars, bundled JetBrains Mono, forced light mode, and a reusable list modifier. Each screen swaps stock colors/fonts for `Theme` equivalents. Structure stays native.

**Tech Stack:** SwiftUI, iOS 17, UIKit appearance proxies, bundled OpenType fonts.

**Conventions:** new files added to the `ernte` target by the user (flagged per task). SourceKit "cannot find … in scope" squiggles before files are added to the target are expected and not real errors.

---

## Task 1: `Theme.swift` — tokens, fonts, band mapping

**Files (Claude creates):** `ernte/Support/Theme.swift`, `ernteTests/ColorHexTests.swift` (test added later in Task 8 if a test target exists; the `Color(hex:)` test code is included here for reference).

- [ ] **Step 1: Create `ernte/Support/Theme.swift`**

```swift
import SwiftUI

extension Color {
    /// "#RRGGBB" or "RRGGBB".
    init(hex: String) {
        let s = hex.hasPrefix("#") ? String(hex.dropFirst()) : hex
        var v: UInt64 = 0
        Scanner(string: s).scanHexInt64(&v)
        let r = Double((v & 0xFF0000) >> 16) / 255
        let g = Double((v & 0x00FF00) >> 8) / 255
        let b = Double(v & 0x0000FF) / 255
        self.init(.sRGB, red: r, green: g, blue: b, opacity: 1)
    }
}

enum Theme {
    // Paper palette (web tokens.css)
    static let paper        = Color(hex: "#f5f1ea")
    static let paper2       = Color(hex: "#efe9dc")
    static let ink          = Color(hex: "#1a1a1a")
    static let ink2         = Color(hex: "#3d3d3d")
    static let ink3         = Color(hex: "#6b6b6b")
    static let ink4         = Color(hex: "#9a9a9a")
    static let border       = Color(hex: "#e8e1d4")
    static let borderStrong = Color(hex: "#c9c0ad")
    static let forest       = Color(hex: "#2d4a3a")   // accent
    static let rust         = Color(hex: "#c97b3c")
    static let red          = Color(hex: "#b54834")
    static let gold         = Color(hex: "#b8941f")

    /// Project/budget band → color. ok → ink3, warn → gold, over → red.
    static func band(_ band: String) -> Color {
        switch band {
        case "over": return red
        case "warn": return gold
        default:     return ink3
        }
    }
}

extension Font {
    /// JetBrains Mono at an explicit size. Falls back to system monospaced if the
    /// bundled font isn't found (e.g. before it's added to the target).
    static func ernte(_ size: CGFloat, _ weight: Font.Weight = .regular) -> Font {
        let name: String
        switch weight {
        case .bold, .heavy, .black: name = "JetBrainsMono-Bold"
        case .medium, .semibold:    name = "JetBrainsMono-Medium"
        default:                    name = "JetBrainsMono-Regular"
        }
        return .custom(name, fixedSize: size)
    }

    static let ernteCaption   = ernte(11)
    static let ernteBody      = ernte(13)
    static let ernteMedium    = ernte(15)
    static let ernteTitle     = ernte(24, .bold)
    static let ernteLargeNum  = ernte(30, .medium)   // running-timer elapsed, etc.
}
```

- [ ] **Step 2 (User): add `Theme.swift` to the `ernte` target.**

- [ ] **Step 3: Commit**
```bash
git add ernte/Support/Theme.swift
git commit -m "feat(ios): Theme tokens — paper palette, JetBrains Mono fonts, band colors"
```

---

## Task 2: Bundle JetBrains Mono

**Files:**
- User adds: `ernte/Fonts/JetBrainsMono-Regular.ttf`, `-Medium.ttf`, `-Bold.ttf`
- Claude modifies: `ernte/Info.plist`

- [ ] **Step 1 (User): download the fonts**

JetBrains Mono is free (OFL). Download from <https://www.jetbrains.com/lp/mono/> (or `brew install --cask font-jetbrains-mono`). From the package, take **JetBrainsMono-Regular.ttf**, **JetBrainsMono-Medium.ttf**, **JetBrainsMono-Bold.ttf**.

- [ ] **Step 2 (User): add them to the project**

Create a `Fonts` group under `ernte/` and drag the three `.ttf` files in. In the add dialog: ✅ *Add to target: ernte*, ✅ *Copy items if needed* (these come from outside the repo).

- [ ] **Step 3 (Claude): register them in `ernte/Info.plist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>ITSAppUsesNonExemptEncryption</key>
	<false/>
	<key>UIAppFonts</key>
	<array>
		<string>JetBrainsMono-Regular.ttf</string>
		<string>JetBrainsMono-Medium.ttf</string>
		<string>JetBrainsMono-Bold.ttf</string>
	</array>
</dict>
</plist>
```

- [ ] **Step 4 (User): verify the PostScript names**

The names in `Font.ernte` (`JetBrainsMono-Regular/Medium/Bold`) must match the fonts' PostScript names. Confirm in **Font Book** (select the font → Cmd-I → "PostScript name"), or temporarily add to `ernteApp.init()`:
```swift
for family in UIFont.familyNames where family.contains("JetBrains") {
    print(family, UIFont.fontNames(forFamilyName: family))
}
```
If they differ, update the strings in `Font.ernte`. (Standard names are as above.)

- [ ] **Step 5: Commit**
```bash
git add ernte/Info.plist ernte/Fonts
git commit -m "feat(ios): bundle JetBrains Mono fonts"
```

---

## Task 3: `Appearance.swift` + wire into the app shell

**Files:**
- Create: `ernte/Support/Appearance.swift`
- Modify: `ernte/ernteApp.swift`, `ernte/ContentView.swift` (RootView)

- [ ] **Step 1: Create `ernte/Support/Appearance.swift`**

```swift
import UIKit
import SwiftUI

enum Appearance {
    /// Configure UIKit appearance proxies so nav/tab bars match the paper theme.
    static func configure() {
        let paper = UIColor(Theme.paper)
        let ink = UIColor(Theme.ink)

        let nav = UINavigationBarAppearance()
        nav.configureWithOpaqueBackground()
        nav.backgroundColor = paper
        nav.shadowColor = UIColor(Theme.border)
        if let large = UIFont(name: "JetBrainsMono-Bold", size: 30) {
            nav.largeTitleTextAttributes = [.foregroundColor: ink, .font: large]
        }
        if let inline = UIFont(name: "JetBrainsMono-Medium", size: 17) {
            nav.titleTextAttributes = [.foregroundColor: ink, .font: inline]
        }
        UINavigationBar.appearance().standardAppearance = nav
        UINavigationBar.appearance().scrollEdgeAppearance = nav
        UINavigationBar.appearance().compactAppearance = nav

        let tab = UITabBarAppearance()
        tab.configureWithOpaqueBackground()
        tab.backgroundColor = paper
        UITabBar.appearance().standardAppearance = tab
        UITabBar.appearance().scrollEdgeAppearance = tab
    }
}
```

- [ ] **Step 2: Call it from `ernteApp.swift`**

```swift
import SwiftUI

@main
struct ernteApp: App {
    init() {
        Appearance.configure()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
        }
    }
}
```

- [ ] **Step 3: Force light mode + forest tint in `ContentView.swift` (`RootView`)**

Change `RootView`'s `body` so the `Group` carries the theme:
```swift
    var body: some View {
        Group {
            switch session.state {
            case .unknown:
                ProgressView().task { await session.restore() }
            case .signedOut:
                LoginView(session: session)
            case .signedIn:
                MainTabView(session: session)
            }
        }
        .tint(Theme.forest)
        .preferredColorScheme(.light)
    }
```

- [ ] **Step 4 (User): add `Appearance.swift` to the target.**

- [ ] **Step 5: Commit**
```bash
git add ernte/Support/Appearance.swift ernte/ernteApp.swift ernte/ContentView.swift
git commit -m "feat(ios): paper nav/tab appearance, forced light mode, forest tint"
```

---

## Task 4: Reusable list modifier + restyle Login & Timer

**Files:**
- Modify: `ernte/Support/Theme.swift` (add a `View` modifier)
- Modify: `ernte/Features/Login/LoginView.swift`, `ernte/Features/Timer/TimerView.swift`

- [ ] **Step 1: Add an `erntePaperList()` modifier to `Theme.swift`** (append at end)

```swift
extension View {
    /// Paper background + mono default font for a List-based screen.
    func erntePaperList() -> some View {
        self
            .scrollContentBackground(.hidden)
            .background(Theme.paper)
            .font(.ernteBody)
    }

    /// Uppercase mono section header in ink3 (matches the web's "THIS WEEK" labels).
    func ernteSectionHeader() -> some View {
        self
            .font(.ernteCaption)
            .foregroundStyle(Theme.ink3)
            .textCase(.uppercase)
    }
}
```

- [ ] **Step 2: Restyle `LoginView`** — apply paper list + forest button + mono title. Replace the `Form { … }` modifiers:
  - Add `.erntePaperList()` to the `Form`.
  - Add `.listRowBackground(Theme.paper2)` to each `Section`.
  - The "Sign In" button text: `Text("Sign In").font(.ernteBody).foregroundStyle(Theme.forest)`.
  - Error text: `.foregroundStyle(Theme.red)`.
  - Title stays `.navigationTitle("ernte")` (now mono via the appearance proxy).

- [ ] **Step 3: Restyle `TimerView`** — in the `List`:
  - Add `.erntePaperList()` to the `List`.
  - Section headers: change `Section("Running")`/`Section("Today")` to use an explicit header — `Section { … } header: { Text("Running").ernteSectionHeader() }` (and "Today").
  - `.listRowBackground(Theme.paper)` on each section (flat rows).
  - Running project name: `.font(.ernte(15, .medium))`; elapsed: `.font(.ernteLargeNum)` (replace the `.system(.title, design: .monospaced)`).
  - Buttons (`Stop`, `Switch project…`, `Start timer…`) inherit forest tint; `Discard` keeps `role: .destructive`.

- [ ] **Step 4 (Commit)**
```bash
git add ernte/Support/Theme.swift ernte/Features/Login ernte/Features/Timer
git commit -m "feat(ios): paper-theme Login and Timer screens"
```

---

## Task 5: Restyle Projects (list + detail)

**Files:** `ernte/Features/Projects/ProjectsView.swift`, `ernte/Features/Projects/ProjectDetailView.swift`

- [ ] **Step 1: `ProjectsView`**
  - `.erntePaperList()` on the `List`.
  - Section headers ("This week", "Active projects") → `header: { Text("…").ernteSectionHeader() }`.
  - `.listRowBackground(Theme.paper)` on each section.
  - In `ProjectRow`: `Text(project.name).font(.ernte(15, .medium)).foregroundStyle(Theme.ink)`; client `.foregroundStyle(Theme.ink3)`; the budget-% text uses `.foregroundStyle(Theme.band(project.band))`; spent-hours `.foregroundStyle(Theme.ink3)`. Remove the old `bandColor` computed prop (now `Theme.band`).

- [ ] **Step 2: `ProjectDetailView`**
  - `.erntePaperList()` on the `List`; section headers via `.ernteSectionHeader()`; `.listRowBackground(Theme.paper)`.
  - Task done icon tint: keep `.green`→ change to `Theme.forest` for done, `Theme.ink4` for not-done.
  - Spent/budget values mono `ink`/`ink3`; "Billed value" mono.

- [ ] **Step 3: Commit**
```bash
git add ernte/Features/Projects
git commit -m "feat(ios): paper-theme Projects list + detail"
```

---

## Task 6: Restyle Billing (list + detail)

**Files:** `ernte/Features/Billing/BillingView.swift`, `ernte/Features/Billing/InvoiceDetailView.swift`, `ernte/Features/Billing/EstimateDetailView.swift`

- [ ] **Step 1: `BillingView`**
  - `.erntePaperList()` on the `List`; the segmented `Picker` row keeps `.listRowBackground(Color.clear)`.
  - Section headers ("Summary") via `.ernteSectionHeader()`; `.listRowBackground(Theme.paper)` on content sections.
  - In `BillingRow`: number `.font(.ernte(15, .medium))`; total `.font(.ernteBody)`; client `.foregroundStyle(Theme.ink3)`; status `.foregroundStyle(Theme.ink3)`; the `flag` (OVERDUE/EXPIRED) `.foregroundStyle(Theme.red)`.

- [ ] **Step 2: `InvoiceDetailView` + `EstimateDetailView`**
  - `.erntePaperList()`; section headers via `.ernteSectionHeader()`; `.listRowBackground(Theme.paper)`.
  - OVERDUE/EXPIRED rows `.foregroundStyle(Theme.red)`.
  - Total row stays `.bold()` — also `.foregroundStyle(Theme.ink)`.
  - In `BillingLineRow`: description `Theme.ink`; the "hours × rate" + amount `Theme.ink3`.

- [ ] **Step 3: Commit**
```bash
git add ernte/Features/Billing
git commit -m "feat(ios): paper-theme Billing list + detail"
```

---

## Task 7: Restyle Account + (optional) Color(hex:) test

**Files:** `ernte/ContentView.swift` (`AccountView`), `ernteTests/ColorHexTests.swift` (only if a test target exists)

- [ ] **Step 1: `AccountView`**
  - `.erntePaperList()` on the `List`; "Signed in as" header via `.ernteSectionHeader()`; `.listRowBackground(Theme.paper)`.
  - Name `.foregroundStyle(Theme.ink)`; email `.foregroundStyle(Theme.ink3)`; "Sign Out" keeps `role: .destructive` (renders red).

- [ ] **Step 2 (optional): `Color(hex:)` unit test** (only if you added a test target in Task 7 of the 1b plan; otherwise skip)

```swift
import XCTest
import SwiftUI
@testable import ernte

final class ColorHexTests: XCTestCase {
    func testParsesSixDigitHex() {
        let c = Color(hex: "#2d4a3a")
        let ui = UIColor(c)
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        ui.getRed(&r, green: &g, blue: &b, alpha: &a)
        XCTAssertEqual(r, 0x2d / 255, accuracy: 0.01)
        XCTAssertEqual(g, 0x4a / 255, accuracy: 0.01)
        XCTAssertEqual(b, 0x3a / 255, accuracy: 0.01)
        XCTAssertEqual(a, 1, accuracy: 0.01)
    }
}
```

- [ ] **Step 3: Commit**
```bash
git add ernte/ContentView.swift
git commit -m "feat(ios): paper-theme Account screen"
```

---

## Task 8: BUILD CHECKPOINT — add files, build, verify all screens

**Owner:** User (Xcode).

- [ ] **Step 1: Add the new files to the `ernte` target** (if not added per-task): `Support/Theme.swift`, `Support/Appearance.swift`, and the three `Fonts/*.ttf`. (`Info.plist`, `ernteApp.swift`, `ContentView.swift`, and the feature views were modified in place — already in the target.)
- [ ] **Step 2: Build (⌘B).** Paste any compile errors.
- [ ] **Step 3: Run (⌘R).** Verify on every screen:
  - Warm paper background throughout; **JetBrains Mono** text everywhere (titles + body).
  - Forest-green accent on buttons/links/segmented control; large mono nav titles on paper bars; paper tab bar.
  - Projects budget % colored by band (gold = warn, red = over); Billing OVERDUE/EXPIRED in red.
  - If any text shows in the system font, the font name/registration is off → re-check Task 2 Step 4 (PostScript names).
- [ ] **Step 4: Report.** When it looks right, `fastlane beta` ships it to your phone via TestFlight.

---

## Self-Review Notes

- **Spec coverage:** Theme tokens (Task 1), JetBrains Mono bundling (Task 2), Appearance + light mode + tint (Task 3), all five screens restyled (Tasks 4–7), `Color(hex:)` test + visual verification (Tasks 7–8). Out-of-scope items (dark theme, custom cards, sparkline/heatmap rendering) are intentionally not included.
- **Font fallback:** `Font.ernte` uses `.custom(name, fixedSize:)`, which falls back to the system font if the bundled font is missing — so the app still builds/runs before fonts are added, and Task 2 Step 4 verifies the names.
- **Type consistency:** `Theme.band(_:)` replaces the per-view `bandColor`; `erntePaperList()`/`ernteSectionHeader()` are the shared modifiers used across all screens; fonts referenced via the `.ernte*` helpers throughout.
- **Collaboration:** Claude authors + commits; the user adds `Theme.swift`, `Appearance.swift`, and the three `.ttf`s to the target and builds (Task 8).
