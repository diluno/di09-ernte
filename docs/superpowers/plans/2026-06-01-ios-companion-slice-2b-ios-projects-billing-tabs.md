# iOS Companion — Slice 2b: iOS Projects + Billing Tabs Implementation Plan

> **For agentic workers:** Executed collaboratively (Claude authors + commits Swift; the user adds files to the Xcode target, builds, and runs at the BUILD CHECKPOINT). App repo: `/Users/sam/Documents/_projects/ernte`, target/module `ernte`. Steps use checkbox (`- [ ]`).

**Goal:** Add read-only **Projects** and **Billing** tabs to the iOS app, consuming the Slice 2 read API, so project status and invoice/estimate status are visible on the phone.

**Architecture:** Same light MVVM as Slice 1b. Extend `APIClient` with query-param support + six read methods. Add `@Observable` view models and SwiftUI list/detail screens. Add two tabs to `MainTabView`.

**Backend contract (already shipped, Slice 2a):**
- `GET /api/projects?filter=&q=` → `{ projects:[…], stats:{…}, counts:{…} }`
- `GET /api/projects/{code}` → `{ project:{…}, tasks:[…], recent_entries:[…], heatmap:[…], counts:{…} }`
- `GET /api/invoices?filter=&q=` → `{ invoices:{current_page,data:[…],last_page,total}, stats:{…} }`
- `GET /api/invoices/{number}` → invoice detail object
- `GET /api/estimates?filter=&q=` → `{ estimates:{…paginator…} }`
- `GET /api/estimates/{number}` → estimate detail object

**Critical decoding note:** list/detail date fields (`issued_on`, `due_on`, `valid_until`, `started_on`, `deadline_on`) are **date-only strings** (`"2026-06-01"`). The shared `JSONDecoder` uses `.iso8601`, which does NOT parse date-only strings — so these DTO fields are typed **`String`** (displayed as-is), not `Date`. Only `RunningEntry.startedAt` (full ISO‑8601) stays a `Date`.

---

## Task 1: Format helper + status/billing DTOs

**Files (Claude creates):**
- `ernte/Support/Format.swift`
- `ernte/Models/StatusDTOs.swift`

- [ ] **Step 1: `Support/Format.swift`**

```swift
import Foundation

enum Format {
    static func money(_ value: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .decimal
        f.minimumFractionDigits = 2
        f.maximumFractionDigits = 2
        return "CHF " + (f.string(from: NSNumber(value: value)) ?? String(format: "%.2f", value))
    }

    static func hours(_ value: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .decimal
        f.maximumFractionDigits = 1
        return (f.string(from: NSNumber(value: value)) ?? "\(value)") + " h"
    }

    static func duration(_ seconds: Int) -> String {
        String(format: "%d:%02d", seconds / 3600, (seconds % 3600) / 60)
    }
}
```

- [ ] **Step 2: `Models/StatusDTOs.swift`** (see the date-as-String note above)

```swift
import Foundation

struct ClientRef: Codable, Equatable, Hashable {
    let id: Int
    let name: String
}

// MARK: - Projects list

struct ProjectsResponse: Codable {
    let projects: [ProjectListItem]
    let stats: ProjectStats
    let counts: ProjectCounts
}

struct ProjectCounts: Codable { let active: Int; let all: Int; let archived: Int }

struct ProjectStats: Codable {
    let active: Int
    let weekHours: Double
    let unbilledAmount: Double
    let outstandingAmount: Double
    enum CodingKeys: String, CodingKey {
        case active
        case weekHours = "week_hours"
        case unbilledAmount = "unbilled_amount"
        case outstandingAmount = "outstanding_amount"
    }
}

struct ProjectListItem: Codable, Identifiable {
    let id: Int
    let code: String
    let name: String
    let status: String
    let spentHours: Double
    let budgetHours: Int
    let pctHours: Int
    let band: String
    let sparkline: [Double]
    let client: ClientRef
    enum CodingKeys: String, CodingKey {
        case id, code, name, status, band, sparkline, client
        case spentHours = "spent_hours"
        case budgetHours = "budget_hours"
        case pctHours = "pct_hours"
    }
}

// MARK: - Project detail

struct ProjectDetailResponse: Codable {
    let project: ProjectDetailItem
    let tasks: [ProjectTask]
    let recentEntries: [ProjectEntry]
    let heatmap: [Double]
    enum CodingKeys: String, CodingKey {
        case project, tasks, heatmap
        case recentEntries = "recent_entries"
    }
}

struct ProjectDetailItem: Codable {
    let id: Int
    let name: String
    let code: String
    let status: String
    let description: String?
    let billable: Bool
    let rate: Int
    let budgetHours: Int
    let spentHours: Double
    let spentAmount: Double
    let pctHours: Int
    let band: String
    let startedOn: String?
    let deadlineOn: String?
    let client: ClientRef
    enum CodingKeys: String, CodingKey {
        case id, name, code, status, description, billable, rate, band, client
        case budgetHours = "budget_hours"
        case spentHours = "spent_hours"
        case spentAmount = "spent_amount"
        case pctHours = "pct_hours"
        case startedOn = "started_on"
        case deadlineOn = "deadline_on"
    }
}

struct ProjectTask: Codable, Identifiable {
    let id: Int
    let name: String
    let done: Bool
    let budgetHours: Int
    let spentHours: Double
    enum CodingKeys: String, CodingKey {
        case id, name, done
        case budgetHours = "budget_hours"
        case spentHours = "spent_hours"
    }
}

struct ProjectEntry: Codable, Identifiable {
    let id: Int
    let description: String
    let taskName: String?
    let durationSeconds: Int
    let billable: Bool
    enum CodingKeys: String, CodingKey {
        case id, description, billable
        case taskName = "task_name"
        case durationSeconds = "duration_seconds"
    }
}

// MARK: - Pagination

struct Paginated<T: Codable>: Codable {
    let data: [T]
    let currentPage: Int
    let lastPage: Int
    let total: Int
    enum CodingKeys: String, CodingKey {
        case data, total
        case currentPage = "current_page"
        case lastPage = "last_page"
    }
}

struct BillingLine: Codable, Identifiable {
    let id: Int
    let description: String
    let hours: Double
    let rate: Int
    let amount: Double
}

// MARK: - Invoices

struct InvoicesResponse: Codable {
    let invoices: Paginated<InvoiceListItem>
    let stats: InvoiceStats
}

struct InvoiceStats: Codable {
    let outstanding: Double
    let overdue: Double
    let paidYtd: Double
    let count: Int
    enum CodingKeys: String, CodingKey {
        case outstanding, overdue, count
        case paidYtd = "paid_ytd"
    }
}

struct InvoiceListItem: Codable, Identifiable {
    let id: Int
    let number: String
    let title: String?
    let status: String
    let overdue: Bool
    let total: Double
    let hours: Double
    let client: ClientRef
    enum CodingKeys: String, CodingKey {
        case id, number, title, status, overdue, total, hours, client
    }
}

struct InvoiceDetail: Codable {
    let id: Int
    let number: String
    let status: String
    let overdue: Bool
    let title: String?
    let client: ClientRef
    let issuedOn: String?
    let dueOn: String?
    let subtotal: Double
    let vat: Double
    let total: Double
    let vatRate: Double
    let notes: String?
    let lines: [BillingLine]
    enum CodingKeys: String, CodingKey {
        case id, number, status, overdue, title, client, subtotal, vat, total, notes, lines
        case issuedOn = "issued_on"
        case dueOn = "due_on"
        case vatRate = "vat_rate"
    }
}

// MARK: - Estimates

struct EstimatesResponse: Codable {
    let estimates: Paginated<EstimateListItem>
}

struct EstimateListItem: Codable, Identifiable {
    let id: Int
    let number: String
    let title: String?
    let status: String
    let expired: Bool
    let total: Double
    let hours: Double
    let client: ClientRef
    enum CodingKeys: String, CodingKey {
        case id, number, title, status, expired, total, hours, client
    }
}

struct EstimateDetail: Codable {
    let id: Int
    let number: String
    let status: String
    let expired: Bool
    let title: String?
    let client: ClientRef
    let issuedOn: String?
    let validUntil: String?
    let subtotal: Double
    let vat: Double
    let total: Double
    let vatRate: Double
    let notes: String?
    let lines: [BillingLine]
    enum CodingKeys: String, CodingKey {
        case id, number, status, expired, title, client, subtotal, vat, total, notes, lines
        case issuedOn = "issued_on"
        case validUntil = "valid_until"
        case vatRate = "vat_rate"
    }
}
```

- [ ] **Step 3 (User): add `Format.swift` and `StatusDTOs.swift` to the target.** (Or do all file-adds at the single checkpoint in Task 6.)

- [ ] **Step 4: Commit**
```bash
git add ernte/Support/Format.swift ernte/Models/StatusDTOs.swift
git commit -m "feat(ios): format helper + projects/billing DTOs"
```

---

## Task 2: Extend `APIClient` with query support + read methods

**Files (Claude modifies):** `ernte/Networking/APIClient.swift`

- [ ] **Step 1: Add query-param support to the core `request` method**

Change the signature and URL construction. Replace the current `request<T>` signature line and its first two lines:

```swift
    private func request<T: Decodable>(
        _ method: String,
        _ path: String,
        query: [String: String]? = nil,
        body: [String: Any]? = nil,
        authorized: Bool = true
    ) async throws -> T {
        var components = URLComponents(url: baseURL.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        if let query, !query.isEmpty {
            components.queryItems = query.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        var req = URLRequest(url: components.url!)
```

(The rest of the method body — headers, body, `session.data`, status handling — stays exactly the same. Just replace the `var req = URLRequest(url: baseURL.appendingPathComponent(path))` line with the URLComponents block above.)

- [ ] **Step 2: Add the six read methods** (after `discardTimer()`):

```swift
    // MARK: - Projects

    func projects(filter: String = "active", search: String? = nil) async throws -> ProjectsResponse {
        var query = ["filter": filter]
        if let search, !search.isEmpty { query["q"] = search }
        return try await request("GET", "/api/projects", query: query)
    }

    func projectDetail(code: String) async throws -> ProjectDetailResponse {
        try await request("GET", "/api/projects/\(code)")
    }

    // MARK: - Billing

    func invoices(filter: String = "all", search: String? = nil) async throws -> InvoicesResponse {
        var query = ["filter": filter]
        if let search, !search.isEmpty { query["q"] = search }
        return try await request("GET", "/api/invoices", query: query)
    }

    func invoiceDetail(number: String) async throws -> InvoiceDetail {
        try await request("GET", "/api/invoices/\(number)")
    }

    func estimates(filter: String = "all", search: String? = nil) async throws -> EstimatesResponse {
        var query = ["filter": filter]
        if let search, !search.isEmpty { query["q"] = search }
        return try await request("GET", "/api/estimates", query: query)
    }

    func estimateDetail(number: String) async throws -> EstimateDetail {
        try await request("GET", "/api/estimates/\(number)")
    }
```

- [ ] **Step 3: Commit**
```bash
git add ernte/Networking/APIClient.swift
git commit -m "feat(ios): APIClient query support + projects/billing read methods"
```

**BUILD CHECKPOINT note:** This compiles independently; full verification happens at Task 6.

---

## Task 3: Projects feature

**Files (Claude creates):**
- `ernte/Features/Projects/ProjectsViewModel.swift`
- `ernte/Features/Projects/ProjectsView.swift`
- `ernte/Features/Projects/ProjectDetailView.swift`

- [ ] **Step 1: `ProjectsViewModel.swift`**

```swift
import Foundation
import Observation

@Observable
@MainActor
final class ProjectsViewModel {
    var response: ProjectsResponse?
    var isLoading = false
    var errorMessage: String?

    private let session: Session
    init(session: Session) { self.session = session }

    var projects: [ProjectListItem] { response?.projects ?? [] }

    func load() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            response = try await session.api.projects()
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

- [ ] **Step 2: `ProjectsView.swift`**

```swift
import SwiftUI

struct ProjectsView: View {
    @State private var model: ProjectsViewModel
    let session: Session

    init(session: Session) {
        self.session = session
        _model = State(initialValue: ProjectsViewModel(session: session))
    }

    var body: some View {
        NavigationStack {
            List {
                if let stats = model.response?.stats {
                    Section("This week") {
                        LabeledContent("Tracked", value: Format.hours(stats.weekHours))
                        LabeledContent("Unbilled", value: Format.money(stats.unbilledAmount))
                        LabeledContent("Outstanding", value: Format.money(stats.outstandingAmount))
                    }
                }
                Section("Active projects") {
                    ForEach(model.projects) { project in
                        NavigationLink(value: project.code) {
                            ProjectRow(project: project)
                        }
                    }
                }
                if let error = model.errorMessage {
                    Section { Text(error).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Projects")
            .navigationDestination(for: String.self) { code in
                ProjectDetailView(session: session, code: code)
            }
            .overlay { if model.isLoading && model.response == nil { ProgressView() } }
            .refreshable { await model.load() }
            .task { await model.load() }
        }
    }
}

private struct ProjectRow: View {
    let project: ProjectListItem

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(project.name).font(.headline)
            Text(project.client.name).font(.subheadline).foregroundStyle(.secondary)
            HStack {
                Text(Format.hours(project.spentHours))
                if project.budgetHours > 0 {
                    Text("· \(project.pctHours)% of \(project.budgetHours)h")
                        .foregroundStyle(bandColor)
                }
            }
            .font(.caption)
            .foregroundStyle(.secondary)
        }
    }

    private var bandColor: Color {
        switch project.band {
        case "over": return .red
        case "warn": return .orange
        default: return .secondary
        }
    }
}
```

- [ ] **Step 3: `ProjectDetailView.swift`** (loads detail on appear)

```swift
import SwiftUI

struct ProjectDetailView: View {
    let session: Session
    let code: String

    @State private var detail: ProjectDetailResponse?
    @State private var errorMessage: String?

    var body: some View {
        List {
            if let project = detail?.project {
                Section {
                    LabeledContent("Client", value: project.client.name)
                    LabeledContent("Status", value: project.status.capitalized)
                    LabeledContent("Spent", value: Format.hours(project.spentHours))
                    if project.budgetHours > 0 {
                        LabeledContent("Budget", value: "\(project.budgetHours) h (\(project.pctHours)%)")
                    }
                    LabeledContent("Billed value", value: Format.money(project.spentAmount))
                }
                if let description = project.description, !description.isEmpty {
                    Section("Notes") { Text(description) }
                }
            }
            if let tasks = detail?.tasks, !tasks.isEmpty {
                Section("Tasks") {
                    ForEach(tasks) { task in
                        HStack {
                            Image(systemName: task.done ? "checkmark.circle.fill" : "circle")
                                .foregroundStyle(task.done ? .green : .secondary)
                            Text(task.name)
                            Spacer()
                            Text(Format.hours(task.spentHours)).foregroundStyle(.secondary)
                        }
                    }
                }
            }
            if let entries = detail?.recentEntries, !entries.isEmpty {
                Section("Recent entries") {
                    ForEach(entries) { entry in
                        VStack(alignment: .leading, spacing: 2) {
                            Text(entry.description.isEmpty ? "(no description)" : entry.description)
                            HStack {
                                if let task = entry.taskName, !task.isEmpty {
                                    Text(task)
                                }
                                Spacer()
                                Text(Format.duration(entry.durationSeconds))
                            }
                            .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
            }
            if let error = errorMessage {
                Section { Text(error).foregroundStyle(.red) }
            }
        }
        .navigationTitle(detail?.project.name ?? code)
        .navigationBarTitleDisplayMode(.inline)
        .overlay { if detail == nil && errorMessage == nil { ProgressView() } }
        .task { await load() }
    }

    private func load() async {
        do {
            detail = try await session.api.projectDetail(code: code)
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

- [ ] **Step 4: Commit**
```bash
git add ernte/Features/Projects
git commit -m "feat(ios): projects tab — list, stats, and detail"
```

---

## Task 4: Billing feature (invoices + estimates)

**Files (Claude creates):**
- `ernte/Features/Billing/BillingViewModel.swift`
- `ernte/Features/Billing/BillingView.swift`
- `ernte/Features/Billing/InvoiceDetailView.swift`
- `ernte/Features/Billing/EstimateDetailView.swift`

- [ ] **Step 1: `BillingViewModel.swift`**

```swift
import Foundation
import Observation

@Observable
@MainActor
final class BillingViewModel {
    var invoices: InvoicesResponse?
    var estimates: EstimatesResponse?
    var isLoading = false
    var errorMessage: String?

    private let session: Session
    init(session: Session) { self.session = session }

    func loadInvoices() async {
        await run { self.invoices = try await self.session.api.invoices() }
    }

    func loadEstimates() async {
        await run { self.estimates = try await self.session.api.estimates() }
    }

    private func run(_ operation: @escaping () async throws -> Void) async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            try await operation()
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

- [ ] **Step 2: `BillingView.swift`** (segmented Invoices/Estimates)

```swift
import SwiftUI

struct BillingView: View {
    enum Segment: String, CaseIterable { case invoices = "Invoices"; case estimates = "Estimates" }

    let session: Session
    @State private var model: BillingViewModel
    @State private var segment: Segment = .invoices

    init(session: Session) {
        self.session = session
        _model = State(initialValue: BillingViewModel(session: session))
    }

    var body: some View {
        NavigationStack {
            List {
                if segment == .invoices {
                    invoiceContent
                } else {
                    estimateContent
                }
                if let error = model.errorMessage {
                    Section { Text(error).foregroundStyle(.red) }
                }
            }
            .navigationTitle("Billing")
            .navigationDestination(for: InvoiceListItem.self) { inv in
                InvoiceDetailView(session: session, number: inv.number)
            }
            .navigationDestination(for: EstimateListItem.self) { est in
                EstimateDetailView(session: session, number: est.number)
            }
            .safeAreaInset(edge: .top) {
                Picker("", selection: $segment) {
                    ForEach(Segment.allCases, id: \.self) { Text($0.rawValue).tag($0) }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.vertical, 8)
                .background(.bar)
            }
            .overlay { if model.isLoading { ProgressView() } }
            .task(id: segment) { await loadCurrent() }
            .refreshable { await loadCurrent() }
        }
    }

    private func loadCurrent() async {
        if segment == .invoices { await model.loadInvoices() } else { await model.loadEstimates() }
    }

    @ViewBuilder private var invoiceContent: some View {
        if let stats = model.invoices?.stats {
            Section("Summary") {
                LabeledContent("Outstanding", value: Format.money(stats.outstanding))
                LabeledContent("Overdue", value: Format.money(stats.overdue))
                LabeledContent("Paid (YTD)", value: Format.money(stats.paidYtd))
            }
        }
        Section {
            ForEach(model.invoices?.invoices.data ?? []) { inv in
                NavigationLink(value: inv) {
                    BillingRow(number: inv.number, title: inv.title, client: inv.client.name,
                               total: inv.total, status: inv.status, flagged: inv.overdue, flag: "OVERDUE")
                }
            }
        }
    }

    @ViewBuilder private var estimateContent: some View {
        Section {
            ForEach(model.estimates?.estimates.data ?? []) { est in
                NavigationLink(value: est) {
                    BillingRow(number: est.number, title: est.title, client: est.client.name,
                               total: est.total, status: est.status, flagged: est.expired, flag: "EXPIRED")
                }
            }
        }
    }
}

private struct BillingRow: View {
    let number: String
    let title: String?
    let client: String
    let total: Double
    let status: String
    let flagged: Bool
    let flag: String

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack {
                Text(number).font(.headline)
                Spacer()
                Text(Format.money(total)).font(.subheadline)
            }
            Text(client).font(.subheadline).foregroundStyle(.secondary)
            HStack {
                Text(status.capitalized)
                if flagged { Text(flag).foregroundStyle(.red) }
            }
            .font(.caption).foregroundStyle(.secondary)
        }
    }
}
```

- [ ] **Step 3: `InvoiceDetailView.swift`**

```swift
import SwiftUI

struct InvoiceDetailView: View {
    let session: Session
    let number: String

    @State private var detail: InvoiceDetail?
    @State private var errorMessage: String?

    var body: some View {
        List {
            if let inv = detail {
                Section {
                    LabeledContent("Client", value: inv.client.name)
                    LabeledContent("Status", value: inv.status.capitalized)
                    if inv.overdue { LabeledContent("", value: "OVERDUE").foregroundStyle(.red) }
                    if let issued = inv.issuedOn { LabeledContent("Issued", value: issued) }
                    if let due = inv.dueOn { LabeledContent("Due", value: due) }
                }
                Section("Lines") {
                    ForEach(inv.lines) { line in
                        BillingLineRow(line: line)
                    }
                }
                Section {
                    LabeledContent("Subtotal", value: Format.money(inv.subtotal))
                    LabeledContent("VAT (\(Format.hours(inv.vatRate).replacingOccurrences(of: " h", with: ""))%)", value: Format.money(inv.vat))
                    LabeledContent("Total", value: Format.money(inv.total)).bold()
                }
                if let notes = inv.notes, !notes.isEmpty {
                    Section("Notes") { Text(notes) }
                }
            }
            if let error = errorMessage {
                Section { Text(error).foregroundStyle(.red) }
            }
        }
        .navigationTitle(number)
        .navigationBarTitleDisplayMode(.inline)
        .overlay { if detail == nil && errorMessage == nil { ProgressView() } }
        .task {
            do { detail = try await session.api.invoiceDetail(number: number) }
            catch APIError.unauthorized { session.handleUnauthorized() }
            catch let error as APIError { errorMessage = error.userMessage }
            catch { errorMessage = "Something went wrong." }
        }
    }
}

struct BillingLineRow: View {
    let line: BillingLine
    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(line.description)
            HStack {
                Text("\(Format.hours(line.hours)) × CHF \(line.rate)")
                Spacer()
                Text(Format.money(line.amount))
            }
            .font(.caption).foregroundStyle(.secondary)
        }
    }
}
```

- [ ] **Step 4: `EstimateDetailView.swift`**

```swift
import SwiftUI

struct EstimateDetailView: View {
    let session: Session
    let number: String

    @State private var detail: EstimateDetail?
    @State private var errorMessage: String?

    var body: some View {
        List {
            if let est = detail {
                Section {
                    LabeledContent("Client", value: est.client.name)
                    LabeledContent("Status", value: est.status.capitalized)
                    if est.expired { LabeledContent("", value: "EXPIRED").foregroundStyle(.red) }
                    if let issued = est.issuedOn { LabeledContent("Issued", value: issued) }
                    if let valid = est.validUntil { LabeledContent("Valid until", value: valid) }
                }
                Section("Lines") {
                    ForEach(est.lines) { line in
                        BillingLineRow(line: line)
                    }
                }
                Section {
                    LabeledContent("Subtotal", value: Format.money(est.subtotal))
                    LabeledContent("VAT", value: Format.money(est.vat))
                    LabeledContent("Total", value: Format.money(est.total)).bold()
                }
                if let notes = est.notes, !notes.isEmpty {
                    Section("Notes") { Text(notes) }
                }
            }
            if let error = errorMessage {
                Section { Text(error).foregroundStyle(.red) }
            }
        }
        .navigationTitle(number)
        .navigationBarTitleDisplayMode(.inline)
        .overlay { if detail == nil && errorMessage == nil { ProgressView() } }
        .task {
            do { detail = try await session.api.estimateDetail(number: number) }
            catch APIError.unauthorized { session.handleUnauthorized() }
            catch let error as APIError { errorMessage = error.userMessage }
            catch { errorMessage = "Something went wrong." }
        }
    }
}
```

- [ ] **Step 5: Commit**
```bash
git add ernte/Features/Billing
git commit -m "feat(ios): billing tab — invoices/estimates lists + detail views"
```

---

## Task 5: Wire the new tabs into `MainTabView`

**Files (Claude modifies):** `ernte/ContentView.swift`

- [ ] **Step 1: Replace the `MainTabView` struct** (Timer → Projects → Billing → Account)

```swift
struct MainTabView: View {
    let session: Session

    var body: some View {
        TabView {
            TimerView(session: session)
                .tabItem { Label("Timer", systemImage: "timer") }

            ProjectsView(session: session)
                .tabItem { Label("Projects", systemImage: "folder") }

            BillingView(session: session)
                .tabItem { Label("Billing", systemImage: "doc.text") }

            AccountView(session: session)
                .tabItem { Label("Account", systemImage: "person.crop.circle") }
        }
    }
}
```

- [ ] **Step 2: Commit**
```bash
git add ernte/ContentView.swift
git commit -m "feat(ios): add Projects and Billing tabs to the tab bar"
```

---

## Task 6: BUILD CHECKPOINT — add files, build, run, verify

**Owner:** User (Xcode).

- [ ] **Step 1: Add the new files to the `ernte` target**

In Xcode, right-click the `ernte` group → **Add Files to "ernte"…** → select the new files/folders:
- `Support/Format.swift`
- `Models/StatusDTOs.swift`
- `Features/Projects/` (3 files)
- `Features/Billing/` (4 files)

Ensure ✅ *Add to targets: ernte*, ❌ uncheck *Copy items if needed*. (`APIClient.swift` and `ContentView.swift` were modified in place — already in the target.)

- [ ] **Step 2: Build (⌘B).** If compile errors, paste them; Claude fixes.

- [ ] **Step 3: Run (⌘R, simulator) and verify against `ernte.dil.uno`:**
  1. **Projects** tab: "This week" stats + a list of active projects with hours/budget %.
  2. Tap a project → detail (status, spent/budget, tasks, recent entries).
  3. **Billing** tab: Invoices segment shows summary + invoice rows (number, client, total, status, OVERDUE flag).
  4. Tap an invoice → detail with line items + subtotal/VAT/total.
  5. Switch to **Estimates** segment → estimate rows; tap one → detail.
  6. Pull-to-refresh on each list.

- [ ] **Step 4: Report results.** Note anything off; Claude fixes and you re-verify.

**FINAL CHECKPOINT:** All steps pass → Slice 2b complete; the iOS companion now covers timer + status (projects + billing).

---

## Self-Review Notes

- **Spec coverage:** Implements Slice 2b — Projects list/detail and Billing (invoices + estimates) list/detail, read-only, matching the spec's "glanceable status" scope. Light actions (mark paid/sent, accept/decline) are Slice 3.
- **Decoding safety:** date-only fields are `String` (the `.iso8601` decoder can't parse them); only `RunningEntry.startedAt` stays `Date`. `Paginated<T>` decodes the index paginators; only page 1 (≤50 rows) is shown — acceptable for a mobile glance (a "load more" affordance can come later if needed).
- **Consistency:** view models mirror `TimerViewModel`'s error/401 handling (`session.handleUnauthorized()` on 401). Navigation uses value-based `navigationDestination`. Money/hours formatting centralized in `Format`.
- **Manual Xcode bookkeeping:** new files must be added to the target (Task 6, Step 1) — the one unavoidable manual step in our setup.
