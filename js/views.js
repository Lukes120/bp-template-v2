// Renderer delle schermate. Tutte le funzioni restituiscono HTML stringa.
// Stato globale (currentUser, utenti, offerte, form, screen, editId, filterUser) è in app.js.

function badgeRuolo(ruolo){
  if (ruolo === "admin")       return "badge-admin";
  if (ruolo === "supervisore") return "badge-supervisore";
  if (ruolo === "viewer")      return "badge-viewer";
  return "badge-user";
}

function inicial(n){ return (n || "A").charAt(0).toUpperCase(); }

/* ============== TOPNAV (app bar Odoo) ============== */

function renderTopnav(activeMenu){
  if (!currentUser) return '';
  const isAdmin = currentUser.ruolo === "admin";
  const isSupervisore = currentUser.ruolo === "supervisore";
  // Solo admin/supervisore approvano sconti/PI (viewer e' read-only su tutto).
  const canApprove = isAdmin || isSupervisore;
  const nInAttesa = canApprove ? offerte.filter(o => (o.scontoStato || "") === "inattesa").length : 0;

  const link = (key, label, icon, onclick) =>
    `<button class="topnav-link${activeMenu === key ? ' active' : ''}" onclick="${onclick}"><i class="fas fa-${icon}"></i>${label}</button>`;

  return '<nav class="topnav" aria-label="Navigazione principale">' +
    '<button class="topnav-logo" onclick="screen=\'list\';render()" title="Torna alla home" aria-label="Torna alla home">' +
      '<img src="logo.png" class="topnav-logo-img" alt="Ecotel Italia">' +
      '<span class="topnav-logo-text">BP Template</span>' +
    '</button>' +
    '<button class="topnav-burger" type="button" data-action="topnav-burger" aria-label="Apri menu navigazione" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>' +
    '<div class="topnav-links">' +
      link('list', 'Offerte', 'list-ul', "screen='list';render()") +
      (canApprove ? `<button class="topnav-link${activeMenu === 'approvazioni' ? ' active' : ''}" onclick="screen='approvazioni';render()"><i class="fas fa-check-circle"></i>Da approvare${nInAttesa > 0 ? `<span class="topnav-link-badge">${nInAttesa}</span>` : ''}</button>` : '') +
      (isAdmin ? link('adminUsers', 'Utenti', 'users', 'apriUtenti()') : '') +
      (isAdmin ? link('audit', 'Audit', 'history', 'apriAudit()') : '') +
      link('profilo', 'Profilo', 'user-circle', "screen='profilo';render()") +
    '</div>' +
    '<div class="topnav-right">' +
      '<button class="topnav-btn btn-tnav-help" onclick="screen=\'guida\';render()" title="Guida utente" aria-label="Guida utente"><i class="fas fa-question-circle" aria-hidden="true"></i><span class="btn-tnav-help-label">Guida</span></button>' +
      '<div class="topnav-user" title="' + esc(currentUser.username) + '">' +
        '<div class="topnav-avatar">' + esc(inicial(currentUser.nome)) + '</div>' +
        '<span class="topnav-username">' + esc(currentUser.nome) + '</span>' +
        '<span class="badge-tnav-admin">' + currentUser.ruolo + '</span>' +
      '</div>' +
      '<button class="topnav-btn btn-tnav-logout" onclick="doLogout()" title="Esci" aria-label="Esci"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></button>' +
    '</div></nav>';
}

/* ============== CONTROL PANEL (breadcrumb + actions + statusbar) ============== */

function renderControlPanel(opts){
  const crumbs = (opts.breadcrumb || []).map((c, i, arr) => {
    const isLast = i === arr.length - 1;
    if (isLast) return '<span class="o-bc-current">' + c.label + '</span>';
    return '<a onclick="' + (c.onclick || '') + '">' + c.label + '</a><span class="o-bc-sep">›</span>';
  }).join('');
  return '<div class="o-control-panel">' +
    '<div class="o-cp-breadcrumb">' + crumbs + '</div>' +
    (opts.actions || '') +
    (opts.right || '') +
    '</div>';
}

/* ============== SMART BUTTONS (riutilizzabile fra form e riepilogo) ============== */

function renderSmartButtons(c, opts = {}){
  const interactive = opts.interactive !== false;
  const onClick = interactive ? "syncAndGo('riepilogo')" : null;
  const btn = (icon, iconColor, value, valueClass, label) =>
    '<button class="o-smart-btn"' + (onClick ? ' onclick="' + onClick + '"' : ' disabled') + '>' +
      '<div class="o-smart-btn-icon"><i class="fas fa-' + icon + '"' + (iconColor ? ' style="color:' + iconColor + '"' : '') + '></i></div>' +
      '<div class="o-smart-btn-content">' +
        '<span class="o-smart-btn-value' + (valueClass ? ' ' + valueClass : '') + '">' + value + '</span>' +
        '<span class="o-smart-btn-label">' + label + '</span>' +
      '</div>' +
    '</button>';

  let html = '<div class="o-smart-buttons">' +
    btn('euro-sign', null,        fmt(c.tFSconto),         '',          'Prezzo cliente') +
    btn('coins',     '#a8a8a8',   fmt(c.tC),               '',          'Costi') +
    btn('chart-line','#28a745',   fmtPct(c.mP) + '%',      mc(c.mP),    'Margine');

  if (form.allegataOdoo) {
    html += '<button class="o-smart-btn" disabled title="Allegata il ' + fmtDataTimeIT(form.allegataOdoo) + '">' +
      '<div class="o-smart-btn-icon"><i class="fas fa-link" style="color:#28a745"></i></div>' +
      '<div class="o-smart-btn-content"><span class="o-smart-btn-value">Sì</span><span class="o-smart-btn-label">Allegata Odoo</span></div>' +
    '</button>';
  }
  return html + '</div>';
}

/* ============== STATUS BAR (per form/riepilogo) ============== */

function renderStatusBar(o, opts = {}){
  const stato = o.scontoStato || '';
  const allegata = !!o.allegataOdoo;
  const stages = [];

  if (!stato) {
    stages.push({ label: 'Bozza', active: !allegata, done: allegata });
    stages.push({ label: 'Pronta', active: allegata, done: false });
  } else if (stato === 'inattesa') {
    stages.push({ label: 'Bozza', done: true });
    stages.push({ label: 'In approvazione', active: true });
    stages.push({ label: 'Pronta', active: false });
  } else if (stato === 'approvato') {
    stages.push({ label: 'Bozza', done: true });
    stages.push({ label: 'Sconto approvato', done: allegata, active: !allegata });
    stages.push({ label: 'Pronta', active: allegata });
  } else if (stato === 'rifiutato') {
    stages.push({ label: 'Bozza', done: true });
    stages.push({ label: 'Sconto rifiutato', active: true });
  }

  // Stile mini (default, in alto a destra del control panel) o "full" pillole grandi
  if (opts.full) {
    return '<div class="o-statusbar">' +
      stages.map(s => '<span class="o-status-pill' + (s.active ? ' active' : (s.done ? ' done' : '')) + '">' + s.label + '</span>').join('') +
      (allegata ? '<span style="margin-left:auto;font-size:.75rem;color:var(--o-text-muted)"><i class="fas fa-link" style="color:#28a745"></i> Allegata a Odoo · ' + fmtDataTimeIT(o.allegataOdoo) + '</span>' : '') +
      '</div>';
  }
  return '<div class="o-statusbar-mini">' +
    stages.map(s => '<span class="o-status-pill' + (s.active ? ' active' : (s.done ? ' done' : '')) + '">' + s.label + '</span>').join('') +
    '</div>';
}

/* ============== LOGIN ============== */

function renderLogin(){
  return '<div class="login-wrap"><div class="login-card">' +
    '<img src="logo.png" style="max-width:160px;margin-bottom:14px" alt="Ecotel Italia">' +
    '<h1>BP Template</h1><p>Valutazione Commesse — Ecotel Italia</p>' +
    '<div id="login-err" class="login-err"></div>' +
    '<form id="login-form" method="post" action="api/login.php" autocomplete="on" onsubmit="doLogin(event)">' +
    '<input id="l-user" name="username" placeholder="Email Odoo" autocomplete="username" required value="' + (localStorage.getItem('bp_last_user') || '') + '">' +
    '<input id="l-pass" name="password" type="password" placeholder="Password Odoo" autocomplete="current-password" required>' +
    '<div id="l-totp-wrap" style="display:none;margin-top:4px">' +
      '<input id="l-totp" name="totp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="Codice 2FA (6 cifre)" style="text-align:center;letter-spacing:4px;font-size:1.1rem">' +
      '<div style="font-size:.75rem;color:#6c757d;margin-top:6px"><i class="fas fa-shield-alt"></i> Inserisci il codice del tuo Authenticator (lo stesso che usi su Odoo).</div>' +
    '</div>' +
    '<button id="l-submit" type="submit" class="btn btn-purple btn-full" style="padding:10px;font-size:.9rem;font-weight:500;margin-top:6px"><i class="fas fa-sign-in-alt"></i> Accedi</button>' +
    '</form>' +
    '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e9ecef;font-size:.78rem;color:#6c757d;line-height:1.5">' +
      '<i class="fas fa-info-circle" style="color:var(--o-primary)"></i> ' +
      'Usa le stesse credenziali con cui accedi a <b>Odoo</b>.' +
    '</div>' +
    '</div></div>';
}

/* ============== LIST (DASHBOARD) ============== */

function renderList(){
  const isAdmin = currentUser.ruolo === "admin";
  const isSupervisore = currentUser.ruolo === "supervisore";
  const isViewer = currentUser.ruolo === "viewer";
  const canSeeAll = isAdmin || isSupervisore || isViewer;
  let lista = [...offerte].reverse();
  if (!canSeeAll) lista = lista.filter(o => o.userId === currentUser.id);
  else if (filterUser !== "all") lista = lista.filter(o => o.userId === filterUser);
  if (filterText && filterText.trim() !== "") {
    const q = filterText.toLowerCase();
    lista = lista.filter(o =>
      (o.nome || "").toLowerCase().includes(q) ||
      (o.cliente || "").toLowerCase().includes(q) ||
      (o.nOrdineOdoo || "").toLowerCase().includes(q)
    );
  }

  // Pre-calcola tutte le offerte una sola volta (ricavi/costi/margine + render rows)
  const calcs = lista.map(o => calcAll(o));
  let totRicavi = 0, totCosti = 0;
  for (const c of calcs) { totRicavi += c.tFSconto; totCosti += c.tC; }
  const totMargineE = totRicavi - totCosti;
  const totMargineP = totRicavi > 0 ? (totMargineE / totRicavi) * 100 : 0;
  const mpClass = p => p >= MARGINE_THRESHOLDS.green ? "dash-mp-green" : p >= MARGINE_THRESHOLDS.yellow ? "dash-mp-yellow" : "dash-mp-red";

  const rows = lista.map((o, i) => {
    const c = calcs[i]; const ut = utenti.find(u => u.id === o.userId);
    return '<tr>' +
      '<td data-label="Commessa" style="font-weight:600;color:#1e293b;white-space:nowrap" title="' + esc(o.nome) + '">' + esc(o.nome) + '</td>' +
      '<td data-label="Cliente / Data" style="max-width:180px" title="' + esc(o.cliente || "") + '"><div style="color:#374151;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px">' + esc(o.cliente || "") + '</div><div style="color:#94a3b8;font-size:.72rem">' + fmtDataIT(o.data || "") + '</div></td>' +
      '<td data-label="N. Odoo">' + (o.nOrdineOdoo ? '<span class="dash-odoo-chip">' + esc(o.nOrdineOdoo) + '</span>' : "") + (o.allegataOdoo ? ' <i class="fas fa-link" style="color:#28a745;font-size:.78rem;margin-left:4px" title="Allegata a Odoo il ' + fmtDataTimeIT(o.allegataOdoo) + '"></i>' : '') + '</td>' +
      (canSeeAll ? '<td data-label="Utente" style="font-size:.8rem;color:#64748b;white-space:nowrap">' + esc(ut?.nome || "") + '</td>' : "") +
      '<td data-label="Ricavi €" style="text-align:right;font-variant-numeric:tabular-nums;font-size:.82rem;font-weight:500;color:#1e293b;white-space:nowrap">' + fmt(c.tFSconto) + '</td>' +
      '<td data-label="Costi €" style="text-align:right;font-variant-numeric:tabular-nums;font-size:.82rem;color:#94a3b8;white-space:nowrap">' + fmt(c.tC) + '</td>' +
      '<td data-label="Margine €" style="text-align:right;font-variant-numeric:tabular-nums;font-size:.82rem;font-weight:600;color:#059669;white-space:nowrap">' + fmt(c.mE) + '</td>' +
      '<td data-label="Margine %" style="text-align:right;white-space:nowrap"><span class="' + mpClass(c.mP) + '">' + fmtPct(c.mP) + '%</span></td>' +
      '<td data-label="Azioni" style="text-align:right;white-space:nowrap">' +
        ((isAdmin || isSupervisore || o.userId === currentUser.id) ? '<button class="btn-dash-mod" onclick="apriModifica(\'' + o.id + '\')">Modifica</button>' : "") +
        '<button class="btn-dash-rie" onclick="apriRiepilogo(\'' + o.id + '\')">Riepilogo</button>' +
        (isAdmin ? '<button class="btn-dash-dup" onclick="duplica(\'' + o.id + '\')">Duplica</button>' : '') +
        ((isAdmin || o.userId === currentUser.id) ? '<button class="btn-dash-del" onclick="elimina(\'' + o.id + '\')">Elimina</button>' : "") +
      '</td></tr>';
  }).join("");

  const emptyRow = '<tr><td colspan="' + (canSeeAll ? 9 : 8) + '" style="text-align:center;padding:40px;color:#9ca3af">Nessuna offerta presente</td></tr>';
  const mpTotClass = totMargineP >= MARGINE_THRESHOLDS.green ? "dash-mp-green" : totMargineP >= MARGINE_THRESHOLDS.yellow ? "dash-mp-yellow" : "dash-mp-red";
  const totRow = '<tr class="dash-tot-row">' +
    '<td colspan="' + (canSeeAll ? 4 : 3) + '" class="tot-cell-label">TOTALI (' + lista.length + ' offerte)</td>' +
    '<td class="tot-cell tot-cell-ricavi">' + fmt(totRicavi) + '</td>' +
    '<td class="tot-cell tot-cell-costi">' + fmt(totCosti) + '</td>' +
    '<td class="tot-cell tot-cell-margine">' + fmt(totMargineE) + '</td>' +
    '<td class="tot-cell tot-cell-mp"><span class="' + mpTotClass + '">' + fmtPct(totMargineP) + '%</span></td>' +
    '<td></td></tr>';

  const filterBar = canSeeAll ?
    '<select class="dash-filter-select" onchange="filterUser=this.value;render()"><option value="all"' + (filterUser === "all" ? " selected" : "") + '>Tutti gli utenti</option>' +
    utenti.map(u => '<option value="' + esc(u.id) + '"' + (filterUser === u.id ? " selected" : "") + '>' + esc(u.nome) + '</option>').join("") + '</select>' : '';

  const kpiBar = '<div class="kpi-bar">' +
    '<div class="kpi-item"><div class="kpi-label">Totale Offerte</div><div class="kpi-value kpi-value-blue">' + lista.length + '</div><div class="kpi-sub">offerte in archivio</div></div>' +
    '<div class="kpi-item"><div class="kpi-label">Ricavi Totali</div><div class="kpi-value">' + fmt(totRicavi) + '</div><div class="kpi-sub">EUR</div></div>' +
    '<div class="kpi-item"><div class="kpi-label">Margine Totale</div><div class="kpi-value kpi-value-green">' + fmt(totMargineE) + '</div><div class="kpi-sub">EUR</div></div>' +
    '<div class="kpi-item"><div class="kpi-label">Margine Medio %</div><div class="kpi-value kpi-value-yellow">' + fmtPct(totMargineP) + '%</div><div class="kpi-sub">media ponderata</div></div>' +
    '</div>';

  const controlPanel =
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb"><span class="o-bc-current">Offerte</span></div>' +
      '<div class="o-cp-actions">' +
        '<button class="btn btn-purple" onclick="nuovaOfferta()"><i class="fas fa-plus"></i>Nuovo</button>' +
        '<button class="btn btn-excel" onclick="esportaArchivioExcel()"><i class="fas fa-file-excel"></i>Excel</button>' +
        '<button class="btn btn-pdf" onclick="esportaArchivioPDF()"><i class="fas fa-file-pdf"></i>PDF</button>' +
      '</div>' +
      '<div class="o-cp-spacer"></div>' +
      '<div class="o-cp-search">' +
        '<i class="fas fa-search" style="color:var(--o-text-muted)"></i>' +
        '<input id="dash-search" type="search" placeholder="Cerca commessa / cliente / N. Odoo" value="' + (filterText || "") + '" oninput="onSearchInput(this.value)">' +
      '</div>' +
    '</div>';

  return renderTopnav('list') + controlPanel + kpiBar +
    '<div class="dash-main">' +
    '<div class="dash-toolbar">' +
    '<div class="dash-toolbar-left">' +
    '<span class="dash-toolbar-title"><i class="fas fa-list-ul" style="color:var(--o-primary);margin-right:6px"></i>Elenco Offerte</span>' +
    filterBar +
    '<span class="dash-count-pill">' + lista.length + ' offerte</span>' +
    '</div>' +
    '<div class="dash-toolbar-right"></div></div>' +
    '<div class="dash-table-card"><table class="dash-table">' +
    '<colgroup>' + (canSeeAll ?
      '<col style="width:16%"><col style="width:13%"><col style="width:6%"><col style="width:7%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:6%"><col style="width:25%">' :
      '<col style="width:18%"><col style="width:15%"><col style="width:7%"><col style="width:10%"><col style="width:10%"><col style="width:10%"><col style="width:7%"><col style="width:23%">'
    ) + '</colgroup>' +
    '<thead><tr>' +
    '<th>Commessa</th><th>Cliente / Data</th><th>N. Odoo</th>' +
    (canSeeAll ? '<th>Utente</th>' : "") +
    '<th class="r">Ricavi EUR</th><th class="r">Costi EUR</th><th class="r">Margine EUR</th><th class="r">Margine %</th><th></th>' +
    '</tr></thead>' +
    '<tbody>' + (lista.length === 0 ? emptyRow : rows) + '</tbody>' +
    '<tfoot>' + totRow + '</tfoot>' +
    '</table></div></div>';
}

/* ============== ADMIN UTENTI ============== */

function renderAdminUsers(){
  const rows = utenti.map(u => {
    const n = offerte.filter(o => o.userId === u.id).length;
    return '<div class="user-row"><div style="flex:1"><span style="font-weight:bold">' + esc(u.nome) + '</span><span class="badge ' + badgeRuolo(u.ruolo) + '">' + esc(u.ruolo) + '</span><span style="font-size:.78rem;color:#9ca3af;margin-left:8px">@' + esc(u.username) + ' - ' + n + ' offerte</span>' + (u.email ? '<span style="font-size:.78rem;color:#6b7280;margin-left:8px">' + esc(u.email) + '</span>' : '') + '</div>' +
      '<div style="display:flex;gap:6px">' +
      (u.id !== "u0" ? '<button class="btn btn-blue btn-sm" onclick="modificaUtente(\'' + u.id + '\')">Modifica</button>' : '') +
      (u.email && u.id !== "u0" ? '<button class="btn btn-gray btn-sm" onclick="reinviaMailUtente(\'' + u.id + '\')">Reinvia Mail</button>' : '') +
      (u.id !== "u0" ? '<button class="btn btn-red btn-sm" onclick="eliminaUtente(\'' + u.id + '\')">Elimina</button>' : '<span style="font-size:.75rem;color:#d1d5db">account principale</span>') +
      '</div></div>';
  }).join("");

  return renderTopnav('adminUsers') +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb"><a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span><span class="o-bc-current">Gestione Utenti</span></div>' +
    '</div>' +
    '<div class="dash-main">' +
    '<div class="card"><div class="sec-title admin"><i class="fas fa-user-plus"></i>Crea Utente</div><div class="card-body"><div class="grid-form">' +
    '<div><label>Nome</label><input id="nu-nome"></div><div><label>Username</label><input id="nu-user"></div>' +
    '<div><label>Email</label><input id="nu-email" type="email" placeholder="email@ecotelitalia.it"></div>' +
    '<div><label>Password</label><input id="nu-pass" type="password"></div>' +
    '<div><label>Ruolo</label><select id="nu-ruolo"><option value="user">Utente</option><option value="viewer">Viewer (vede tutto, no autorizzazioni)</option><option value="supervisore">Supervisore</option><option value="admin">Amministratore</option></select></div>' +
    '</div><div id="nu-err" style="color:var(--o-danger);font-size:.78rem;margin-top:8px"></div>' +
    '<button class="btn btn-purple" style="margin-top:12px" onclick="creaUtente()"><i class="fas fa-plus"></i>Crea Utente</button></div></div>' +
    '<div class="card"><div class="sec-title admin"><i class="fas fa-users"></i>Utenti Esistenti</div><div class="card-body" style="padding:0">' + rows + '</div></div></div>';
}

/* ============== APPROVAZIONI (DA APPROVARE) ============== */

function renderApprovazioni(){
  const inAttesa = offerte.filter(o => (o.scontoStato || "") === "inattesa");
  const rows = inAttesa.map(o => {
    const c = calcAll(o); const ut = utenti.find(u => u.id === o.userId);
    const isPI = !!o.prezzoImpostoAttivo;
    let richiesta;
    if (isPI) {
      const piVal = parseFloat(o.prezzoImpostoValore) || 0;
      const mePI = piVal - c.tC;
      const mPPI = piVal > 0 ? (mePI / piVal) * 100 : 0;
      richiesta =
        '<div style="font-weight:bold;color:#7c3aed">Prezzo imposto: EUR ' + fmt(piVal) + '</div>' +
        '<div style="font-size:.78rem;color:#6b7280">margine risultante <span class="' + mc(mPPI) + '" style="font-weight:700">' + fmtPct(mPPI) + '%</span></div>';
    } else {
      const valore = (o.scontoTipo || "pct") === "pct" ? fmt(o.scontoValore || 0) + "%" : "EUR " + fmt(o.scontoValore || 0);
      richiesta = '<div style="font-weight:bold;color:#ea580c">Sconto: ' + valore + '</div>';
    }
    return '<tr>' +
      '<td data-label="Commessa" style="font-weight:600;color:#1e293b" title="' + esc(o.nome) + '">' + esc(o.nome) + '</td>' +
      '<td data-label="Cliente" style="font-size:.82rem;color:#374151">' + esc(o.cliente || "") + '</td>' +
      '<td data-label="Utente" style="font-size:.8rem;color:#64748b">' + esc(ut?.nome || "") + '</td>' +
      '<td data-label="N. Odoo" style="font-size:.82rem;color:#7c3aed;font-weight:bold">' + esc(o.nOrdineOdoo || "") + '</td>' +
      '<td data-label="Totale offerta" style="text-align:right;font-family:\'Roboto Mono\',monospace">' + fmt(c.tF) + '</td>' +
      '<td data-label="Richiesta" style="text-align:right">' + richiesta + '</td>' +
      '<td data-label="Note" style="font-size:.82rem;color:#475569">' + (o.scontoNota || "") + '</td>' +
      '<td data-label="Azioni" style="text-align:right;white-space:nowrap">' +
        '<button class="btn-dash-rie" onclick="apriRiepilogo(\'' + o.id + '\')">Vedi</button>' +
        '<button style="background:#059669;color:white;border:none;padding:4px 12px;border-radius:5px;font-size:.72rem;font-weight:600;margin-left:4px;cursor:pointer" onclick="approvaSconto(\'' + o.id + '\')">Approva</button>' +
        '<button style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;padding:4px 12px;border-radius:5px;font-size:.72rem;font-weight:600;margin-left:4px;cursor:pointer" onclick="rifiutaSconto(\'' + o.id + '\')">Rifiuta</button>' +
      '</td>' +
    '</tr>';
  }).join("");
  return renderTopnav('approvazioni') +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb"><a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span><span class="o-bc-current">Richieste da approvare</span></div>' +
      '<span class="dash-count-pill" style="margin-left:8px">' + inAttesa.length + ' richieste</span>' +
    '</div>' +
    '<div class="dash-main">' +
    '<div class="dash-table-card"><table class="dash-table">' +
    '<thead><tr><th>Commessa</th><th>Cliente</th><th>Utente</th><th>N. Odoo</th><th class="r">Totale offerta</th><th class="r">Richiesta</th><th>Note</th><th></th></tr></thead>' +
    '<tbody>' + (rows || '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--o-text-muted)"><i class="fas fa-check-circle" style="font-size:2rem;color:#a5d8b1;display:block;margin-bottom:8px"></i>Nessuna richiesta in attesa</td></tr>') + '</tbody>' +
    '</table></div></div>';
}

/* ============== AUDIT LOG ============== */

function renderAudit(){
  const rows = (auditEntries || []).map(e => {
    const badgeColors = {
      create:'#dcfce7;color:#15803d', update:'#dbeafe;color:#1d4ed8',
      delete:'#fee2e2;color:#991b1b', login:'#e0f2fe;color:#075985',
      login_fail:'#fef3c7;color:#92400e', attach_odoo:'#ede9fe;color:#6d28d9',
      mail_sconto:'#ffe4e6;color:#9f1239', mail_credenziali:'#f0fdfa;color:#0d9488',
      reset:'#fee2e2;color:#991b1b'
    };
    const c = badgeColors[e.action] || '#e2e8f0;color:#475569';
    return '<tr>' +
      '<td style="font-variant-numeric:tabular-nums;font-size:.78rem;color:#64748b;white-space:nowrap">' + fmtDataTimeIT(e.ts) + '</td>' +
      '<td style="font-size:.82rem">' + (e.user_name ? esc(e.user_name) : '<i style="color:#94a3b8">anonimo</i>') + '</td>' +
      '<td><span style="background:' + c + ';font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase">' + esc(e.action) + '</span></td>' +
      '<td style="font-size:.82rem">' + esc(e.entity) + (e.entity_id ? ' <span style="color:#94a3b8;font-variant-numeric:tabular-nums;font-size:.72rem">#' + esc(e.entity_id) + '</span>' : '') + '</td>' +
      '<td style="font-size:.82rem;color:#374151">' + esc(e.detail || '') + '</td>' +
    '</tr>';
  }).join("");
  return renderTopnav('audit') +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb"><a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span><span class="o-bc-current">Audit Log</span></div>' +
    '</div>' +
    '<div class="dash-main">' +
    '<div class="dash-table-card"><table class="dash-table">' +
    '<thead><tr><th style="width:160px">Quando</th><th style="width:140px">Chi</th><th style="width:130px">Azione</th><th style="width:140px">Entità</th><th>Dettaglio</th></tr></thead>' +
    '<tbody>' + (rows || '<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--o-text-muted)">Nessun evento</td></tr>') + '</tbody>' +
    '</table></div></div>';
}

/* ============== PROFILO ============== */

function renderProfilo(){
  return renderTopnav('profilo') +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb"><a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span><span class="o-bc-current">Profilo</span></div>' +
    '</div>' +
    '<div class="o-form-view"><div class="o-sheet">' +
    '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid var(--o-border-light)">' +
      '<div class="topnav-avatar" style="width:48px;height:48px;font-size:1.1rem;background:var(--o-primary)">' + esc(inicial(currentUser.nome)) + '</div>' +
      '<div><div style="font-size:1.05rem;font-weight:500">' + esc(currentUser.nome) + '</div><div style="font-size:.78rem;color:var(--o-text-muted)">@' + esc(currentUser.username) + ' · <span class="badge ' + badgeRuolo(currentUser.ruolo) + '">' + esc(currentUser.ruolo) + '</span></div></div>' +
    '</div>' +

    '<div style="font-size:.78rem;font-weight:500;color:var(--o-primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px"><i class="fas fa-id-card"></i> Modifica dati</div>' +
    '<div class="grid-form">' +
    '<div><label>Nome</label><input id="p-nome" value="' + esc(currentUser.nome) + '"></div>' +
    '<div><label>Username</label><input id="p-user" value="' + esc(currentUser.username) + '"></div>' +
    '</div><div id="p-info-err" style="font-size:.8rem;margin-top:8px"></div>' +
    '<button class="btn btn-purple" style="margin-top:14px" onclick="salvaDatiProfilo()"><i class="fas fa-save"></i>Salva</button>' +

    '<div style="font-size:.78rem;font-weight:500;color:var(--o-primary);text-transform:uppercase;letter-spacing:.04em;margin:24px 0 10px"><i class="fas fa-key"></i> Cambia password</div>' +
    '<div class="grid-form" style="grid-template-columns:repeat(3,1fr)">' +
    '<div><label>Attuale</label><input id="p-old" type="password"></div>' +
    '<div><label>Nuova</label><input id="p-new" type="password"></div>' +
    '<div><label>Conferma</label><input id="p-new2" type="password"></div>' +
    '</div><div id="p-pass-err" style="font-size:.8rem;margin-top:8px"></div>' +
    '<button class="btn btn-purple" style="margin-top:14px" onclick="salvaPassword()"><i class="fas fa-key"></i>Cambia password</button>' +
    '</div></div>';
}

/* ============== FORM (NUOVA/MODIFICA) ============== */

// Banner warning "margine sotto soglia ruolo": HTML stringa (vuota se ok).
// Estratto da renderForm cosi' che bindEvents in app.js possa rigenerarlo live
// quando l'utente modifica una riga di costo e il margine cambia.
function bbWarnMargine(c){
  if (!currentUser) return '';
  const minM = MARGINE_MIN_RUOLO[currentUser.ruolo] ?? 0;
  const scontoOk = form.scontoStato === "approvato";
  if (c.mP < minM && !scontoOk) {
    return '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;margin-bottom:10px;color:#991b1b;font-size:.85rem"><b>⚠ Margine ' + fmtPct(c.mP) + '% sotto la soglia minima del ' + minM + '% per il tuo ruolo.</b> Per salvare devi richiedere uno sconto direzione (da approvare).</div>';
  }
  return '';
}

function renderForm(){
  const c = calcAll(form, true);
  const inp = (key, id, field, val, type, mw) => '<input type="' + (type || "text") + '" data-key="' + key + '" data-id="' + id + '" data-field="' + field + '" value="' + esc(val || "") + '" style="min-width:' + (mw || 60) + 'px">';
  const sel = (key, id, val) => '<select data-key="' + key + '" data-id="' + id + '" data-field="categoria" onchange="aggiornaCostoH(this)">' + Object.keys(CATEGORIE).map(cat => '<option' + (cat === val ? " selected" : "") + '>' + esc(cat) + '</option>').join("") + '</select>';
  // Markup readonly per ruolo "user": solo admin/supervisore possono modificarlo.
  const isUserRole = currentUser && currentUser.ruolo === "user";
  const inpMarkup = (key, id, val) => {
    const ro = isUserRole ? ' readonly title="Solo supervisore o admin possono modificare il markup"' : '';
    const cls = isUserRole ? ' class="markup-locked"' : '';
    return '<input type="number"' + cls + ' data-key="' + key + '" data-id="' + id + '" data-field="markup" value="' + esc(val || "") + '"' + ro + ' style="min-width:45px">';
  };

  const manRows = c.cp.map(r => '<tr><td data-label="Categoria">' + sel("personale", r.id, r.categoria) + '</td><td data-label="h/uomo">' + inp("personale", r.id, "oreG", r.oreG, "number", 50) + '</td><td data-label="Costo/h"><input type="number" data-key="personale" data-id="' + r.id + '" data-field="costoH" value="' + (r.costoH || CATEGORIE[r.categoria] || 0) + '" readonly style="min-width:65px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;font-size:.8rem;width:100%"></td><td data-label="Costo Tot." class="td-r">' + fmt(r.b) + '</td><td data-label="Markup %">' + inpMarkup("personale", r.id, r.markup) + '</td><td data-label="Prezzo Vendita" class="td-pv">' + fmt(r.pv) + '</td><td data-label="Margine" class="td-mg">' + fmt(r.pv - r.b) + '</td><td data-label=""><button class="del-btn" aria-label="Elimina riga" onclick="delRow(\'personale\',\'' + r.id + '\')">x</button></td></tr>').join("");
  const matRows = c.cm.map(r => '<tr><td data-label="Descrizione">' + inp("materiali", r.id, "desc", r.desc, "text", 140) + '</td><td data-label="Qta">' + inp("materiali", r.id, "qta", r.qta, "number", 50) + '</td><td data-label="Costo Unit.">' + inp("materiali", r.id, "costoU", r.costoU, "number", 75) + '</td><td data-label="Costo Tot." class="td-r">' + fmt(r.b) + '</td><td data-label="Markup %">' + inpMarkup("materiali", r.id, r.markup) + '</td><td data-label="Prezzo Vendita" class="td-pv">' + fmt(r.pv) + '</td><td data-label="Margine" class="td-mg">' + fmt(r.pv - r.b) + '</td><td data-label=""><button class="del-btn" aria-label="Elimina riga" onclick="delRow(\'materiali\',\'' + r.id + '\')">x</button></td></tr>').join("");
  const serRows = c.cs.map(r => '<tr><td data-label="Descrizione">' + inp("servizi", r.id, "desc", r.desc, "text", 140) + '</td><td data-label="Qta">' + inp("servizi", r.id, "qta", r.qta, "number", 50) + '</td><td data-label="Costo Unit.">' + inp("servizi", r.id, "costoU", r.costoU, "number", 75) + '</td><td data-label="Costo Tot." class="td-r">' + fmt(r.b) + '</td><td data-label="Markup %">' + inpMarkup("servizi", r.id, r.markup) + '</td><td data-label="Prezzo Vendita" class="td-pv">' + fmt(r.pv) + '</td><td data-label="Margine" class="td-mg">' + fmt(r.pv - r.b) + '</td><td data-label=""><button class="del-btn" aria-label="Elimina riga" onclick="delRow(\'servizi\',\'' + r.id + '\')">x</button></td></tr>').join("");
  const manRows2 = c.cm2.map(r => '<tr><td data-label="Descrizione">' + inp("manutenzione", r.id, "desc", r.desc, "text", 140) + '</td><td data-label="Qta">' + inp("manutenzione", r.id, "qta", r.qta, "number", 50) + '</td><td data-label="Costo/Un">' + inp("manutenzione", r.id, "costoU", r.costoU, "number", 75) + '</td><td data-label="Costo Tot." class="td-r">' + fmt(r.b) + '</td><td data-label="Markup %">' + inpMarkup("manutenzione", r.id, r.markup) + '</td><td data-label="Prezzo Vendita" class="td-pv">' + fmt(r.pv) + '</td><td data-label="Margine" class="td-mg">' + fmt(r.pv - r.b) + '</td><td data-label=""><button class="del-btn" aria-label="Elimina riga" onclick="delRow(\'manutenzione\',\'' + r.id + '\')">x</button></td></tr>').join("");
  const traFields = [["desc","Descrizione"],["persone","Persone"],["giorni","Giorni"],["costoGiorno","Costo/g/pers"],["vitto","Vitto/p/g"],["alloggio","Alloggio/p/g"],["km","Km"],["costoKm","EUR/km"]];
  const traRows = c.ct.map(r => '<tr>' + traFields.map(([f,lbl]) => '<td data-label="' + lbl + '"><input type="' + (f === "desc" ? "text" : "number") + '" data-key="trasferte" data-id="' + r.id + '" data-field="' + f + '" value="' + (r[f] || "") + '" style="min-width:' + (f === "desc" ? 90 : 50) + 'px"></td>').join("") + '<td data-label="Costo Tot." class="td-r">' + fmt(r.b) + '</td><td data-label="Markup %">' + inpMarkup("trasferte", r.id, r.markup) + '</td><td data-label="Prezzo Vendita" class="td-pv">' + fmt(r.pv) + '</td><td data-label="Margine" class="td-mg">' + fmt(r.pv - r.b) + '</td><td data-label=""><button class="del-btn" aria-label="Elimina riga" onclick="delRow(\'trasferte\',\'' + r.id + '\')">x</button></td></tr>').join("");

  const smartBtns = renderSmartButtons(c, { interactive: true });

  return renderTopnav() +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb">' +
        '<a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span>' +
        '<span class="o-bc-current">' + (editId ? esc(form.nome || 'Modifica') : 'Nuova offerta') + '</span>' +
      '</div>' +
      '<div class="o-cp-actions">' +
        '<button class="btn btn-primary" onclick="salva()">SALVA</button>' +
        (editId ? '<button class="btn btn-secondary" onclick="annullaModifiche()">SCARTA</button>' : '<button class="btn btn-secondary" onclick="goList()">ANNULLA</button>') +
        '<button class="btn btn-secondary" onclick="syncAndGo(\'riepilogo\')">RIEPILOGO</button>' +
      '</div>' +
      renderStatusBar(form) +
    '</div>' +
    smartBtns +
    '<div class="o-form-view"><div class="o-sheet">' +
    '<h1 class="o-form-title">' + (editId ? esc(form.nome || 'Senza nome') : 'Nuova Offerta') + '</h1>' +
    (form.cliente ? '<div class="o-form-subtitle">' + esc(form.cliente) + (form.tipo ? ' · ' + esc(form.tipo) : '') + '</div>' : '') +
    '<div style="margin-top:24px"></div>' +
    /* ====== Barra Prezzo Imposto (sopra la testata) ====== */
    (function(){
      const piAttivo = !!form.prezzoImpostoAttivo;
      const stato = form.scontoStato || "";
      const piVal = parseFloat(form.prezzoImpostoValore) || 0;
      if (!piAttivo) {
        return '<div class="pi-bar"><button type="button" class="btn-pi-add" onclick="apriPrezzoImposto()"><i class="fas fa-bullseye"></i> Attiva Prezzo Imposto</button>' +
          '<span class="pi-bar-hint">Imposta un prezzo finale concordato in alternativa allo Sconto Direzione</span></div>';
      }
      // Badge "Da inviare" sostituito da "Margine OK" se user ha margine sopra soglia
      // (v=65: PI con margine sufficiente non richiede approvazione esplicita).
      const isUserBar = currentUser && currentUser.ruolo === "user";
      const mPPreviewBar = piVal > 0 ? ((piVal - c.tC) / piVal) * 100 : 0;
      const minMargineBar = MARGINE_MIN_RUOLO[currentUser?.ruolo] ?? 0;
      const margineOkBar = mPPreviewBar >= minMargineBar;
      let badge;
      if (stato === "approvato") badge = '<span class="pi-badge pi-ok"><i class="fas fa-check-circle"></i> Approvato</span>';
      else if (stato === "rifiutato") badge = '<span class="pi-badge pi-ko"><i class="fas fa-times-circle"></i> Rifiutato</span>';
      else if (stato === "inattesa") badge = '<span class="pi-badge pi-wait"><i class="fas fa-hourglass-half"></i> In attesa</span>';
      else if (isUserBar && margineOkBar) badge = '<span class="pi-badge pi-ok"><i class="fas fa-check-circle"></i> Margine OK</span>';
      else badge = '<span class="pi-badge pi-draft"><i class="fas fa-pencil-alt"></i> Da inviare</span>';
      return '<div class="pi-bar pi-bar-active">' +
        '<span class="pi-pill"><i class="fas fa-bullseye"></i> Prezzo Imposto: <b>' + fmt(piVal) + ' EUR</b></span>' +
        badge +
        '<button type="button" class="btn-pi-edit" onclick="document.getElementById(\'prezzo-imposto-valore\')?.focus()" title="Modifica valore"><i class="fas fa-pencil-alt"></i></button>' +
        '<button type="button" class="btn-pi-remove" onclick="togglePrezzoImposto(false)" title="Disattiva Prezzo Imposto"><i class="fas fa-times"></i></button>' +
      '</div>';
    })() +
    '<div class="card sec-card-dati"><div class="sec-title sec-dati"><i class="fas fa-info-circle"></i>Dati Commessa</div><div class="card-body"><div class="grid-form">' +
    '<div class="odoo-field-row"><label><i class="fas fa-link"></i>N. Prev/Ordine Odoo</label><input id="f-nOrdineOdoo" class="odoo-field" value="' + esc(form.nOrdineOdoo) + '" placeholder="Digita il codice — es. S03914" oninput="this.value=this.value.toUpperCase();aggiornaOdooField(this.value)" onblur="rimuoviDropdown();caricaDaOdoo(this.value)"></div>' +
    '<div><label>Nome Commessa</label><input id="f-nome" value="' + esc(form.nome) + '" readonly title="Auto da Odoo"></div>' +
    '<div><label>Cliente</label><input id="f-cliente" value="' + esc(form.cliente) + '" readonly title="Auto da Odoo"></div>' +
    '<div><label>Tipo</label><input id="f-tipo" value="' + esc(form.tipo) + '" readonly title="Auto da Odoo"></div>' +
    '<div><label>Data</label><input type="date" id="f-data" value="' + form.data + '" oninput="form.data=this.value"></div>' +
    '<div class="full"><label>Note</label><input id="f-note" value="' + esc(form.note) + '" oninput="form.note=this.value" placeholder="Note opzionali"></div>' +
    '</div></div></div>' +
    '<div class="card sec-card-mano"><div class="sec-title sec-mano"><i class="fas fa-hard-hat"></i>Manodopera</div><div class="card-body"><table><thead><tr><th>Categoria</th><th>h/uomo</th><th>Costo/h</th><th>Costo Tot.</th><th>Markup %</th><th>Prezzo Vendita</th><th>Margine</th><th></th></tr></thead><tbody>' + manRows + '</tbody><tfoot><tr><td colspan="3" style="text-align:left">TOTALE</td><td data-label="Costo Tot.">' + fmt(c.tCP) + '</td><td data-label=""></td><td data-label="Prezzo Vendita" style="color:var(--o-action)">' + fmt(c.tVP) + '</td><td data-label="Margine" class="td-mg">' + fmt(c.tVP - c.tCP) + '</td><td data-label=""></td></tr></tfoot></table><button class="add-row" onclick="addRow(\'personale\')"><i class="fas fa-plus-circle"></i>Aggiungi riga</button></div></div>' +
    '<div class="card sec-card-mat"><div class="sec-title sec-mat"><i class="fas fa-boxes"></i>Materiali</div><div class="card-body"><table><thead><tr><th>Descrizione</th><th>Qta</th><th>Costo Unit.</th><th>Costo Tot.</th><th>Markup %</th><th>Prezzo Vendita</th><th>Margine</th><th></th></tr></thead><tbody>' + matRows + '</tbody><tfoot><tr><td colspan="3" style="text-align:left">TOTALE</td><td data-label="Costo Tot.">' + fmt(c.tCM) + '</td><td data-label=""></td><td data-label="Prezzo Vendita" style="color:var(--o-action)">' + fmt(c.tVM) + '</td><td data-label="Margine" class="td-mg">' + fmt(c.tVM - c.tCM) + '</td><td data-label=""></td></tr></tfoot></table><button class="add-row" onclick="addRow(\'materiali\')"><i class="fas fa-plus-circle"></i>Aggiungi riga</button></div></div>' +
    '<div class="card sec-card-serv"><div class="sec-title sec-serv"><i class="fas fa-handshake"></i>Servizi e Subappalti</div><div class="card-body"><table><thead><tr><th>Descrizione</th><th>Qta</th><th>Costo Unit.</th><th>Costo Tot.</th><th>Markup %</th><th>Prezzo Vendita</th><th>Margine</th><th></th></tr></thead><tbody>' + serRows + '</tbody><tfoot><tr><td colspan="3" style="text-align:left">TOTALE</td><td data-label="Costo Tot.">' + fmt(c.tCS) + '</td><td data-label=""></td><td data-label="Prezzo Vendita" style="color:var(--o-action)">' + fmt(c.tVS) + '</td><td data-label="Margine" class="td-mg">' + fmt(c.tVS - c.tCS) + '</td><td data-label=""></td></tr></tfoot></table><button class="add-row" onclick="addRow(\'servizi\')"><i class="fas fa-plus-circle"></i>Aggiungi riga</button></div></div>' +
    '<div class="card sec-card-manut"><div class="sec-title sec-manut"><i class="fas fa-wrench"></i>Manutenzione</div><div class="card-body"><table><thead><tr><th>Descrizione</th><th>Qta</th><th>Costo/Un</th><th>Costo Tot.</th><th>Markup %</th><th>Prezzo Vendita</th><th>Margine</th><th></th></tr></thead><tbody>' + manRows2 + '</tbody><tfoot><tr><td colspan="3" style="text-align:left">TOTALE</td><td data-label="Costo Tot.">' + fmt(c.tCM2) + '</td><td data-label=""></td><td data-label="Prezzo Vendita" style="color:var(--o-action)">' + fmt(c.tVM2) + '</td><td data-label="Margine" class="td-mg">' + fmt(c.tVM2 - c.tCM2) + '</td><td data-label=""></td></tr></tfoot></table><button class="add-row" onclick="addRow(\'manutenzione\')"><i class="fas fa-plus-circle"></i>Aggiungi riga</button></div></div>' +
    '<div class="card sec-card-tras"><div class="sec-title sec-tras"><i class="fas fa-car"></i>Trasferte</div><div class="card-body"><table><thead><tr><th>Descrizione</th><th>Persone</th><th>Giorni</th><th>Costo/g/pers</th><th>Vitto/p/g</th><th>Alloggio/p/g</th><th>Km</th><th>EUR/km</th><th>Costo Tot.</th><th>Markup %</th><th>Prezzo Vendita</th><th>Margine</th><th></th></tr></thead><tbody>' + traRows + '</tbody><tfoot><tr><td colspan="8" style="text-align:left">TOTALE</td><td data-label="Costo Tot.">' + fmt(c.tCT) + '</td><td data-label=""></td><td data-label="Prezzo Vendita" style="color:var(--o-action)">' + fmt(c.tVT) + '</td><td data-label="Margine" class="td-mg">' + fmt(c.tVT - c.tCT) + '</td><td data-label=""></td></tr></tfoot></table><button class="add-row" onclick="addRow(\'trasferte\')"><i class="fas fa-plus-circle"></i>Aggiungi riga</button></div></div>' +
    /* ====== Card dettaglio Prezzo Imposto (visibile solo se attivo) ====== */
    (function(){
      if (!form.prezzoImpostoAttivo) return '';
      const isUser = currentUser.ruolo === "user";
      const stato = form.scontoStato || "";
      const piVal = parseFloat(form.prezzoImpostoValore) || 0;
      const mePreview = piVal - c.tC;
      const mPPreview = piVal > 0 ? (mePreview / piVal) * 100 : 0;
      let body = '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">' +
        '<label style="font-size:.9rem;font-weight:600;color:#6b7280">Prezzo target finale (EUR):</label>' +
        '<input type="number" id="prezzo-imposto-valore" step="0.01" min="0" value="' + piVal + '" oninput="syncFormFromDOM();form.prezzoImpostoValore=parseFloat(this.value)||0;render()" style="width:160px;border:1px solid #d1d5db;border-radius:5px;padding:6px 10px;font-size:1.05rem;font-weight:700">' +
        '<span style="font-size:.85rem;color:#6b7280">Margine risultante:</span>' +
        '<span class="' + mc(mPPreview) + '" style="font-size:1.1rem;font-weight:700">' + fmtPct(mPPreview) + '%</span>' +
        '<span style="font-size:.82rem;color:#6b7280">(' + (mePreview >= 0 ? '+' : '') + fmt(mePreview) + ' EUR)</span>' +
      '</div>';
      if (isUser) {
        // Soglia margine ruolo (parita' col server, vedi MARGINE_MIN_RUOLO in api/offerte.php)
        const minMargineUser = MARGINE_MIN_RUOLO[currentUser.ruolo] ?? 0;
        const margineOk = mPPreview >= minMargineUser;
        if (stato === "approvato") {
          body += '<div style="margin-top:10px;background:#dcfce7;color:#15803d;font-weight:700;padding:8px 14px;border-radius:6px;display:inline-block"><i class="fas fa-check-circle"></i> Prezzo concordato approvato: EUR ' + fmt(piVal) + '</div>';
        } else if (stato === "rifiutato") {
          body += '<div style="margin-top:10px;background:#fee2e2;color:#dc2626;font-weight:700;padding:8px 14px;border-radius:6px;display:inline-block"><i class="fas fa-times-circle"></i> Richiesta rifiutata' + (form.scontoNota ? ' — ' + esc(form.scontoNota) : '') + '</div>';
          if (margineOk) {
            body += '<div style="margin-top:6px;font-size:.82rem;color:#15803d">Il margine attuale (' + fmtPct(mPPreview) + '%) e\' sopra soglia: puoi salvare normalmente senza nuova richiesta.</div>';
          } else {
            body += '<div style="margin-top:6px"><button class="btn btn-orange" onclick="chiediApprovazionePrezzoImposto()"><i class="fas fa-paper-plane"></i> Ripeti richiesta</button></div>';
          }
        } else if (stato === "inattesa") {
          body += '<div style="margin-top:10px;background:#fef9c3;color:#ca8a04;font-weight:700;padding:8px 14px;border-radius:6px;display:inline-block"><i class="fas fa-hourglass-half"></i> Richiesta inviata — in attesa di approvazione</div>';
        } else if (margineOk) {
          // Nuovo v=65: margine >= soglia ruolo -> autoapprovato, niente richiesta
          body += '<div style="margin-top:10px;background:#dcfce7;color:#15803d;font-weight:700;padding:8px 14px;border-radius:6px;display:inline-block"><i class="fas fa-check-circle"></i> Margine ' + fmtPct(mPPreview) + '% sopra soglia (' + minMargineUser + '%) — salvabile senza approvazione</div>';
        } else {
          body += '<div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">' +
            '<span style="background:#fef3c7;color:#92400e;font-weight:600;padding:6px 12px;border-radius:6px;font-size:.85rem"><i class="fas fa-exclamation-triangle"></i> Margine ' + fmtPct(mPPreview) + '% sotto soglia (' + minMargineUser + '%) — serve approvazione</span>' +
            '<button class="btn btn-orange" onclick="chiediApprovazionePrezzoImposto()"><i class="fas fa-paper-plane"></i> Chiedi approvazione</button>' +
          '</div>';
        }
      } else {
        body += '<div style="margin-top:10px;background:#dcfce7;color:#15803d;font-weight:700;padding:8px 14px;border-radius:6px;display:inline-block"><i class="fas fa-check-circle"></i> Approvazione automatica (' + currentUser.ruolo + ')</div>';
      }
      return '<div class="card sec-card-sconto"><div class="sec-title sconto"><i class="fas fa-bullseye"></i>Dettaglio Prezzo Imposto</div><div class="card-body">' + body + '</div></div>';
    })() +
    /* ====== Sconto Direzione (nascosto se prezzo imposto attivo) ====== */
    (form.prezzoImpostoAttivo ? '' :
    '<div class="card sec-card-sconto"><div class="sec-title sconto"><i class="fas fa-percentage"></i>Sconto Direzione</div><div class="card-body">' +
    (currentUser.ruolo === "user" ?
      (form.scontoStato === "approvato" ?
        '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">' +
        '<span style="color:#15803d;font-weight:bold;font-size:1rem">Sconto approvato: ' + (form.scontoTipo === "pct" ? fmt(form.scontoValore) + "%" : "EUR " + fmt(form.scontoValore)) + '</span>' +
        (form.scontoNota ? '<span style="color:#6b7280;font-size:.85rem">Note: ' + esc(form.scontoNota) + '</span>' : '') +
        '</div>' :
      form.scontoStato === "rifiutato" ?
        '<div style="color:#dc2626;font-weight:bold">Sconto rifiutato' + (form.scontoNota ? ' -- ' + esc(form.scontoNota) : '') + '</div>' :
      form.scontoStato === "inattesa" ?
        '<div style="color:#ca8a04;font-weight:bold">Richiesta sconto inviata -- in attesa di approvazione</div>' :
        '<button class="btn btn-orange" onclick="chiediSconto()">Chiedi Sconto</button>'
      ) :
      '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">' +
      '<label style="font-size:.9rem;font-weight:600;color:#6b7280">Tipo:</label>' +
      '<select id="sconto-tipo" style="border:1px solid #d1d5db;border-radius:5px;padding:5px 8px">' +
      '<option value="pct"' + (form.scontoTipo === "pct" ? " selected" : "") + '>Percentuale %</option>' +
      '<option value="eur"' + (form.scontoTipo === "eur" ? " selected" : "") + '>Valore fisso EUR</option>' +
      '</select>' +
      '<input type="number" id="sconto-valore" style="width:100px;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px" value="' + (form.scontoValore || 0) + '" placeholder="0">' +
      '<input type="text" id="sconto-nota" style="width:220px;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px" value="' + esc(form.scontoNota || "") + '" placeholder="Nota opzionale">' +
      '<button class="btn btn-success" onclick="applicaSconto(\'approvato\')">APPROVA</button>' +
      '<button class="btn btn-danger" onclick="applicaSconto(\'rifiutato\')">RIFIUTA</button>' +
      (form.scontoStato === "inattesa" ? '<span style="background:#fef9c3;color:#ca8a04;font-weight:bold;padding:4px 10px;border-radius:6px;font-size:.82rem">Richiesta in attesa</span>' : '') +
      '</div>'
    ) +
    '</div></div>'
    ) +
    '<div class="card sec-card-spese"><div class="sec-title sec-spese"><i class="fas fa-coins"></i>Parametri offerta</div><div class="card-body" style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">' +
      '<div style="display:flex;align-items:center;gap:8px">' +
        '<label style="font-size:.85rem;color:var(--o-text-muted);font-weight:500">Spese Generali (% su costo totale):</label>' +
        '<input type="number"' + (isUserRole ? ' readonly class="markup-locked" title="Solo supervisore o admin possono modificare le spese generali"' : '') + ' style="width:80px;border:1px solid var(--o-border);border-radius:3px;padding:5px 8px" value="' + form.speseGenerali + '" oninput="syncFormFromDOM();form.speseGenerali=this.value;render()">' +
        '<span id="sg-eur" style="font-weight:600;color:#e65100;font-size:.95rem">= EUR ' + fmt(c.sg) + '</span>' +
      '</div>' +
      '<div style="display:flex;align-items:center;gap:8px">' +
        '<label style="font-size:.85rem;color:var(--o-text-muted);font-weight:500" title="Maggiorazione sui ricavi: alza il margine senza toccare le spese generali">Overmarkup (su ricavi):</label>' +
        '<select id="f-overmarkup" style="border:1px solid var(--o-border);border-radius:3px;padding:5px 8px;background:#fff" onchange="setOvermarkup(this)">' +
          [0,5,10,15,20,25,30].map(v => '<option value="' + v + '"' + ((parseInt(form.overmarkup,10)||0) === v ? ' selected' : '') + '>+' + v + '%</option>').join('') +
        '</select>' +
        (c.overmarkupValore > 0 ? '<span style="font-weight:600;color:#15803d;font-size:.95rem">= +EUR ' + fmt(c.overmarkupValore) + '</span>' : '') +
      '</div>' +
    '</div></div>' +
    '<div id="bb-warn-margine">' + bbWarnMargine(c) + '</div>' +
    '<div class="bottom-bar">' +
    '<div class="bb-item bb-item-secondary">Costo Totale: <span id="bb-tc" style="font-size:1.2rem;font-weight:500;color:var(--o-text)">EUR ' + fmt(c.tC) + '</span></div>' +
    '<div class="bb-item bb-item-primary">Prezzo al cliente: <span id="bb-tfsconto" style="font-size:1.2rem;font-weight:500;color:var(--o-action)">EUR ' + fmt(c.tFSconto) + '</span>' +
    (c.scontoE > 0 ? '<span style="font-size:.82rem;color:var(--o-danger);margin-left:8px;text-decoration:line-through">EUR ' + fmt(c.tF) + '</span><span style="font-size:.78rem;color:var(--o-danger);margin-left:6px">-' + fmt(c.scontoE) + ' EUR sconto</span>' : '') +
    '</div>' +
    '<div class="bb-item bb-item-primary">Margine: <span id="bb-mp" style="font-size:1.2rem;font-weight:500" class="' + mc(c.mP) + '">' + fmtPct(c.mP) + '%</span></div>' +
    '<button class="btn btn-purple bb-action" style="padding:8px 22px;font-size:.85rem" onclick="salva()"><i class="fas fa-save"></i>Salva Offerta</button></div>' +
    '</div></div>';
}

/* ============== RIEPILOGO ============== */

function renderRiepilogo(){
  const c = calcAll(form);
  const voci = [["Manodopera", c.tCP, c.tVP], ["Materiali", c.tCM, c.tVM], ["Servizi", c.tCS, c.tVS], ["Manutenzione", c.tCM2, c.tVM2], ["Trasferte", c.tCT, c.tVT]];
  const kc = c.mP >= MARGINE_THRESHOLDS.green ? "background:#dcfce7;color:#15803d" : c.mP >= MARGINE_THRESHOLDS.yellow ? "background:#fef9c3;color:#ca8a04" : "background:#fee2e2;color:#dc2626";
  const canEdit = currentUser.ruolo === "admin" || currentUser.ruolo === "supervisore" || form.userId === currentUser.id;

  const smartBtns = renderSmartButtons(c, { interactive: false });

  return renderTopnav() +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb">' +
        '<a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span>' +
        '<span class="o-bc-current">' + esc(form.nome || '--') + '</span>' +
      '</div>' +
      '<div class="o-cp-actions">' +
        (canEdit ? '<button class="btn btn-primary" onclick="screen=\'form\';render()">MODIFICA</button>' : '') +
        '<button class="btn btn-pdf" onclick="printPDF()"><i class="fas fa-file-pdf"></i>PDF</button>' +
        '<button class="btn btn-excel" onclick="exportExcel()"><i class="fas fa-file-excel"></i>Excel</button>' +
        (function(){
          const isUser = currentUser.ruolo === "user";
          const stato = form.scontoStato || "";
          const piAttivo = !!form.prezzoImpostoAttivo;
          const allegaOk = !isUser || stato === "approvato" || (stato === "" && !piAttivo);
          let tip = "";
          if (!allegaOk) {
            if (piAttivo && stato !== "approvato") tip = "Prezzo Imposto in attesa di approvazione: non puoi allegare a Odoo finché un supervisore non approva.";
            else if (stato === "inattesa") tip = "Sconto direzione in attesa di approvazione: non puoi allegare a Odoo finché un supervisore non approva.";
            else if (stato === "rifiutato") tip = "Richiesta rifiutata: rivedi costi/sconto e ripeti la richiesta prima di allegare.";
            else tip = "Approvazione richiesta prima di allegare a Odoo.";
          }
          return '<button id="btn-odoo" class="btn btn-odoo"' + (allegaOk ? '' : ' disabled style="opacity:.5;cursor:not-allowed"') + ' onclick="allegaAOdoo()"' + (tip ? ' title="' + escAttr(tip) + '"' : '') + '><i class="fas fa-paperclip"></i>Allega a Odoo</button>';
        })() +
      '</div>' +
      renderStatusBar(form) +
    '</div>' +
    smartBtns +
    '<div class="o-form-view"><div class="o-sheet">' +
    '<h1 class="o-form-title">' + esc(form.nome || '--') + '</h1>' +
    '<div class="o-form-subtitle">' +
      esc(form.cliente || "") +
      (form.tipo ? " · " + esc(form.tipo) : "") +
      (form.nOrdineOdoo ? ' · <span class="dash-odoo-chip">' + esc(form.nOrdineOdoo) + '</span>' : "") +
    '</div>' +
    '<div style="margin-top:20px"></div>' +
    (form.nOrdineOdoo ? '<div class="odoo-bar success"><i class="fas fa-link"></i>Allegati pronti per <b>' + esc(form.nOrdineOdoo) + '</b> in Odoo</div>' :
      '<div class="odoo-bar"><i class="fas fa-exclamation-triangle"></i>N. Prev/Ordine Odoo non inserito. Torna in modifica per inserirlo.</div>') +
    '<table class="riep-table" style="margin-top:6px"><thead><tr><th>Voce</th><th style="text-align:right">Costo</th><th style="text-align:right">Prezzo Vendita</th><th style="text-align:right">Margine</th><th style="text-align:right">Margine %</th></tr></thead><tbody>' +
    voci.map(([l, co, v]) => '<tr><td data-label="Voce" style="font-weight:500">' + l + '</td><td data-label="Costo" style="text-align:right">' + fmt(co) + '</td><td data-label="Prezzo Vendita" style="text-align:right;color:var(--o-action);font-weight:500">' + fmt(v) + '</td><td data-label="Margine" style="text-align:right;color:var(--o-success)">' + fmt(v - co) + '</td><td data-label="Margine %" style="text-align:right">' + fmtPct(v > 0 ? (v - co) / v * 100 : 0) + '%</td></tr>').join("") +
    '<tr class="riep-spese"><td data-label="Voce">Spese Generali (' + form.speseGenerali + '%)</td><td data-label="Costo" style="text-align:right">--</td><td data-label="Prezzo Vendita" style="text-align:right">' + fmt(c.sg) + '</td><td colspan="2"></td></tr>' +
    (c.scontoE > 0 && !c.prezzoImpostoOk
      ? '<tr style="background:#fce4e4"><td data-label="Voce" style="font-weight:500;color:var(--o-danger)">Sconto Direzione</td><td data-label="Costo" style="text-align:right">--</td><td data-label="Prezzo Vendita" style="text-align:right;color:var(--o-danger);font-weight:500">- ' + fmt(c.scontoE) + '</td><td colspan="2"></td></tr>'
      : '') +
    '</tbody><tfoot>' +
    '<tr class="riep-total' + (c.prezzoImpostoOk ? ' riep-total-calc' : '') + '"><td data-label="Voce">TOTALE CALCOLATO</td><td data-label="Costo" style="text-align:right">' + fmt(c.tC) + '</td><td data-label="Prezzo Vendita" style="text-align:right">' + fmt(c.tFCalc) + '</td><td data-label="Margine" style="text-align:right">' + fmt(c.mECalc) + '</td><td data-label="Margine %" style="text-align:right">' + fmtPct(c.mPCalc) + '%</td></tr>' +
    (c.prezzoImpostoOk
      ? '<tr class="riep-total riep-total-pi"><td data-label="Voce"><i class="fas fa-bullseye"></i> PREZZO IMPOSTO</td><td data-label="Costo" style="text-align:right">' + fmt(c.tC) + '</td><td data-label="Prezzo Vendita" style="text-align:right">' + fmt(c.tFSconto) + '</td><td data-label="Margine" style="text-align:right">' + fmt(c.mE) + '</td><td data-label="Margine %" style="text-align:right">' + fmtPct(c.mP) + '%</td></tr>'
      : '') +
    '</tfoot></table>' +
    '<div class="kpi-grid">' +
    '<div class="kpi" style="background:#f8f9fa;color:var(--o-text)"><div class="label">Costo</div><div class="value">' + fmt(c.tC) + ' EUR</div></div>' +
    '<div class="kpi" style="background:#e7f4f5;color:var(--o-action)"><div class="label">' + (c.prezzoImpostoOk ? 'Prezzo Imposto' : 'Prezzo Cliente') + '</div><div class="value">' + fmt(c.tFSconto) + ' EUR</div></div>' +
    '<div class="kpi" style="background:#d4edda;color:#155724"><div class="label">Margine EUR</div><div class="value">' + fmt(c.mE) + ' EUR</div></div>' +
    '<div class="kpi" style="' + kc + '"><div class="label">Margine %</div><div class="value">' + fmtPct(c.mP) + '%</div></div>' +
    '</div>' +
    (form.note ? '<div style="margin-top:14px;padding:12px;background:#fffbea;border-left:3px solid var(--o-warning);border-radius:3px"><b style="font-size:.78rem;color:#856404">Note:</b> <span style="font-size:.85rem">' + esc(form.note) + '</span></div>' : "") +
    '</div></div>';
}

