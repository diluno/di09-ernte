# iOS Companion — Slice 1b: iOS App (Login + Timer Tab) Implementation Plan

> **For agentic workers:** This plan is executed **collaboratively**. Claude authors all Swift files and commits them to the `ernte-ios` repo. The **user** performs the Xcode steps (project creation, builds, runs) because full Xcode is required and not drivable from this environment. Steps use checkbox (`- [ ]`) syntax. At each **BUILD CHECKPOINT**, the user builds/runs in Xcode and reports results before continuing.

**Goal:** A native SwiftUI iOS app where you log in against the ernte API and use the timer (see what's running, start/switch/stop/discard, view today's total) from your phone.

**Architecture:** SwiftUI, iOS 17+, light MVVM with the Observation framework (`@Observable`). An `APIClient` actor (URLSession + bearer token) talks to the Slice 1a JSON API; a `KeychainStore` persists the token; a `Session` object holds auth state and switches the root view between Login and the main TabView.

**Tech Stack:** Swift 6, SwiftUI, Observation, URLSession async/await, Security framework (Keychain). No third-party dependencies.

**Backend contract (from Slice 1a, already shipped):**
- `POST /api/auth/token` `{email,password,device_name}` → `{ token, user:{id,name,email} }` (rate-limited; 422 on bad creds)
- `DELETE /api/auth/token` (Bearer) → `{message}`
- `GET /api/me` (Bearer) → `{ user:{id,name,email}, business:{name,currency}|null }`
- `GET /api/timer` (Bearer) → `{ entries:[…], totals:{total_seconds,billable_seconds,earnings_amount}, by_project:[…], quick_start:[{id,name,code}], projects:[{id,name,code}], running:{id,description,task_name?,started_at,duration_seconds,billable,project:{id,name,code}}|null }`
- `POST /api/timer/{start,switch}` `{project_id, task_id?, description?}` → same timer payload; `POST /api/timer/{stop,discard}` → same timer payload
- All timestamps are ISO‑8601 with timezone (e.g. `2026-06-01T13:00:00+00:00`).

---

## Conventions for this plan

- **Product/target name is `ErnteCompanion`.** Name it exactly this when creating the project so the `@main` file and bundle layout match. All other files are name-agnostic (the app target does not import its own module).
- **Repo:** `/Users/sam/Documents/_projects/ernte-ios`, a new standalone git repo (sibling of `di09-ernte`).
- **File groups** live under the `ErnteCompanion/` source folder Xcode creates: `Support/`, `Models/`, `Networking/`, `Auth/`, `Features/Login/`, `Features/Timer/`.
- Claude commits after each task with the shown message. The user does not need to commit.

---

## Task 0: Create the Xcode project and initialize the repo

**Owner:** User (Xcode steps) + Claude (git init).

- [ ] **Step 1 (User): Create the project in Xcode**

In Xcode: **File ▸ New ▸ Project… ▸ iOS ▸ App**. Set:
- Product Name: `ErnteCompanion`
- Interface: **SwiftUI**
- Language: **Swift**
- Storage: **None** (no Core Data), **uncheck** "Include Tests" for now (a test target is added in Task 7 if desired)
- Organization identifier: your choice (e.g. `com.diluno`) → bundle id `com.diluno.ErnteCompanion`
- Save location: `/Users/sam/Documents/_projects/` (Xcode creates `/Users/sam/Documents/_projects/ernte-ios`? No — it creates `…/ErnteCompanion/`).

**Important:** When the save dialog appears, **uncheck "Create Git repository on my Mac"** (Claude will init the repo), and set the folder so the final path is `/Users/sam/Documents/_projects/ernte-ios` with `ErnteCompanion.xcodeproj` inside it. The simplest way: save to `/Users/sam/Documents/_projects/`, then rename the created `ErnteCompanion` folder to `ernte-ios`, OR save anywhere and tell Claude the resulting absolute path.

- [ ] **Step 2 (User): Set the deployment target to iOS 17**

Select the project ▸ target `ErnteCompanion` ▸ **General ▸ Minimum Deployments ▸ iOS 17.0**.

- [ ] **Step 3 (User): Report the project path**

Tell Claude the absolute path to the project root (the folder containing `ErnteCompanion.xcodeproj`) and the absolute path to the source folder (the inner `ErnteCompanion/` that contains `ErnteCompanionApp.swift` and `ContentView.swift`).

- [ ] **Step 4 (Claude): Initialize git + .gitignore**

In the project root, create `.gitignore`:
```gitignore
# Xcode
build/
DerivedData/
*.xcuserstate
xcuserdata/
.DS_Store
*.xcscmblueprint
*.xccheckout
# Swift Package Manager
.swiftpm/
.build/
```
Then:
```bash
git init
git add -A
git commit -m "chore: blank SwiftUI app scaffold from Xcode"
```

- [ ] **Step 5 (Claude): Delete the stock `ContentView.swift`**

Xcode generates `ContentView.swift`. Delete the file from disk (it will be replaced by `RootView` wiring in Task 6). NOTE: removing a file from disk does not remove its reference from the `.xcodeproj`. So **leave `ContentView.swift` in place for now**; Task 6 repurposes its contents instead of deleting it, to avoid editing `project.pbxproj` by hand. (Adding NEW files also requires the user to add them to the target — see the note below.)

> **CRITICAL — adding files to the Xcode target:** New `.swift` files Claude writes to disk are NOT automatically part of the build until added to the target. After Claude creates files in a task, the **user** must, in Xcode, **right-click the `ErnteCompanion` group ▸ Add Files to "ErnteCompanion"…**, select the new files, ensure **"Add to target: ErnteCompanion" is checked**, and **uncheck "Copy items if needed"** (they're already in place). Each task below lists the new files to add. This is the one unavoidable bit of manual Xcode bookkeeping in our setup.

**BUILD CHECKPOINT 0:** User confirms the blank app builds and runs in the simulator (the default "Hello, world!" screen). Report success before continuing.

---

## Task 1: Config + Codable DTOs

**Files (Claude creates):**
- `ErnteCompanion/Support/Config.swift`
- `ErnteCompanion/Models/DTOs.swift`

- [ ] **Step 1: Create `Support/Config.swift`**

```swift
import Foundation

/// App-wide configuration. Change `apiBaseURL` to point at your server.
enum Config {
    /// The ernte API base URL, WITHOUT a trailing slash and WITHOUT `/api`.
    /// - Local DDEV:   https://ernte.ddev.site  (see the TLS note in Task 8)
    /// - Production:   https://<your-forge-host>
    static let apiBaseURL = URL(string: "https://ernte.ddev.site")!

    /// Sent as `device_name` when issuing a token.
    static let deviceName = "iPhone (ErnteCompanion)"
}
```

- [ ] **Step 2: Create `Models/DTOs.swift`** (decodes the exact 1a JSON; snake_case mapped via `CodingKeys`)

```swift
import Foundation

struct UserDTO: Codable, Equatable {
    let id: Int
    let name: String
    let email: String
}

struct BusinessDTO: Codable, Equatable {
    let name: String
    let currency: String
}

struct TokenResponse: Codable {
    let token: String
    let user: UserDTO
}

struct MeResponse: Codable {
    let user: UserDTO
    let business: BusinessDTO?
}

struct ProjectSummary: Codable, Identifiable, Equatable, Hashable {
    let id: Int
    let name: String
    let code: String
}

struct ProjectRef: Codable, Equatable, Hashable {
    let id: Int
    let name: String
    let code: String
}

struct RunningEntry: Codable, Equatable {
    let id: Int
    let description: String
    let taskName: String?
    let startedAt: Date
    let durationSeconds: Int
    let billable: Bool
    let project: ProjectRef

    enum CodingKeys: String, CodingKey {
        case id, description, billable, project
        case taskName = "task_name"
        case startedAt = "started_at"
        case durationSeconds = "duration_seconds"
    }
}

struct TimerTotals: Codable, Equatable {
    let totalSeconds: Int
    let billableSeconds: Int
    let earningsAmount: Double

    enum CodingKeys: String, CodingKey {
        case totalSeconds = "total_seconds"
        case billableSeconds = "billable_seconds"
        case earningsAmount = "earnings_amount"
    }
}

/// `GET /api/timer` and all timer mutations return this shape.
struct TimerPayload: Codable, Equatable {
    let totals: TimerTotals
    let projects: [ProjectSummary]
    let running: RunningEntry?
    // `entries`, `by_project`, `quick_start` are present in the JSON but not
    // needed for the Timer tab in this slice, so they are intentionally omitted.
}
```

- [ ] **Step 3 (User): Add `Config.swift` and `DTOs.swift` to the target** (see the CRITICAL note in Task 0).

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanion/Support/Config.swift ErnteCompanion/Models/DTOs.swift
git commit -m "feat(ios): config + Codable DTOs for the API contract"
```

**BUILD CHECKPOINT 1:** User builds (Cmd‑B). Expected: compiles with no errors. Report before continuing.

---

## Task 2: APIError + APIClient

**Files (Claude creates):**
- `ErnteCompanion/Networking/APIError.swift`
- `ErnteCompanion/Networking/APIClient.swift`

- [ ] **Step 1: Create `Networking/APIError.swift`**

```swift
import Foundation

enum APIError: Error, Equatable {
    case unauthorized                 // 401 — token missing/expired
    case validation([String: [String]]) // 422 — field errors
    case rateLimited                  // 429
    case server(Int)                  // other non-2xx
    case decoding
    case transport(String)            // URLError etc.

    var userMessage: String {
        switch self {
        case .unauthorized: return "Your session expired. Please sign in again."
        case .validation(let errors):
            return errors.values.first?.first ?? "Please check your input."
        case .rateLimited: return "Too many attempts. Wait a minute and try again."
        case .server(let code): return "Server error (\(code)). Try again."
        case .decoding: return "Unexpected response from the server."
        case .transport(let message): return message
        }
    }
}
```

- [ ] **Step 2: Create `Networking/APIClient.swift`** (actor; holds the bearer token; one shared JSON decoder with ISO‑8601 dates)

```swift
import Foundation

actor APIClient {
    private let baseURL: URL
    private let session: URLSession
    private var token: String?

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        return d
    }()

    init(baseURL: URL = Config.apiBaseURL, session: URLSession = .shared) {
        self.baseURL = baseURL
        self.session = session
    }

    func setToken(_ token: String?) {
        self.token = token
    }

    // MARK: - Endpoints

    func issueToken(email: String, password: String) async throws -> TokenResponse {
        try await request(
            "POST", "/api/auth/token",
            body: ["email": email, "password": password, "device_name": Config.deviceName],
            authorized: false
        )
    }

    func revokeToken() async throws {
        let _: EmptyResponse = try await request("DELETE", "/api/auth/token")
    }

    func me() async throws -> MeResponse {
        try await request("GET", "/api/me")
    }

    func timer() async throws -> TimerPayload {
        try await request("GET", "/api/timer")
    }

    func startTimer(projectId: Int, description: String) async throws -> TimerPayload {
        try await request("POST", "/api/timer/start",
                          body: ["project_id": projectId, "description": description])
    }

    func switchTimer(projectId: Int, description: String) async throws -> TimerPayload {
        try await request("POST", "/api/timer/switch",
                          body: ["project_id": projectId, "description": description])
    }

    func stopTimer() async throws -> TimerPayload {
        try await request("POST", "/api/timer/stop")
    }

    func discardTimer() async throws -> TimerPayload {
        try await request("POST", "/api/timer/discard")
    }

    // MARK: - Core request

    private func request<T: Decodable>(
        _ method: String,
        _ path: String,
        body: [String: Any]? = nil,
        authorized: Bool = true
    ) async throws -> T {
        var req = URLRequest(url: baseURL.appendingPathComponent(path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if authorized, let token {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONSerialization.data(withJSONObject: body)
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: req)
        } catch {
            throw APIError.transport(error.localizedDescription)
        }

        guard let http = response as? HTTPURLResponse else { throw APIError.decoding }

        switch http.statusCode {
        case 200...299:
            if T.self == EmptyResponse.self { return EmptyResponse() as! T }
            do { return try decoder.decode(T.self, from: data) }
            catch { throw APIError.decoding }
        case 401:
            throw APIError.unauthorized
        case 422:
            let parsed = try? decoder.decode(ValidationErrorBody.self, from: data)
            throw APIError.validation(parsed?.errors ?? [:])
        case 429:
            throw APIError.rateLimited
        default:
            throw APIError.server(http.statusCode)
        }
    }
}

struct EmptyResponse: Decodable {}

private struct ValidationErrorBody: Decodable {
    let errors: [String: [String]]
}
```

- [ ] **Step 3 (User): Add `APIError.swift` and `APIClient.swift` to the target.**

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanion/Networking
git commit -m "feat(ios): APIClient actor + typed APIError"
```

**BUILD CHECKPOINT 2:** User builds (Cmd‑B). Expected: compiles cleanly. Report before continuing.

---

## Task 3: KeychainStore + Session

**Files (Claude creates):**
- `ErnteCompanion/Auth/KeychainStore.swift`
- `ErnteCompanion/Auth/Session.swift`

- [ ] **Step 1: Create `Auth/KeychainStore.swift`**

```swift
import Foundation
import Security

/// Minimal Keychain wrapper for a single string secret (the API token).
struct KeychainStore {
    let service: String
    let account: String

    init(service: String = "com.diluno.ErnteCompanion", account: String = "api-token") {
        self.service = service
        self.account = account
    }

    func save(_ value: String) {
        let data = Data(value.utf8)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        SecItemDelete(query as CFDictionary)
        var attributes = query
        attributes[kSecValueData as String] = data
        SecItemAdd(attributes as CFDictionary, nil)
    }

    func read() -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data,
              let string = String(data: data, encoding: .utf8)
        else { return nil }
        return string
    }

    func clear() {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        SecItemDelete(query as CFDictionary)
    }
}
```

- [ ] **Step 2: Create `Auth/Session.swift`** (observable auth state; owns the `APIClient`)

```swift
import Foundation
import Observation

@Observable
@MainActor
final class Session {
    enum State: Equatable {
        case unknown        // app just launched, restoring token
        case signedOut
        case signedIn(UserDTO)
    }

    private(set) var state: State = .unknown

    let api: APIClient
    private let keychain: KeychainStore

    init(api: APIClient = APIClient(), keychain: KeychainStore = KeychainStore()) {
        self.api = api
        self.keychain = keychain
    }

    /// Called once at launch: restore a stored token and verify it via /me.
    func restore() async {
        guard let token = keychain.read() else {
            state = .signedOut
            return
        }
        await api.setToken(token)
        do {
            let me = try await api.me()
            state = .signedIn(me.user)
        } catch APIError.unauthorized {
            keychain.clear()
            await api.setToken(nil)
            state = .signedOut
        } catch {
            // Network hiccup at launch: keep the token, treat as signed in if we
            // had one. The Timer view will surface any persistent error.
            state = .signedOut
        }
    }

    func signIn(email: String, password: String) async throws {
        let response = try await api.issueToken(email: email, password: password)
        keychain.save(response.token)
        await api.setToken(response.token)
        state = .signedIn(response.user)
    }

    func signOut() async {
        try? await api.revokeToken()
        keychain.clear()
        await api.setToken(nil)
        state = .signedOut
    }

    /// Call when any authorized request returns 401.
    func handleUnauthorized() {
        keychain.clear()
        Task { await api.setToken(nil) }
        state = .signedOut
    }
}
```

- [ ] **Step 3 (User): Add `KeychainStore.swift` and `Session.swift` to the target.**

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanion/Auth
git commit -m "feat(ios): Keychain token store + observable Session"
```

**BUILD CHECKPOINT 3:** User builds (Cmd‑B). Expected: compiles cleanly. Report before continuing.

---

## Task 4: Login feature

**Files (Claude creates):**
- `ErnteCompanion/Features/Login/LoginViewModel.swift`
- `ErnteCompanion/Features/Login/LoginView.swift`

- [ ] **Step 1: Create `Features/Login/LoginViewModel.swift`**

```swift
import Foundation
import Observation

@Observable
@MainActor
final class LoginViewModel {
    var email = ""
    var password = ""
    var isSubmitting = false
    var errorMessage: String?

    private let session: Session
    init(session: Session) { self.session = session }

    var canSubmit: Bool {
        !email.isEmpty && !password.isEmpty && !isSubmitting
    }

    func submit() async {
        guard canSubmit else { return }
        isSubmitting = true
        errorMessage = nil
        defer { isSubmitting = false }
        do {
            try await session.signIn(email: email, password: password)
            // On success, Session.state flips and RootView swaps the view.
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = "Something went wrong. Please try again."
        }
    }
}
```

- [ ] **Step 2: Create `Features/Login/LoginView.swift`**

```swift
import SwiftUI

struct LoginView: View {
    @State private var model: LoginViewModel

    init(session: Session) {
        _model = State(initialValue: LoginViewModel(session: session))
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Email", text: $model.email)
                        .textContentType(.emailAddress)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    SecureField("Password", text: $model.password)
                        .textContentType(.password)
                        .onSubmit { Task { await model.submit() } }
                }
                if let error = model.errorMessage {
                    Section {
                        Text(error).foregroundStyle(.red)
                    }
                }
                Section {
                    Button {
                        Task { await model.submit() }
                    } label: {
                        if model.isSubmitting {
                            ProgressView()
                        } else {
                            Text("Sign In")
                        }
                    }
                    .disabled(!model.canSubmit)
                }
            }
            .navigationTitle("ernte")
        }
    }
}
```

- [ ] **Step 3 (User): Add both Login files to the target.**

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanion/Features/Login
git commit -m "feat(ios): login screen + view model"
```

**BUILD CHECKPOINT 4:** Deferred to Task 6 (Login can't be shown until `RootView` wires it in). Just build (Cmd‑B) to confirm it compiles. Report before continuing.

---

## Task 5: Timer feature

**Files (Claude creates):**
- `ErnteCompanion/Features/Timer/TimerViewModel.swift`
- `ErnteCompanion/Features/Timer/TimerView.swift`

- [ ] **Step 1: Create `Features/Timer/TimerViewModel.swift`**

```swift
import Foundation
import Observation

@Observable
@MainActor
final class TimerViewModel {
    var payload: TimerPayload?
    var isLoading = false
    var errorMessage: String?

    // Start/switch sheet state
    var selectedProjectId: Int?
    var newDescription = ""

    private let session: Session
    private var api: APIClient { session.api }

    init(session: Session) { self.session = session }

    var running: RunningEntry? { payload?.running }
    var projects: [ProjectSummary] { payload?.projects ?? [] }

    func load() async {
        await run { try await self.api.timer() }
    }

    func start() async {
        guard let projectId = selectedProjectId else { return }
        let description = newDescription
        await run { try await self.api.startTimer(projectId: projectId, description: description) }
        newDescription = ""
    }

    func switchTo() async {
        guard let projectId = selectedProjectId else { return }
        let description = newDescription
        await run { try await self.api.switchTimer(projectId: projectId, description: description) }
        newDescription = ""
    }

    func stop() async {
        await run { try await self.api.stopTimer() }
    }

    func discard() async {
        await run { try await self.api.discardTimer() }
    }

    /// Runs an API call, updates `payload`, and routes errors. 401 → sign out.
    private func run(_ operation: @escaping () async throws -> TimerPayload) async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            payload = try await operation()
        } catch APIError.unauthorized {
            session.handleUnauthorized()
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = "Something went wrong."
        }
    }
}
```

- [ ] **Step 2: Create `Features/Timer/TimerView.swift`** (running card with live‑ticking elapsed; start/switch sheet; stop/discard; today total)

```swift
import SwiftUI

struct TimerView: View {
    @State private var model: TimerViewModel
    @State private var showStartSheet = false
    @State private var sheetIsSwitch = false

    // Drives the live elapsed display once per second.
    @State private var now = Date()
    private let tick = Timer.publish(every: 1, on: .main, in: .common).autoconnect()

    init(session: Session) {
        _model = State(initialValue: TimerViewModel(session: session))
    }

    var body: some View {
        NavigationStack {
            List {
                if let running = model.running {
                    Section("Running") {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(running.project.name).font(.headline)
                            if let task = running.taskName, !task.isEmpty {
                                Text(task).font(.subheadline).foregroundStyle(.secondary)
                            }
                            if !running.description.isEmpty {
                                Text(running.description).font(.subheadline)
                            }
                            Text(elapsed(since: running.startedAt))
                                .font(.system(.title, design: .monospaced))
                                .monospacedDigit()
                        }
                        Button("Stop") { Task { await model.stop() } }
                        Button("Switch project…") {
                            sheetIsSwitch = true; showStartSheet = true
                        }
                        Button("Discard", role: .destructive) { Task { await model.discard() } }
                    }
                } else {
                    Section {
                        Button("Start timer…") {
                            sheetIsSwitch = false; showStartSheet = true
                        }
                    }
                }

                if let totals = model.payload?.totals {
                    Section("Today") {
                        LabeledContent("Tracked", value: hms(totals.totalSeconds))
                        LabeledContent("Billable", value: hms(totals.billableSeconds))
                    }
                }

                if let error = model.errorMessage {
                    Section { Text(error).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Timer")
            .overlay { if model.isLoading && model.payload == nil { ProgressView() } }
            .refreshable { await model.load() }
            .task { await model.load() }
            .onReceive(tick) { now = $0 }
            .sheet(isPresented: $showStartSheet) {
                StartTimerSheet(model: model, isSwitch: sheetIsSwitch) {
                    showStartSheet = false
                }
            }
        }
    }

    private func elapsed(since start: Date) -> String {
        hms(max(0, Int(now.timeIntervalSince(start))))
    }

    private func hms(_ seconds: Int) -> String {
        String(format: "%d:%02d:%02d", seconds / 3600, (seconds % 3600) / 60, seconds % 60)
    }
}

private struct StartTimerSheet: View {
    let model: TimerViewModel
    let isSwitch: Bool
    let onDone: () -> Void

    var body: some View {
        NavigationStack {
            Form {
                Picker("Project", selection: Binding(
                    get: { model.selectedProjectId },
                    set: { model.selectedProjectId = $0 }
                )) {
                    Text("Select…").tag(Int?.none)
                    ForEach(model.projects) { project in
                        Text(project.name).tag(Int?.some(project.id))
                    }
                }
                TextField("Description (optional)", text: Binding(
                    get: { model.newDescription },
                    set: { model.newDescription = $0 }
                ))
            }
            .navigationTitle(isSwitch ? "Switch Project" : "Start Timer")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", action: onDone)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(isSwitch ? "Switch" : "Start") {
                        Task {
                            if isSwitch { await model.switchTo() } else { await model.start() }
                            onDone()
                        }
                    }
                    .disabled(model.selectedProjectId == nil)
                }
            }
        }
    }
}
```

- [ ] **Step 3 (User): Add both Timer files to the target.**

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanion/Features/Timer
git commit -m "feat(ios): timer tab — running card, start/switch/stop/discard, today totals"
```

**BUILD CHECKPOINT 5:** Build (Cmd‑B) to confirm it compiles (still not reachable until Task 6). Report before continuing.

---

## Task 6: Root wiring (App entry + RootView + logout)

**Files:**
- Modify (Claude): `ErnteCompanion/ContentView.swift` → becomes `RootView`
- Modify (Claude): `ErnteCompanion/ErnteCompanionApp.swift`

- [ ] **Step 1: Replace the contents of `ContentView.swift` with `RootView`**

(We reuse the existing file rather than deleting it, to avoid hand-editing `project.pbxproj`.)
```swift
import SwiftUI

struct RootView: View {
    @State private var session = Session()

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
    }
}

struct MainTabView: View {
    let session: Session

    var body: some View {
        TabView {
            TimerView(session: session)
                .tabItem { Label("Timer", systemImage: "timer") }

            AccountView(session: session)
                .tabItem { Label("Account", systemImage: "person.crop.circle") }
        }
    }
}

struct AccountView: View {
    let session: Session

    var body: some View {
        NavigationStack {
            List {
                if case let .signedIn(user) = session.state {
                    Section("Signed in as") {
                        Text(user.name)
                        Text(user.email).foregroundStyle(.secondary)
                    }
                }
                Section {
                    Button("Sign Out", role: .destructive) {
                        Task { await session.signOut() }
                    }
                }
            }
            .navigationTitle("Account")
        }
    }
}
```

- [ ] **Step 2: Replace the contents of `ErnteCompanionApp.swift`**

```swift
import SwiftUI

@main
struct ErnteCompanionApp: App {
    var body: some Scene {
        WindowGroup {
            RootView()
        }
    }
}
```

- [ ] **Step 3: Commit**
```bash
git add ErnteCompanion/ContentView.swift ErnteCompanion/ErnteCompanionApp.swift
git commit -m "feat(ios): root view wiring — login/main switch, timer + account tabs"
```

**BUILD CHECKPOINT 6 (the real one):** User runs the app in the simulator (Cmd‑R) — but first complete Task 8's connectivity setup so the app can reach the API. See Task 8.

---

## Task 7: Unit tests (optional but recommended)

**Owner:** User adds a Unit Test target (File ▸ New ▸ Target ▸ Unit Testing Bundle, name `ErnteCompanionTests`); Claude writes the tests.

**Files (Claude creates):**
- `ErnteCompanionTests/DTODecodingTests.swift`
- `ErnteCompanionTests/KeychainStoreTests.swift`

- [ ] **Step 1: `DTODecodingTests.swift`** — verifies the timer payload decodes from a realistic JSON fixture

```swift
import XCTest
@testable import ErnteCompanion

final class DTODecodingTests: XCTestCase {
    private func decoder() -> JSONDecoder {
        let d = JSONDecoder(); d.dateDecodingStrategy = .iso8601; return d
    }

    func testTimerPayloadDecodesWithRunningEntry() throws {
        let json = """
        {
          "entries": [],
          "totals": {"total_seconds": 3600, "billable_seconds": 3600, "earnings_amount": 120.5},
          "by_project": [],
          "quick_start": [{"id": 1, "name": "Acme", "code": "ACM"}],
          "projects": [{"id": 1, "name": "Acme", "code": "ACM"}],
          "running": {
            "id": 9, "description": "in progress", "task_name": "Design",
            "started_at": "2026-06-01T13:00:00+00:00",
            "duration_seconds": 600, "billable": true,
            "project": {"id": 1, "name": "Acme", "code": "ACM"}
          }
        }
        """.data(using: .utf8)!

        let payload = try decoder().decode(TimerPayload.self, from: json)
        XCTAssertEqual(payload.totals.totalSeconds, 3600)
        XCTAssertEqual(payload.totals.earningsAmount, 120.5, accuracy: 0.001)
        XCTAssertEqual(payload.projects.count, 1)
        XCTAssertEqual(payload.running?.taskName, "Design")
        XCTAssertEqual(payload.running?.project.name, "Acme")
    }

    func testTimerPayloadDecodesWithNullRunning() throws {
        let json = """
        {"entries": [], "totals": {"total_seconds": 0, "billable_seconds": 0, "earnings_amount": 0},
         "by_project": [], "quick_start": [], "projects": [], "running": null}
        """.data(using: .utf8)!
        let payload = try decoder().decode(TimerPayload.self, from: json)
        XCTAssertNil(payload.running)
    }
}
```

- [ ] **Step 2: `KeychainStoreTests.swift`** — round-trips a value (uses a unique service so it never collides with the real token)

```swift
import XCTest
@testable import ErnteCompanion

final class KeychainStoreTests: XCTestCase {
    func testSaveReadClear() {
        let store = KeychainStore(service: "com.diluno.ErnteCompanion.tests", account: "unit")
        store.clear()
        XCTAssertNil(store.read())

        store.save("abc123")
        XCTAssertEqual(store.read(), "abc123")

        store.save("def456") // overwrite
        XCTAssertEqual(store.read(), "def456")

        store.clear()
        XCTAssertNil(store.read())
    }
}
```

- [ ] **Step 3 (User): Add the two files to the `ErnteCompanionTests` target; run with Cmd‑U.**

- [ ] **Step 4: Commit**
```bash
git add ErnteCompanionTests
git commit -m "test(ios): DTO decoding + Keychain round-trip"
```

**TEST CHECKPOINT 7:** User runs the test target (Cmd‑U). Expected: all tests pass. Report results.

---

## Task 8: Connectivity + end-to-end verification

**Owner:** User (Xcode/networking) with Claude guidance.

The app needs to reach the API over the network from the simulator/device.

- [ ] **Step 1: Choose the base URL**

- **Easiest for a real end-to-end test:** point `Config.apiBaseURL` at your **deployed Forge host** (real, publicly-trusted TLS cert). Tell Claude the URL and Claude updates `Config.swift`.
- **Local DDEV (`https://ernte.ddev.site`):** the simulator can resolve it, but DDEV uses a locally-generated TLS cert the simulator won't trust by default → requests fail with a TLS error. To use DDEV from the simulator, install DDEV's mkcert root CA into the simulator (drag `"$(mkcert -CAROOT)/rootCA.pem"` onto the booted simulator, or `xcrun simctl keychain booted add-root-cert <path>`), then trust it in **Settings ▸ General ▸ About ▸ Certificate Trust Settings**.

- [ ] **Step 2: If needed, set the base URL**

If you pick the Forge host, give Claude the URL; Claude edits `Config.swift` and commits:
```bash
git add ErnteCompanion/Support/Config.swift
git commit -m "chore(ios): point API base URL at production host"
```

- [ ] **Step 3 (User): Run and verify the full flow** (Cmd‑R, simulator):
  1. App launches → Login screen.
  2. Enter your ernte credentials → **Sign In** → lands on the Timer tab.
  3. Tap **Start timer…**, pick a project, Start → a Running card appears with a live‑ticking elapsed time.
  4. **Switch project…** → pick another project → the running project changes.
  5. **Stop** → running card disappears; **Today ▸ Tracked** increases.
  6. Start again, then **Discard** → running card disappears with no time added.
  7. **Account ▸ Sign Out** → returns to Login. Relaunch → still on Login (token cleared).
  8. Sign in again, force-quit, relaunch → goes straight to Timer (token restored from Keychain).

- [ ] **Step 4: Report results.** Note any screen that misbehaves; Claude fixes and you re-verify.

**FINAL CHECKPOINT 8:** All eight flow steps pass. Slice 1b is complete.

---

## Self-Review Notes

- **Spec coverage:** Implements Slice 1b from the design — SwiftUI app, `APIClient`, Keychain, `Session`, Login, and the Timer tab (running display, start/switch/stop/discard, today totals), plus the login↔main view switch and logout. Projects/Billing tabs are intentionally **not** built here (Slice 2).
- **Execution model:** Because full Xcode is required and not drivable from this environment, the build/run/verify steps are owned by the user at the marked checkpoints; Claude authors and commits all Swift. The one piece of recurring manual bookkeeping is adding new files to the Xcode target (flagged in Task 0).
- **Type consistency:** `Session.api` is the single shared `APIClient`; view models reach it via `session.api`. DTO `CodingKeys` map every snake_case field. `TimerPayload` deliberately decodes only `totals`, `projects`, `running` (the fields the Timer tab uses); the other JSON keys are ignored, which is valid for `Decodable`.
- **Deferred / follow-ups:** Live elapsed ticks client-side from `started_at`; no background updates. No offline queue (per spec v1). The DDEV-cert friction is documented rather than worked around in code.
