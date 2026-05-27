/* Views for ernte — Dashboard, Project Detail, Timer, Clients, Invoices, Reports */

// ─────── Dashboard ───────
function Dashboard({ setRoute }) {
  const [filter, setFilter] = useState("active");
  const [sort, setSort] = useState("activity");
  const [search, setSearch] = useState("");

  const filtered = useMemo(() => {
    let list = PROJECTS.filter(p => filter === "all" ? true : p.status === filter);
    if (search) list = list.filter(p =>
      p.name.toLowerCase().includes(search.toLowerCase()) ||
      clientById(p.client).name.toLowerCase().includes(search.toLowerCase())
    );
    return list;
  }, [filter, search]);

  const totals = useMemo(() => {
    const active = PROJECTS.filter(p => p.status === "active").length;
    const week = 38.9;
    const unbilled = TODAY_ENTRIES.filter(e => e.billable).reduce((a, e) => a + e.dur / 3600, 0) + 17.2;
    const outstanding = INVOICES.filter(i => i.status === "sent" || i.status === "overdue").reduce((a, i) => a + i.total, 0);
    return { active, week, unbilled, outstanding };
  }, []);

  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">~ / projects</div>
          <h1 className="page-title">
            Projects
            <span className="meta">{filtered.length} of {PROJECTS.length}<span className="ascii-dot">·</span>updated 12m ago</span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ghost">Import</button>
          <button className="btn">Archive</button>
          <button className="btn primary">+ New project</button>
        </div>
      </div>

      <div className="stats">
        <div className="stat">
          <div className="label">Active projects</div>
          <div className="val">{totals.active}<span className="unit">running</span></div>
          <div className="delta up">▲ 1 vs last month</div>
        </div>
        <div className="stat">
          <div className="label">This week</div>
          <div className="val">{totals.week.toFixed(1)}<span className="unit">h / 40h</span></div>
          <Spark data={WEEK_HOURS} w={140} h={18} color="var(--forest)"/>
        </div>
        <div className="stat">
          <div className="label">Unbilled</div>
          <div className="val">{fmtMoneyShort(8420)}<span className="unit">· 58.5h</span></div>
          <div className="delta">across 4 clients</div>
        </div>
        <div className="stat">
          <div className="label">Outstanding</div>
          <div className="val" style={{ color: "var(--rust)" }}>{fmtMoneyShort(totals.outstanding)}</div>
          <div className="delta down">1 overdue · Kelp Forest Co.</div>
        </div>
      </div>

      <div className="filter-row">
        {[
          { id: "active", label: "Active", count: 8 },
          { id: "all", label: "All", count: 12 },
          { id: "archived", label: "Archived", count: 4 },
        ].map(c => (
          <button
            key={c.id}
            className="chip"
            aria-pressed={filter === c.id}
            onClick={() => setFilter(c.id)}
          >
            {c.label} <span className="dim" style={{ marginLeft: 4 }}>{c.count}</span>
          </button>
        ))}
        <span className="filter-divider"/>
        <button className="chip">Billable</button>
        <button className="chip">Over budget <span className="dim" style={{ marginLeft: 4 }}>2</span></button>
        <button className="chip">My time</button>
        <div className="search">
          <span style={{ color: "var(--ink-4)" }}>⌕</span>
          <input
            placeholder="filter…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <span className="kbd">/</span>
        </div>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th className="pad-l" style={{ width: 280 }}>Project</th>
              <th>Client</th>
              <th className="num" style={{ width: 90 }}>Rate</th>
              <th style={{ width: 260 }}>Hours budget</th>
              <th style={{ width: 240 }}>Fees budget</th>
              <th style={{ width: 130 }}>14-day</th>
              <th style={{ width: 90 }}>Status</th>
              <th className="pad-r num" style={{ width: 110 }}>Last activity</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((p, i) => {
              const cl = clientById(p.client);
              const hPct = pctOf(p.spent_hours, p.budget_hours);
              const band = budgetBand(hPct);
              return (
                <tr key={p.id} onClick={() => setRoute({ view: "project", id: p.id })}>
                  <td className="pad-l strong">
                    <div className="proj-cell">
                      <span className={`proj-glyph ${p.glyph}`}>{p.code[0]}</span>
                      <span>
                        {p.name}
                        <span className="dim" style={{ marginLeft: 8, fontWeight: 400 }}>{p.code}</span>
                      </span>
                    </div>
                  </td>
                  <td>{cl.name}</td>
                  <td className="num">{p.rate ? `€${p.rate}/h` : <span className="dim">—</span>}</td>
                  <td>
                    {p.budget_hours > 0 ? (
                      <BudgetCell spent={p.spent_hours} budget={p.budget_hours} unit="h"/>
                    ) : (
                      <span className="dim">no budget · {fmtHours(p.spent_hours)} logged</span>
                    )}
                  </td>
                  <td>
                    {p.budget_amount > 0 ? (
                      <BudgetCell spent={p.spent_amount} budget={p.budget_amount} unit="€"/>
                    ) : (
                      <span className="dim">non-billable</span>
                    )}
                  </td>
                  <td>
                    <Spark data={SPARKS[p.id] || [0]} w={120} h={20} color={band === "over" ? "var(--red)" : band === "warn" ? "var(--rust)" : "var(--forest)"}/>
                  </td>
                  <td>
                    {p.retainer ? (
                      <span className="badge dot active">retainer</span>
                    ) : band === "over" ? (
                      <span className="badge dot over">over</span>
                    ) : band === "warn" ? (
                      <span className="badge dot warn">at risk</span>
                    ) : (
                      <span className="badge dot active">active</span>
                    )}
                  </td>
                  <td className="pad-r num dim">{p.last_activity}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </React.Fragment>
  );
}

// ─────── Project Detail ───────
function ProjectDetail({ id, setRoute }) {
  const p = projectById(id);
  const cl = clientById(p.client);
  const tasks = TASKS_BY_PROJECT[id] || TASKS_BY_PROJECT["atlas-fleet"]; // fallback for demo
  const [tab, setTab] = useState("overview");

  const hPct = pctOf(p.spent_hours, p.budget_hours);
  const band = budgetBand(hPct);

  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">
            <span onClick={() => setRoute({ view: "dashboard" })} style={{ cursor: "pointer" }}>~ / projects</span>
            <span className="ascii-dot">/</span>
            <span>{p.code}</span>
          </div>
          <h1 className="page-title">
            <span className={`proj-glyph ${p.glyph}`} style={{ width: 28, height: 28, fontSize: 14 }}>{p.code[0]}</span>
            {p.name}
            <span className="meta">{cl.name}<span className="ascii-dot">·</span>€{p.rate}/h</span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ghost">⏵ Start timer</button>
          <button className="btn">Export</button>
          <button className="btn primary">+ Invoice</button>
        </div>
      </div>

      <div className="filter-row">
        {[
          { id: "overview", label: "Overview" },
          { id: "entries", label: "Entries", count: 142 },
          { id: "tasks", label: "Tasks", count: 7 },
          { id: "team", label: "Team", count: 1 },
          { id: "settings", label: "Settings" },
        ].map(t => (
          <button key={t.id} className="chip" aria-pressed={tab === t.id} onClick={() => setTab(t.id)}>
            {t.label} {t.count && <span className="dim" style={{ marginLeft: 4 }}>{t.count}</span>}
          </button>
        ))}
      </div>

      <div className="detail-grid">
        <div className="detail-main">
          <h3 className="section-title">Budget burn-down</h3>
          <BurnDown project={p}/>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 24, margin: "20px 0 28px" }}>
            <BurnStat label="Hours spent" v={fmtHours(p.spent_hours)} sub={`of ${fmtHours(p.budget_hours)}`} pct={hPct} band={band}/>
            <BurnStat label="Fees" v={fmtMoneyShort(p.spent_amount)} sub={`of ${fmtMoneyShort(p.budget_amount)}`} pct={pctOf(p.spent_amount, p.budget_amount)} band={band}/>
            <BurnStat label="Remaining" v={fmtHours(Math.max(0, p.budget_hours - p.spent_hours))} sub={band === "over" ? "exceeded" : "available"}/>
            <BurnStat label="Avg / week" v="14.2h" sub="last 4 weeks"/>
          </div>

          <h3 className="section-title">Tasks</h3>
          <div className="task-list">
            {tasks.map(t => (
              <div key={t.id} className="task-row">
                <div className={`task-check ${t.done ? "done" : ""}`}>{t.done && "✓"}</div>
                <div className={`task-name ${t.done ? "done" : ""}`}>{t.name}</div>
                <div className="task-num">{fmtHours(t.spent)} / {fmtHours(t.budget)}</div>
                <div className="task-num dim">{pctOf(t.spent, t.budget)}%</div>
                <div className="task-bar">
                  <div className="task-bar-fill" style={{
                    width: `${Math.min(100, pctOf(t.spent, t.budget))}%`,
                    background: pctOf(t.spent, t.budget) > 100 ? "var(--red)" : "var(--forest)",
                  }}/>
                </div>
              </div>
            ))}
            <button className="btn ghost" style={{ marginTop: 12, alignSelf: "flex-start" }}>+ Add task</button>
          </div>

          <h3 className="section-title" style={{ marginTop: 28 }}>Recent entries</h3>
          <div>
            {TODAY_ENTRIES.slice(0, 4).map((e, idx) => {
              const ep = projectById(e.project);
              return (
                <div key={e.id} className="entry-row">
                  <div className="bar-color" style={{ background: ["#2d4a3a","#c97b3c","#b8941f","#1a1a1a"][idx % 4] }}/>
                  <div className="desc">
                    {e.task}
                    <span className="sub">{ep.name} <span className="ascii-dot">·</span> Today</span>
                  </div>
                  <div className="time">{e.start} – {e.end || "running"}</div>
                  <div className="dur">{fmtHM(e.dur)}</div>
                  <div className={`billable ${e.billable ? "" : "no"}`}>{e.billable ? "billable" : "—"}</div>
                  <div className="time"><button className="icon-btn">⋯</button></div>
                </div>
              );
            })}
          </div>
        </div>

        <aside className="detail-side">
          <h3 className="section-title">Details</h3>
          <dl className="kv">
            <dt>Client</dt><dd>{cl.name}</dd>
            <dt>Code</dt><dd><span className="mono-tag">{p.code}</span></dd>
            <dt>Status</dt><dd><span className="badge dot active">active</span></dd>
            <dt>Started</dt><dd>{p.started}, 2026</dd>
            <dt>Due</dt><dd>{p.deadline}</dd>
            <dt>Billing</dt><dd>Hourly · €{p.rate}/h</dd>
            <dt>Currency</dt><dd>EUR</dd>
          </dl>

          <h3 className="section-title" style={{ marginTop: 24 }}>Description</h3>
          <p style={{ fontSize: "var(--fs-sm)", color: "var(--ink-2)", lineHeight: 1.6, margin: 0 }}>
            {p.description}
          </p>

          <h3 className="section-title" style={{ marginTop: 24 }}>Activity heatmap</h3>
          <div className="heat">
            {Array.from({ length: 60 }).map((_, i) => {
              const r = (Math.sin(i * 12.91) * 10000) % 1;
              const l = r > 0.6 ? 4 : r > 0.4 ? 3 : r > 0.2 ? 2 : r > 0.05 ? 1 : 0;
              return <div key={i} className={`sq ${l ? "l" + l : ""}`}/>;
            })}
          </div>
          <div style={{ fontSize: "var(--fs-xs)", color: "var(--ink-4)", marginTop: 8, display: "flex", justifyContent: "space-between" }}>
            <span>12 weeks ago</span><span>today</span>
          </div>

          <h3 className="section-title" style={{ marginTop: 24 }}>Quick log</h3>
          <button className="btn" style={{ width: "100%", justifyContent: "center" }}>+ Log time</button>
        </aside>
      </div>
    </React.Fragment>
  );
}

function BurnStat({ label, v, sub, pct, band }) {
  return (
    <div>
      <div style={{ fontSize: "var(--fs-xs)", letterSpacing: ".06em", textTransform: "uppercase", color: "var(--ink-3)" }}>{label}</div>
      <div style={{ fontSize: "var(--fs-xl)", fontWeight: 600, fontVariantNumeric: "tabular-nums", marginTop: 4, color: band === "over" ? "var(--red)" : "var(--ink)" }}>
        {v}
      </div>
      <div style={{ fontSize: "var(--fs-xs)", color: "var(--ink-3)", marginTop: 2 }}>{sub}</div>
    </div>
  );
}

function BurnDown({ project }) {
  // Synthetic burn data
  const days = 60;
  const budget = project.budget_hours;
  const spent = project.spent_hours;
  const ideal = Array.from({ length: days }).map((_, i) => budget - (budget / days) * i);
  const actual = Array.from({ length: days }).map((_, i) => {
    const progress = i / days;
    const burnFactor = spent / budget;
    return Math.max(0, budget - budget * progress * burnFactor * (1 + Math.sin(i * 0.4) * 0.05));
  });
  const w = 720, h = 140, pad = 20;
  const xs = (i) => pad + (i / (days - 1)) * (w - pad * 2);
  const ys = (v) => pad + (1 - v / budget) * (h - pad * 2);
  const idealPath = ideal.map((v, i) => `${i === 0 ? "M" : "L"}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(" ");
  const actualPath = actual.map((v, i) => `${i === 0 ? "M" : "L"}${xs(i).toFixed(1)},${ys(v).toFixed(1)}`).join(" ");
  const todayIdx = Math.floor(actual.length * (spent / budget) * 1.05);
  return (
    <div className="burndown">
      <svg viewBox={`0 0 ${w} ${h}`} width="100%" height={h} preserveAspectRatio="none">
        {/* gridlines */}
        {[0.25, 0.5, 0.75].map(p => (
          <line key={p} x1={pad} x2={w - pad} y1={pad + p * (h - pad * 2)} y2={pad + p * (h - pad * 2)} stroke="var(--border)" strokeDasharray="2 4"/>
        ))}
        <line x1={pad} x2={w - pad} y1={h - pad} y2={h - pad} stroke="var(--border)"/>
        <line x1={pad} x2={pad} y1={pad} y2={h - pad} stroke="var(--border)"/>
        {/* ideal */}
        <path d={idealPath} fill="none" stroke="var(--ink-4)" strokeWidth="1" strokeDasharray="4 4"/>
        {/* actual */}
        <path d={actualPath} fill="none" stroke="var(--forest)" strokeWidth="1.5"/>
        {/* today marker */}
        {todayIdx < days && (
          <React.Fragment>
            <line x1={xs(todayIdx)} x2={xs(todayIdx)} y1={pad} y2={h - pad} stroke="var(--rust)" strokeDasharray="2 2"/>
            <circle cx={xs(todayIdx)} cy={ys(actual[todayIdx])} r="3" fill="var(--rust)"/>
          </React.Fragment>
        )}
      </svg>
      <div style={{ position: "absolute", top: 6, left: 8, fontSize: "var(--fs-xs)", color: "var(--ink-4)", display: "flex", gap: 14 }}>
        <span><span style={{ display: "inline-block", width: 10, height: 1.5, background: "var(--forest)", verticalAlign: "middle", marginRight: 4 }}/>actual</span>
        <span><span style={{ display: "inline-block", width: 10, borderTop: "1px dashed var(--ink-4)", verticalAlign: "middle", marginRight: 4 }}/>ideal</span>
        <span><span style={{ display: "inline-block", width: 6, height: 6, background: "var(--rust)", borderRadius: "50%", verticalAlign: "middle", marginRight: 4 }}/>today</span>
      </div>
    </div>
  );
}

// ─────── Timer / Today View ───────
function TimerView({ running, setRunning }) {
  const [entries, setEntries] = useState(TODAY_ENTRIES);
  const [isPaused, setPaused] = useState(false);
  const proj = projectById(running.project);

  const totalToday = entries.reduce((a, e) => a + e.dur, 0);
  const billableToday = entries.filter(e => e.billable).reduce((a, e) => a + e.dur, 0);

  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">~ / timer</div>
          <h1 className="page-title">
            Today
            <span className="meta">Tue · May 27, 2026<span className="ascii-dot">·</span>{fmtHM(totalToday)} logged</span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ghost">Week view</button>
          <button className="btn">+ Manual entry</button>
        </div>
      </div>

      <div className="timer-stage">
        <div>
          <div className="timer-hero">
            <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between" }}>
              <div>
                <div style={{ fontSize: "var(--fs-xs)", letterSpacing: ".06em", textTransform: "uppercase", color: "var(--ink-3)" }}>
                  Running · {proj.name}
                </div>
                <div style={{ marginTop: 12, color: "var(--ink)", fontSize: "var(--fs-md)" }}>
                  {running.task}
                </div>
              </div>
              <div style={{ display: "flex", alignItems: "center", gap: 6, color: "var(--rust)", fontSize: "var(--fs-sm)" }}>
                <span className="pulse" style={{ width: 6, height: 6, borderRadius: "50%", background: "var(--rust)" }}/>
                started 16:30
              </div>
            </div>

            <div className="timer-display" style={{ marginTop: 18 }}>
              {fmtHM(running.seconds).slice(0, 5)}
              <span className="ms">:{fmtHM(running.seconds).slice(6)}</span>
            </div>

            <div className="timer-meta">
              <span>billable · €{(running.seconds / 3600 * 145).toFixed(2)}</span>
              <span className="ascii-dot">·</span>
              <span>rate €145/h</span>
              <span className="ascii-dot">·</span>
              <span>{proj.code}</span>
            </div>

            <div style={{ marginTop: 20, display: "flex", gap: 8 }}>
              <button className="btn primary" onClick={() => setPaused(!isPaused)} style={{ minWidth: 120 }}>
                {isPaused ? "▶ resume" : "■ stop"}
              </button>
              <button className="btn">switch project</button>
              <button className="btn ghost">discard</button>
              <span style={{ flex: 1 }}/>
              <span className="kbd" style={{ alignSelf: "center" }}>space</span>
              <span className="muted" style={{ alignSelf: "center", fontSize: "var(--fs-xs)" }}>to toggle</span>
            </div>
          </div>

          <div className="divider-row">Today's entries · 6</div>
          {entries.map((e, idx) => {
            const ep = projectById(e.project);
            const color = ["#2d4a3a","#c97b3c","#b8941f","#1a1a1a","#7a8c5c","#b54834"][idx % 6];
            return (
              <div key={e.id} className="entry-row">
                <div className="bar-color" style={{ background: color }}/>
                <div className="desc">
                  {e.task}
                  <span className="sub">{ep.name} <span className="ascii-dot">·</span> <span className="mono-tag" style={{ padding: "0 4px" }}>{ep.code}</span></span>
                </div>
                <div className="time">{e.start} – {e.end || <span style={{ color: "var(--rust)" }}>now</span>}</div>
                <div className="dur">{fmtHM(e.dur)}</div>
                <div className={`billable ${e.billable ? "" : "no"}`}>{e.billable ? "billable" : "—"}</div>
                <div><button className="icon-btn">⋯</button></div>
              </div>
            );
          })}
        </div>

        <aside>
          <h3 className="section-title">Today summary</h3>
          <div style={{ border: "1px solid var(--border)", padding: 16, marginBottom: 18 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline" }}>
              <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>TOTAL</span>
              <span style={{ fontSize: "var(--fs-xl)", fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>{fmtHM(totalToday)}</span>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", marginTop: 8 }}>
              <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>BILLABLE</span>
              <span style={{ fontSize: "var(--fs-md)", color: "var(--forest)", fontVariantNumeric: "tabular-nums" }}>{fmtHM(billableToday)}</span>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", marginTop: 4 }}>
              <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>EARNINGS</span>
              <span style={{ fontSize: "var(--fs-md)", color: "var(--ink)", fontVariantNumeric: "tabular-nums" }}>€{(billableToday / 3600 * 140).toFixed(0)}</span>
            </div>
          </div>

          <h3 className="section-title">By project</h3>
          {Object.entries(entries.reduce((a, e) => {
            a[e.project] = (a[e.project] || 0) + e.dur; return a;
          }, {})).map(([pid, dur]) => {
            const p = projectById(pid);
            const pct = (dur / totalToday) * 100;
            return (
              <div key={pid} style={{ marginBottom: 12 }}>
                <div style={{ display: "flex", justifyContent: "space-between", fontSize: "var(--fs-sm)" }}>
                  <span>{p.name}</span>
                  <span style={{ fontVariantNumeric: "tabular-nums" }}>{fmtHM(dur)}</span>
                </div>
                <div className="budget-bar" style={{ marginTop: 4 }}>
                  <div className="budget-fill" style={{ width: `${pct}%`, background: "var(--accent)" }}/>
                </div>
              </div>
            );
          })}

          <h3 className="section-title" style={{ marginTop: 24 }}>Quick start</h3>
          <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
            {PROJECTS.slice(0, 4).map(p => (
              <button key={p.id} className="btn ghost" style={{ justifyContent: "flex-start", padding: "6px 8px" }}>
                <span className={`proj-glyph ${p.glyph}`} style={{ width: 12, height: 12, fontSize: 8 }}>{p.code[0]}</span>
                <span style={{ fontSize: "var(--fs-sm)" }}>{p.name}</span>
              </button>
            ))}
          </div>

          <h3 className="section-title" style={{ marginTop: 24 }}>Shortcuts</h3>
          <div style={{ display: "grid", gridTemplateColumns: "1fr auto", gap: "6px 12px", fontSize: "var(--fs-xs)", color: "var(--ink-3)" }}>
            <span>Start / stop timer</span><span className="kbd">space</span>
            <span>New entry</span><span className="kbd">n</span>
            <span>Switch project</span><span className="kbd">⌘P</span>
            <span>Jump to dashboard</span><span className="kbd">g d</span>
          </div>
        </aside>
      </div>
    </React.Fragment>
  );
}

// ─────── Clients View ───────
function ClientsView({ setRoute }) {
  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">~ / clients</div>
          <h1 className="page-title">
            Clients
            <span className="meta">{CLIENTS.length} accounts<span className="ascii-dot">·</span>€13,330 outstanding</span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn">Export CSV</button>
          <button className="btn primary">+ New client</button>
        </div>
      </div>

      <div className="filter-row">
        <button className="chip" aria-pressed="true">All <span className="dim">{CLIENTS.length}</span></button>
        <button className="chip">With balance <span className="dim">3</span></button>
        <button className="chip">Archived <span className="dim">2</span></button>
        <div className="search">
          <span style={{ color: "var(--ink-4)" }}>⌕</span>
          <input placeholder="filter…"/>
        </div>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th className="pad-l" style={{ width: 280 }}>Client</th>
              <th>Contact</th>
              <th className="num" style={{ width: 90 }}>Default rate</th>
              <th className="num" style={{ width: 90 }}>Projects</th>
              <th className="num" style={{ width: 110 }}>Hours YTD</th>
              <th className="num" style={{ width: 130 }}>Outstanding</th>
              <th className="pad-r" style={{ width: 150 }}>Activity</th>
            </tr>
          </thead>
          <tbody>
            {CLIENTS.map((c, i) => (
              <tr key={c.id}>
                <td className="pad-l strong">
                  <div className="proj-cell">
                    <span className={`proj-glyph ${["", "alt-1", "alt-2", "alt-3", "alt-4", "alt-2"][i]}`}>{c.short[0]}</span>
                    <span>{c.name}</span>
                  </div>
                </td>
                <td>
                  {c.contact === "—" ? <span className="dim">—</span> : (
                    <React.Fragment>
                      {c.contact} <span className="dim" style={{ marginLeft: 4 }}>{c.email}</span>
                    </React.Fragment>
                  )}
                </td>
                <td className="num">{c.rate ? `€${c.rate}/h` : <span className="dim">—</span>}</td>
                <td className="num">{c.projects}</td>
                <td className="num">{c.hours_ytd}h</td>
                <td className="num strong" style={{ color: c.outstanding > 0 ? "var(--rust)" : "var(--ink-3)" }}>
                  {c.outstanding > 0 ? fmtMoneyShort(c.outstanding) : <span className="dim">—</span>}
                </td>
                <td className="pad-r">
                  <Spark data={[2,3,1,4,5,2,3,4,5,6,5,4,3,5]} w={110} h={20} color="var(--ink-3)"/>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </React.Fragment>
  );
}

// ─────── Invoices ───────
function InvoicesView({ setRoute }) {
  const total = INVOICES.reduce((a, i) => a + i.total, 0);
  const outstanding = INVOICES.filter(i => i.status === "sent" || i.status === "overdue").reduce((a, i) => a + i.total, 0);
  const overdue = INVOICES.filter(i => i.status === "overdue").reduce((a, i) => a + i.total, 0);
  const paid = INVOICES.filter(i => i.status === "paid").reduce((a, i) => a + i.total, 0);

  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">~ / invoices</div>
          <h1 className="page-title">
            Invoices
            <span className="meta">{INVOICES.length} total<span className="ascii-dot">·</span>FY 2026</span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ghost">Export PDFs</button>
          <button className="btn">Recurring</button>
          <button className="btn primary">+ New invoice</button>
        </div>
      </div>

      <div className="stats">
        <div className="stat">
          <div className="label">Outstanding</div>
          <div className="val" style={{ color: "var(--rust)" }}>{fmtMoneyShort(outstanding)}</div>
          <div className="delta">2 invoices</div>
        </div>
        <div className="stat">
          <div className="label">Overdue</div>
          <div className="val" style={{ color: "var(--red)" }}>{fmtMoneyShort(overdue)}</div>
          <div className="delta down">Kelp Forest Co. · 1 day</div>
        </div>
        <div className="stat">
          <div className="label">Paid YTD</div>
          <div className="val">{fmtMoneyShort(paid)}</div>
          <Spark data={[12,18,22,15,28,32,38,42]} w={140} h={18} color="var(--forest)"/>
        </div>
        <div className="stat">
          <div className="label">Avg days to pay</div>
          <div className="val">11<span className="unit">days</span></div>
          <div className="delta up">▼ 2 days vs last quarter</div>
        </div>
      </div>

      <div className="filter-row">
        <button className="chip" aria-pressed="true">All <span className="dim">8</span></button>
        <button className="chip">Draft <span className="dim">1</span></button>
        <button className="chip">Sent <span className="dim">1</span></button>
        <button className="chip">Overdue <span className="dim">1</span></button>
        <button className="chip">Paid <span className="dim">5</span></button>
        <div className="search">
          <span style={{ color: "var(--ink-4)" }}>⌕</span>
          <input placeholder="filter…"/>
        </div>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th className="pad-l" style={{ width: 140 }}>Invoice</th>
              <th style={{ width: 240 }}>Client</th>
              <th className="num" style={{ width: 100 }}>Issued</th>
              <th className="num" style={{ width: 100 }}>Due</th>
              <th className="num" style={{ width: 80 }}>Hours</th>
              <th className="num" style={{ width: 120 }}>Total</th>
              <th style={{ width: 120 }}>Status</th>
              <th className="pad-r" style={{ width: 80 }}></th>
            </tr>
          </thead>
          <tbody>
            {INVOICES.map(inv => {
              const cl = clientById(inv.client);
              return (
                <tr key={inv.id} onClick={() => setRoute({ view: "invoice", id: inv.id })}>
                  <td className="pad-l strong"><span className="mono-tag" style={{ padding: "2px 6px", color: "var(--ink)", borderColor: "var(--border-strong)" }}>#{inv.id}</span></td>
                  <td>{cl.name}</td>
                  <td className="num">{inv.issued}</td>
                  <td className="num">{inv.due}</td>
                  <td className="num">{inv.hours.toFixed(1)}h</td>
                  <td className="num strong">{fmtMoney(inv.total)}</td>
                  <td><span className={`badge dot ${inv.status}`}>{inv.status}</span></td>
                  <td className="pad-r"><button className="icon-btn">⋯</button></td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </React.Fragment>
  );
}

// ─────── Invoice Detail ───────
function InvoiceDetail({ id, setRoute }) {
  const inv = INVOICES.find(i => i.id === id) || INVOICES[0];
  const cl = clientById(inv.client);

  // synthesize line items
  const lines = [
    { d: "Map mode: cluster rendering", h: 11.5, r: 145 },
    { d: "Telemetry side-panel",        h:  8.0, r: 145 },
    { d: "Operator role permissions",   h:  6.0, r: 145 },
    { d: "Code review queue",           h:  4.0, r: 145 },
  ];
  const subtotal = lines.reduce((a, l) => a + l.h * l.r, 0);
  const vat = subtotal * 0.19;
  const total = subtotal + vat;

  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">
            <span onClick={() => setRoute({ view: "invoices" })} style={{ cursor: "pointer" }}>~ / invoices</span>
            <span className="ascii-dot">/</span>
            <span>#{inv.id}</span>
          </div>
          <h1 className="page-title">
            Invoice #{inv.id}
            <span className="meta">{cl.name}<span className="ascii-dot">·</span><span className={`badge dot ${inv.status}`}>{inv.status}</span></span>
          </h1>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ghost">Duplicate</button>
          <button className="btn">Download PDF</button>
          <button className="btn primary">{inv.status === "draft" ? "Send" : "Mark paid"}</button>
        </div>
      </div>

      <div className="invoice-page">
        <div className="invoice-doc-wrap">
          <div className="invoice-doc">
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 36 }}>
              <div>
                <div style={{ fontSize: 11, letterSpacing: ".1em", textTransform: "uppercase", color: "var(--ink-3)" }}>Invoice</div>
                <h2 className="invoice-h" style={{ marginTop: 4 }}>#{inv.id}</h2>
              </div>
              <div style={{ textAlign: "right", display: "flex", alignItems: "center", gap: 10 }}>
                <span className="wordmark-mark"/>
                <div>
                  <div style={{ fontWeight: 700, letterSpacing: "-0.02em" }}>Julian Krause</div>
                  <div style={{ fontSize: 11, color: "var(--ink-3)" }}>Independent · Berlin, DE</div>
                </div>
              </div>
            </div>

            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 24, marginBottom: 32, fontSize: "var(--fs-sm)" }}>
              <div>
                <div style={{ fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)", marginBottom: 6 }}>Billed to</div>
                <div style={{ fontWeight: 600, color: "var(--ink)" }}>{cl.name}</div>
                <div style={{ color: "var(--ink-2)", marginTop: 2 }}>{cl.contact}</div>
                <div style={{ color: "var(--ink-3)" }}>Friedrichstraße 47</div>
                <div style={{ color: "var(--ink-3)" }}>10117 Berlin, DE</div>
              </div>
              <div>
                <div style={{ fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)", marginBottom: 6 }}>Issued</div>
                <div style={{ fontWeight: 600, color: "var(--ink)" }}>{inv.issued}, 2026</div>
                <div style={{ fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)", marginTop: 16, marginBottom: 6 }}>Due</div>
                <div style={{ fontWeight: 600, color: inv.status === "overdue" ? "var(--red)" : "var(--ink)" }}>{inv.due}, 2026</div>
              </div>
              <div>
                <div style={{ fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)", marginBottom: 6 }}>Project</div>
                <div style={{ fontWeight: 600, color: "var(--ink)" }}>Fleet Console v2</div>
                <div style={{ color: "var(--ink-3)" }}>ATLS-FLT</div>
                <div style={{ fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)", marginTop: 16, marginBottom: 6 }}>Period</div>
                <div style={{ color: "var(--ink-2)" }}>Apr 28 – May 18</div>
              </div>
            </div>

            <div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 80px 80px 100px", gap: 12, padding: "8px 0", borderBottom: "1px solid var(--ink)", fontSize: 10, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-3)" }}>
                <div>Description</div>
                <div style={{ textAlign: "right" }}>Hours</div>
                <div style={{ textAlign: "right" }}>Rate</div>
                <div style={{ textAlign: "right" }}>Amount</div>
              </div>
              {lines.map((l, i) => (
                <div key={i} className="invoice-line">
                  <div style={{ color: "var(--ink)" }}>{l.d}</div>
                  <div className="num">{l.h.toFixed(1)}</div>
                  <div className="num">€{l.r}</div>
                  <div className="num strong" style={{ color: "var(--ink)" }}>{fmtMoney(l.h * l.r)}</div>
                </div>
              ))}
            </div>

            <div className="invoice-totals">
              <div className="label">Subtotal</div><div className="v">{fmtMoney(subtotal)}</div>
              <div className="label">VAT 19%</div><div className="v">{fmtMoney(vat)}</div>
              <div className="grand-l">Total due</div><div className="v grand">{fmtMoney(total)}</div>
            </div>

            <div style={{ marginTop: 36, paddingTop: 18, borderTop: "1px solid var(--border)", fontSize: 11, color: "var(--ink-3)", display: "flex", justifyContent: "space-between" }}>
              <div>
                <div style={{ color: "var(--ink-2)", marginBottom: 2 }}>Payment</div>
                <div>SEPA · DE89 3704 0044 0532 0130 00</div>
                <div>COBADEFFXXX · Commerzbank</div>
              </div>
              <div style={{ textAlign: "right" }}>
                <div>USt-IdNr. DE 287 415 922</div>
                <div>julian@krause.dev</div>
              </div>
            </div>
          </div>
        </div>

        <aside className="invoice-side">
          <h3 className="section-title">Activity</h3>
          <div style={{ display: "flex", flexDirection: "column", gap: 10, fontSize: "var(--fs-sm)" }}>
            <ActivityRow t="20 May, 09:14" who="you" what="Sent to marit@atlas.dev" tone="ink"/>
            <ActivityRow t="20 May, 09:13" who="you" what="Marked as Sent" tone="muted"/>
            <ActivityRow t="20 May, 09:11" who="you" what="Generated PDF" tone="muted"/>
            <ActivityRow t="20 May, 09:02" who="you" what="Created from 4 entries" tone="muted"/>
          </div>

          <h3 className="section-title" style={{ marginTop: 24 }}>Reminders</h3>
          <div style={{ fontSize: "var(--fs-sm)", color: "var(--ink-2)", lineHeight: 1.6 }}>
            <div>Reminder scheduled for <span style={{ color: "var(--ink)" }}>Jun 04</span> if unpaid.</div>
            <button className="btn ghost" style={{ marginTop: 8, padding: "4px 0" }}>edit schedule →</button>
          </div>

          <h3 className="section-title" style={{ marginTop: 24 }}>Linked entries</h3>
          <div style={{ fontSize: "var(--fs-sm)", color: "var(--ink-2)" }}>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "4px 0", borderBottom: "1px solid var(--border)" }}>
              <span>29.5h</span><span className="muted">29 entries</span>
            </div>
            <button className="btn ghost" style={{ marginTop: 8, padding: "4px 0" }}>view entries →</button>
          </div>
        </aside>
      </div>
    </React.Fragment>
  );
}

function ActivityRow({ t, who, what, tone }) {
  return (
    <div style={{ display: "flex", gap: 10, alignItems: "baseline" }}>
      <span style={{ color: "var(--ink-4)", fontSize: 10, minWidth: 90, fontVariantNumeric: "tabular-nums" }}>{t}</span>
      <span style={{ color: tone === "ink" ? "var(--ink)" : "var(--ink-2)" }}>{what}</span>
    </div>
  );
}

// ─────── Reports ───────
function ReportsView() {
  return (
    <React.Fragment>
      <div className="page-head">
        <div>
          <div className="crumb">~ / reports</div>
          <h1 className="page-title">
            Reports
            <span className="meta">Last 90 days</span>
          </h1>
        </div>
      </div>
      <div style={{ padding: 28, color: "var(--ink-3)" }}>
        <div style={{ border: "1px dashed var(--border-strong)", padding: 48, textAlign: "center" }}>
          Reports view — utilization, revenue, project profitability.
        </div>
      </div>
    </React.Fragment>
  );
}

Object.assign(window, {
  Dashboard, ProjectDetail, TimerView, ClientsView, InvoicesView, InvoiceDetail, ReportsView,
});
