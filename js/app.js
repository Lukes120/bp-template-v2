// Stato applicativo + router + handler azioni utente.
// Carica utenti/offerte iniziali, renderizza screen, gestisce login, salvataggi, Odoo.

let utenti = [], offerte = [], currentUser = null, auditEntries = [];
const _urlParams = new URLSearchParams(window.location.search);
window._pendingOdoo = (_urlParams.get("nOrdine") || "").trim().toUpperCase();
let screen = "loading", form = emptyForm(), editId = null, filterUser = "all", filterText = "";
let formDirty = false;

// Avvisa l'utente se chiude la tab con modifiche non salvate al form offerta
window.addEventListener('beforeunload', e => {
  if (formDirty && screen === 'form') { e.preventDefault(); e.returnValue = ''; }
});

async function initApp(){
  // Fast-path: nessuna sessione cached → mostra subito il login, niente API call sprecate
  const cached = localStorage.getItem('bp_current_user');
  if (!cached) { screen = "login"; render(); return; }

  render();
  const safety = setTimeout(() => {
    if (screen === 'loading') { screen = 'login'; try { render(); } catch (_) {} }
  }, 8000);

  try {
    const [ru, ro] = await Promise.all([apiGet(API.utenti), apiGet(API.offerte)]);
    if (Array.isArray(ru) && Array.isArray(ro)) {
      utenti = ru; offerte = ro;
      try {
        currentUser = JSON.parse(cached);
        clearTimeout(safety);
        screen = "list"; render(); gestisciParamOdoo(); return;
      } catch (_) { localStorage.removeItem('bp_current_user'); }
    } else {
      localStorage.removeItem('bp_current_user');
    }
  } catch (e) {
    if (e.message !== 'unauthorized') {
      console.error('initApp:', e);
    }
  }
  clearTimeout(safety);
  screen = "login"; render();
}

function render(){
  const app = document.getElementById("app");
  if (screen === "form") syncFormFromDOM();
  if (screen === "loading") {
    app.innerHTML = '<div class="login-wrap"><div class="login-card"><img src="logo.png" style="max-width:180px;margin-bottom:16px" alt="Ecotel Italia"><p>Connessione in corso...</p></div></div>';
    return;
  }
  if      (screen === "login")      app.innerHTML = renderLogin();
  else if (screen === "list")       app.innerHTML = renderList();
  else if (screen === "form")       app.innerHTML = renderForm();
  else if (screen === "riepilogo")  app.innerHTML = renderRiepilogo();
  else if (screen === "adminUsers") app.innerHTML = renderAdminUsers();
  else if (screen === "audit")      app.innerHTML = renderAudit();
  else if (screen === "approvazioni") app.innerHTML = renderApprovazioni();
  else if (screen === "profilo")    app.innerHTML = renderProfilo();
  else if (screen === "guida")      app.innerHTML = renderGuida();
  bindEvents();
  bindBurger();
}

/* ============== AUTH ============== */

let _2faPendingToken = null; // ticket pending tra 1° e 2° submit (vedi bp_odoo_pending_2fa)

async function doLogin(e){
  if (e) e.preventDefault();
  const u = document.getElementById("l-user").value.trim();
  const p = document.getElementById("l-pass").value;
  const totpEl = document.getElementById("l-totp");
  const totp = totpEl ? totpEl.value.trim() : "";
  const btn = document.getElementById("l-submit");

  // Overlay grigio + cursore loading: il flow SSO (specialmente con 2FA) ha 4 round-trip
  // verso Odoo, puo' impiegare 3-6 secondi. Senza feedback l'utente preme due volte.
  document.body.classList.add("odoo-loading");
  if (btn) btn.disabled = true;
  let keepOverlay = false; // true = lascia overlay per transizione a dashboard

  try {
    const payload = { username: u, password: p, totp };
    if (_2faPendingToken && totp) payload.pending_token = _2faPendingToken;
    const data = await apiPost(API.login, payload);
    // 2FA richiesto: mostro il campo TOTP e aspetto un secondo submit con il codice
    if (data && data.totp_required) {
      _2faPendingToken = data.pending_token || null;
      const wrap = document.getElementById("l-totp-wrap");
      const err  = document.getElementById("login-err");
      if (wrap) wrap.style.display = "block";
      if (totpEl) { totpEl.focus(); totpEl.select(); }
      if (btn) btn.innerHTML = '<i class="fas fa-shield-alt"></i> Conferma 2FA';
      if (err) { err.style.color = "#1d4ed8"; err.textContent = "Inserisci il codice 2FA dal tuo Authenticator."; }
      return;
    }
    // Login OK o errore definitivo: reset del token pending
    _2faPendingToken = null;
    if (data.error || !data.ok) {
      document.getElementById("login-err").textContent = data.error || "Login fallito";
      return;
    }
    // Login OK: tieni overlay finche' la dashboard non e' renderizzata
    keepOverlay = true;

    localStorage.setItem('bp_last_user', u);
    localStorage.setItem('bp_current_user', JSON.stringify(data.user));

    // Suggerisci al browser di salvare le credenziali (Credential Management API).
    if (window.PasswordCredential) {
      try {
        const cred = new PasswordCredential({
          id: u, password: p, name: data.user.nome || u,
        });
        await navigator.credentials.store(cred);
      } catch (_) { /* non blocca il flusso */ }
    }

    currentUser = data.user;

    // Carica utenti+offerte dal server: il fast-path di initApp aveva skippato il fetch
    // perche' localStorage era vuoto prima del login.
    try {
      const [ru, ro] = await Promise.all([apiGet(API.utenti), apiGet(API.offerte)]);
      if (Array.isArray(ru)) utenti  = ru;
      if (Array.isArray(ro)) offerte = ro;
    } catch (_) { /* render mostrera' lista vuota */ }

    // Lascio il password field nel DOM per ~50ms così Chrome aggancia il "form login fatto".
    setTimeout(() => {
      if (currentUser.firstLogin) {
        screen = "profilo"; render();
        setTimeout(() => {
          const err = document.getElementById("p-pass-err");
          if (err) { err.style.color = "#dc2626"; err.textContent = "Primo accesso: devi cambiare la password!"; }
        }, 100);
      } else {
        screen = "list"; render(); gestisciParamOdoo();
      }
      // Tolgo overlay solo dopo la transizione visiva
      document.body.classList.remove("odoo-loading");
      if (btn) btn.disabled = false;
    }, 50);
  } catch (err) {
    document.getElementById("login-err").textContent = err && err.message ? "Errore: " + err.message : "Errore di rete";
  } finally {
    if (!keepOverlay) {
      document.body.classList.remove("odoo-loading");
      if (btn) btn.disabled = false;
    }
  }
}

async function doLogout(){
  if (formDirty && screen === "form") {
    if (!confirm("Hai modifiche non salvate nell'offerta. Uscire e perdere le modifiche?")) return;
  }
  try { await apiPost(API.logout, {}); } catch (_) {}
  localStorage.removeItem('bp_current_user');
  currentUser = null; formDirty = false; screen = "login"; render();
}

/* ============== UTENTI ADMIN ============== */

async function creaUtente(){
  const nome = document.getElementById("nu-nome").value.trim();
  const username = document.getElementById("nu-user").value.trim();
  const password = document.getElementById("nu-pass").value;
  const ruolo = document.getElementById("nu-ruolo").value;
  const err = document.getElementById("nu-err");
  if (!nome || !username || !password) { err.textContent = "Compila tutti i campi."; return; }
  if (utenti.find(u => u.username === username)) { err.textContent = "Username gia in uso."; return; }
  const email = document.getElementById("nu-email").value.trim();
  const nu = { id: uid(), nome, username, password, ruolo, email, firstLogin: true };
  utenti.push({ ...nu });
  delete utenti[utenti.length - 1].password;  // non tenere password in memoria
  await fbSaveUtente(nu);
  toast("Utente " + nome + " creato!" + (email ? " Mail inviata a " + email : ""), "success", 4000);
  render();
}

async function eliminaUtente(id){
  const n = offerte.filter(o => o.userId === id).length;
  if (!confirm(n > 0 ? "Utente con " + n + " offerte. Continuare?" : "Eliminare?")) return;
  utenti = utenti.filter(u => u.id !== id);
  await fbDelUtente(id); render();
}

function modificaUtente(id){
  const u = utenti.find(x => x.id === id);
  if (!u) return;
  const html =
    '<div style="padding:24px"><h2 style="font-size:1.1rem;color:#1e293b;margin-bottom:16px">Modifica utente</h2>' +
    '<div class="grid-form" style="grid-template-columns:1fr 1fr">' +
    '<div><label>Nome</label><input id="m-nome" value="' + (u.nome || "") + '"></div>' +
    '<div><label>Username</label><input id="m-user" value="' + (u.username || "") + '"></div>' +
    '<div class="full"><label>Email</label><input id="m-email" type="email" value="' + (u.email || "") + '"></div>' +
    '<div><label>Ruolo</label><select id="m-ruolo">' +
      ['user','viewer','supervisore','admin'].map(r => '<option value="' + r + '"' + (u.ruolo === r ? ' selected' : '') + '>' + r + '</option>').join("") +
    '</select></div>' +
    '<div><label>Nuova password</label><input id="m-pass" type="password" placeholder="lascia vuoto per non cambiare"></div>' +
    '</div>' +
    '<div id="m-err" style="color:#dc2626;font-size:.82rem;margin-top:10px;min-height:18px"></div>' +
    '<div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end">' +
    '<button class="btn btn-secondary" onclick="closeModal()">Annulla</button>' +
    '<button class="btn btn-purple" onclick="confermaModificaUtente(\'' + id + '\')"><i class="fas fa-save"></i>Salva</button>' +
    '</div></div>';
  openModal(html, { maxWidth: '520px' });
  setTimeout(() => document.getElementById('m-nome')?.focus(), 50);
}

async function confermaModificaUtente(id){
  const u = utenti.find(x => x.id === id); if (!u) return;
  const nome     = document.getElementById('m-nome').value.trim();
  const username = document.getElementById('m-user').value.trim();
  const email    = document.getElementById('m-email').value.trim();
  const ruolo    = document.getElementById('m-ruolo').value;
  const pass     = document.getElementById('m-pass').value;
  const err      = document.getElementById('m-err');
  if (!nome || !username) { err.textContent = "Nome e username obbligatori."; return; }
  if (utenti.find(x => x.username === username && x.id !== id)) { err.textContent = "Username già in uso."; return; }
  const uAggiornato = { ...u, nome, username, email, ruolo };
  if (pass.trim() !== "") uAggiornato.password = pass.trim();
  utenti = utenti.map(x => x.id === id ? { ...uAggiornato, password: undefined } : x);
  await fbSaveUtente(uAggiornato);
  closeModal();
  toast("Utente aggiornato", "success");
  render();
}

async function reinviaMailUtente(id){
  const u = utenti.find(x => x.id === id);
  if (!u || !u.email) { toast("Utente senza email!", "warn"); return; }
  if (!confirm("Reinviare le credenziali a " + u.email + "?")) return;
  const nuovaPass = prompt("Inserisci la password (le password non sono recuperabili):");
  if (!nuovaPass) return;
  try {
    const d = await apiPost(API.reinviaMail, { nome: u.nome, username: u.username, password: nuovaPass, email: u.email });
    if (d.ok) toast("Mail inviata a " + u.email, "success", 4000);
    else toast("Errore invio mail: " + (d.error || ""), "error", 5000);
  } catch (e) { toast("Errore: " + e.message, "error", 5000); }
}

/* ============== PROFILO ============== */

async function salvaDatiProfilo(){
  const nome = document.getElementById("p-nome").value.trim();
  const username = document.getElementById("p-user").value.trim();
  const err = document.getElementById("p-info-err");
  if (!nome || !username) { err.style.color = "#dc2626"; err.textContent = "Compila tutti i campi."; return; }
  if (utenti.find(u => u.username === username && u.id !== currentUser.id)) {
    err.style.color = "#dc2626"; err.textContent = "Username gia in uso."; return;
  }
  utenti = utenti.map(u => u.id === currentUser.id ? { ...u, nome, username } : u);
  currentUser = { ...currentUser, nome, username };
  await fbSaveUtente({ ...currentUser });
  err.style.color = "#15803d"; err.textContent = "Aggiornato!";
}

async function salvaPassword(){
  const o = document.getElementById("p-old").value;
  const n = document.getElementById("p-new").value;
  const n2 = document.getElementById("p-new2").value;
  const err = document.getElementById("p-pass-err");
  err.style.color = "#dc2626";
  if (!o || !n || !n2) { err.textContent = "Compila tutti i campi."; return; }
  if (n.length < 4)    { err.textContent = "Minimo 4 caratteri."; return; }
  if (n !== n2)        { err.textContent = "Le password non coincidono."; return; }

  // verifica password attuale via login
  const check = await apiPost(API.login, { username: currentUser.username, password: o });
  if (!check.ok) { err.textContent = "Password attuale non corretta."; return; }

  await fbSaveUtente({ ...currentUser, password: n, firstLogin: false });
  currentUser = { ...currentUser, firstLogin: false };
  ["p-old", "p-new", "p-new2"].forEach(id => document.getElementById(id).value = "");
  err.style.color = "#15803d"; err.textContent = "Password cambiata!";
  setTimeout(() => { screen = "list"; render(); }, 1500);
}

/* ============== FORM HELPERS ============== */

function syncFormFromDOM(){
  ["nome", "cliente", "tipo", "nOrdineOdoo", "data", "note"].forEach(k => {
    const el = document.getElementById("f-" + k); if (el) form[k] = el.value;
  });
  const st = document.getElementById("sconto-tipo");    if (st) form.scontoTipo = st.value;
  const sv = document.getElementById("sconto-valore");  if (sv) form.scontoValore = parseFloat(sv.value) || 0;
  const sn = document.getElementById("sconto-nota");    if (sn) form.scontoNota = sn.value;
  const pi = document.getElementById("prezzo-imposto-valore");
  if (pi) form.prezzoImpostoValore = parseFloat(pi.value) || 0;
  const om = document.getElementById("f-overmarkup");
  if (om) form.overmarkup = parseInt(om.value, 10) || 0;
}

function aggiornaCostoH(sel){
  syncFormFromDOM();
  formDirty = true;
  const id = sel.dataset.id, cat = sel.value, costo = CATEGORIE[cat] || 0;
  form.personale = form.personale.map(r => r.id == id ? { ...r, categoria: cat, costoH: costo } : r);
  render();
}

// Wrapper per onchange del <select id="f-overmarkup">. NON inlineare la logica:
// dentro un onchange inline di un <select>, l'identifier `form` viene risolto a
// this.form (form owner DOM) prima della variabile globale, e se il select non è
// dentro un <form> element this.form===null → "Cannot set properties of null".
function setOvermarkup(sel){
  form.overmarkup = parseInt(sel.value, 10) || 0;
  formDirty = true;
  render();
}

function syncAndGo(dest){ syncFormFromDOM(); screen = dest; render(); }

function nuovaOfferta(){ form = emptyForm(); editId = null; formDirty = false; screen = "form"; render(); }
function goList(){ formDirty = false; screen = "list"; render(); }

async function _notificaEsito(offertaId, esito, motivo){
  try {
    await apiPost(API.notificaEsito, { offertaId, esito, motivo: motivo || "" });
  } catch (e) { console.error("Notifica esito fallita:", e); }
}

async function approvaSconto(id){
  const o = offerte.find(x => x.id === id); if (!o) return;
  const isPI = !!o.prezzoImpostoAttivo;
  const descr = isPI
    ? "il prezzo imposto di EUR " + fmt(o.prezzoImpostoValore || 0)
    : "lo sconto di " + ((o.scontoTipo || "pct") === "pct" ? (o.scontoValore || 0) + "%" : "EUR " + (o.scontoValore || 0));
  if (!confirm(`Approvare ${descr} per "${o.nome}"?`)) return;
  const aggiornato = { ...o, scontoStato: "approvato" };
  offerte = offerte.map(x => x.id === id ? aggiornato : x);
  await fbSaveOfferta(aggiornato);
  await _notificaEsito(id, "approvato", "");
  toast(isPI ? "Prezzo imposto approvato — email inviata al richiedente" : "Sconto approvato — email inviata al richiedente", "success", 4000);
  render();
}

async function rifiutaSconto(id){
  const o = offerte.find(x => x.id === id); if (!o) return;
  const isPI = !!o.prezzoImpostoAttivo;
  const motivo = prompt("Motivo del rifiuto (opzionale):", o.scontoNota || "");
  if (motivo === null) return;
  const aggiornato = { ...o, scontoStato: "rifiutato", scontoNota: motivo };
  offerte = offerte.map(x => x.id === id ? aggiornato : x);
  await fbSaveOfferta(aggiornato);
  await _notificaEsito(id, "rifiutato", motivo);
  toast(isPI ? "Prezzo imposto rifiutato — email inviata al richiedente" : "Sconto rifiutato — email inviata al richiedente", "warn", 4000);
  render();
}

async function apriAudit(){
  try {
    auditEntries = await apiGet(API.audit + '?limit=200');
  } catch (e) {
    toast("Errore caricamento audit log", "error");
    return;
  }
  screen = "audit"; render();
}

async function apriUtenti(){
  try {
    const [ru, ro] = await Promise.all([apiGet(API.utenti), apiGet(API.offerte)]);
    if (Array.isArray(ru)) utenti = ru;
    if (Array.isArray(ro)) offerte = ro;
  } catch (e) {
    toast("Errore caricamento utenti", "error");
  }
  screen = "adminUsers"; render();
}

let _searchTimer = null;
function onSearchInput(val){
  filterText = val;
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(() => {
    render();
    const el = document.getElementById("dash-search");
    if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
  }, 200);
}

function apriModifica(id){
  const o = offerte.find(x => x.id === id);
  if (o) { form = JSON.parse(JSON.stringify(o)); editId = o.id; formDirty = false; screen = "form"; render(); }
}

function annullaModifiche(){
  if (!editId) return;
  if (!confirm("Scartare le modifiche e tornare alla versione salvata?")) return;
  const o = offerte.find(x => x.id === editId);
  if (o) { form = JSON.parse(JSON.stringify(o)); formDirty = false; render(); toast("Modifiche annullate", "info"); }
}

function apriRiepilogo(id){
  const o = offerte.find(x => x.id === id);
  if (o) { form = JSON.parse(JSON.stringify(o)); editId = o.id; formDirty = false; screen = "riepilogo"; render(); }
}

async function elimina(id){
  if (!confirm("Eliminare questa offerta?")) return;
  offerte = offerte.filter(o => o.id !== id);
  await fbDelOfferta(id); render();
}

async function duplica(id){
  const o = offerte.find(x => x.id === id); if (!o) return;
  form = JSON.parse(JSON.stringify(o));
  form.id = uid();
  form.nome = "Copia di " + form.nome;
  form.nOrdineOdoo = "";
  form.scontoStato = ""; form.scontoTipo = "pct"; form.scontoValore = 0; form.scontoNota = "";
  form.prezzoImpostoAttivo = false; form.prezzoImpostoValore = 0;
  editId = null;
  offerte.push({ ...form });
  await fbSaveOfferta({ ...form });
  editId = form.id;
  formDirty = false;
  screen = "form"; render();
}

async function salva(){
  syncFormFromDOM();
  if (!form.nome.trim())                    { toast("Inserisci il nome della commessa", "warn"); return; }
  if (!(form.nOrdineOdoo || "").trim())     { toast("Inserisci il N. Prev/Ordine Odoo", "warn"); return; }

  // Validazione margine minimo per ruolo
  const c = calcAll(form);
  const minMargine = MARGINE_MIN_RUOLO[currentUser.ruolo] ?? 0;
  const scontoApprovato = form.scontoStato === "approvato";
  const margineOk = c.mP >= minMargine;
  // Modalità prezzo imposto: per user, blocca il salvataggio SOLO se margine
  // sotto soglia ruolo. Con margine OK il PI passa senza approvazione
  // (replica regola server api/offerte.php:167, v=65 commit c7b4374).
  if (form.prezzoImpostoAttivo && !scontoApprovato && currentUser.ruolo === "user" && !margineOk) {
    toast(
      `Prezzo imposto: margine ${fmtPct(c.mP)}% sotto la soglia minima del ${minMargine}% per il tuo ruolo. ` +
      `Clicca "Chiedi approvazione" per inviare la richiesta al supervisore.`,
      "error", 6000
    );
    return;
  }
  if (c.mP < minMargine && !scontoApprovato) {
    toast(
      `Margine ${fmtPct(c.mP)}% sotto la soglia minima del ${minMargine}% per il tuo ruolo. ` +
      `Salva una bozza chiedendo lo sconto direzione, oppure rivedi costi/markup.`,
      "error", 6000
    );
    return;
  }

  // v=68: se PI attivo + margine OK + stato vuoto, marca scontoStato='approvato'
  // (replica server api/offerte.php). Garantisce che il riepilogo subito dopo il
  // save mostri la sezione PI come totale praticato, senza richiedere un reload.
  if (form.prezzoImpostoAttivo && margineOk && !(form.scontoStato || "")) {
    form.scontoStato = "approvato";
  }

  form.userId = currentUser.id;
  form.userName = currentUser.nome;
  if (!editId) form.id = uid();
  if (editId) {
    offerte = offerte.map(o => o.id === editId ? { ...form } : o);
  } else {
    offerte.push({ ...form });
  }
  await fbSaveOfferta({ ...form });
  formDirty = false;
  toast(editId ? "Offerta aggiornata" : "Offerta creata", "success");
  screen = "list"; render();
}

function addRow(key){
  syncFormFromDOM();
  formDirty = true;
  const mk = {
    personale:    () => { const cat = Object.keys(CATEGORIE)[0]; return { id: uid(), categoria: cat, oreG: "", costoH: CATEGORIE[cat], markup: "35" }; },
    materiali:    () => ({ id: uid(), desc: "", qta: "", costoU: "", markup: "25" }),
    servizi:      () => ({ id: uid(), desc: "", qta: "", costoU: "", markup: "20" }),
    manutenzione: () => ({ id: uid(), desc: "", qta: "", costoU: "", markup: "25" }),
    trasferte:    () => ({ id: uid(), desc: "", persone: "", giorni: "", costoGiorno: "", vitto: "", alloggio: "", km: "", costoKm: "0.30", markup: "10" }),
  };
  form[key].push(mk[key]()); render();
}

function delRow(key, id){
  syncFormFromDOM();
  formDirty = true;
  form[key] = form[key].filter(r => r.id != id);
  render();
}

// Event delegation: 1 solo listener "input" su #app, installato 1 sola volta in vita app.
// Prima (audit pre-P1.3) si attaccava un listener ad ogni input [data-key][data-field] e si
// riattaccava ad ogni rerender (75+ listener ricostruiti). Ora 1 listener fisso, logica invariata.
// Hamburger menu mobile (v=62): toggle drawer + outside-click chiude + link tap chiude.
// Listener su document, idempotente via flag. Nessun impatto desktop: il bottone burger
// e' display:none sopra 768px, quindi il branch toggle non scatta mai.
let _bpBurgerBound = false;
function bindBurger(){
  if (_bpBurgerBound) return;
  _bpBurgerBound = true;
  document.addEventListener("click", function(e){
    const topnav = document.querySelector(".topnav");
    if (!topnav) return;
    const burger = e.target.closest('[data-action="topnav-burger"]');
    if (burger){
      e.preventDefault();
      const open = topnav.classList.toggle("topnav-open");
      burger.setAttribute("aria-expanded", open ? "true" : "false");
      return;
    }
    if (!topnav.classList.contains("topnav-open")) return;
    // Click su link nav o fuori dal nav: chiudi
    if (e.target.closest(".topnav-link") || !e.target.closest(".topnav")){
      topnav.classList.remove("topnav-open");
      const b = topnav.querySelector('[data-action="topnav-burger"]');
      if (b) b.setAttribute("aria-expanded", "false");
    }
  });
}

let _bpInputBound = false;
function bindEvents(){
  if (_bpInputBound) return;
  const app = document.getElementById("app");
  if (!app) return;
  _bpInputBound = true;
  app.addEventListener("input", function(e){
    const el = e.target.closest("[data-key][data-field]");
    if (!el) return;
    formDirty = true;
    const key = el.dataset.key, field = el.dataset.field;
    if (!form[key]) return;
    form[key] = form[key].map(r => r.id == el.dataset.id ? { ...r, [field]: el.value } : r);
    const c = calcAll(form, true);
    const row = el.closest("tr"); if (!row) return;
    const tdR = row.querySelectorAll(".td-r"), tdPv = row.querySelector(".td-pv"), tdMg = row.querySelector(".td-mg");
    const map = { personale: c.cp, materiali: c.cm, servizi: c.cs, manutenzione: c.cm2, trasferte: c.ct };
    const r2 = map[key]?.find(x => x.id == el.dataset.id);
    if (r2) {
      if (tdR[0]) tdR[0].textContent = fmt(r2.b);
      if (tdPv)   tdPv.textContent   = fmt(r2.pv);
      if (tdMg)   tdMg.textContent   = fmt(r2.pv - r2.b);
    }
    const tf = row.closest("table")?.querySelector("tfoot");
    if (tf) {
      const cc = tf.querySelectorAll("td");
      if (key === "personale" && cc.length >= 5) { cc[1].textContent = fmt(c.tCP); cc[3].textContent = fmt(c.tVP); cc[4].textContent = fmt(c.tVP - c.tCP); }
      if ((key === "materiali" || key === "servizi" || key === "manutenzione") && cc.length >= 5) {
        const [ct, vt] = key === "materiali" ? [c.tCM, c.tVM] : key === "servizi" ? [c.tCS, c.tVS] : [c.tCM2, c.tVM2];
        cc[1].textContent = fmt(ct); cc[3].textContent = fmt(vt); cc[4].textContent = fmt(vt - ct);
      }
      if (key === "trasferte" && cc.length >= 5) { cc[1].textContent = fmt(c.tCT); cc[3].textContent = fmt(c.tVT); cc[4].textContent = fmt(c.tVT - c.tCT); }
    }
    // Repaint chirurgico KPI bottom-bar via id (NON usare indici querySelectorAll: ordine span
    // fragile, gia' rotto in v=56 quando e' stato aggiunto "Costo Totale" come primo div).
    const bbTc = document.getElementById("bb-tc");
    if (bbTc) bbTc.textContent = "EUR " + fmt(c.tC);
    const bbTf = document.getElementById("bb-tfsconto");
    if (bbTf) bbTf.textContent = "EUR " + fmt(c.tFSconto);
    const bbMp = document.getElementById("bb-mp");
    if (bbMp) { bbMp.textContent = fmtPct(c.mP) + "%"; bbMp.className = mc(c.mP); }
    // Banner "margine sotto soglia" va rigenerato live: se il margine sale sopra soglia il
    // banner deve sparire, e viceversa. Senza questo restava stale (cifra vecchia + non si nascondeva).
    const bbWarn = document.getElementById("bb-warn-margine");
    if (bbWarn) bbWarn.innerHTML = bbWarnMargine(c);
    // Card Spese Generali: il valore EUR dipende da tC (somma costi) e va aggiornato live
    // ad ogni modifica di riga. Senza questo, lo span restava stale fino a render() completo.
    const sgEur = document.getElementById("sg-eur");
    if (sgEur) sgEur.textContent = "= EUR " + fmt(c.sg);
  });
}

/* ============== SCONTO ============== */

async function chiediSconto(){
  syncFormFromDOM();
  if (!form.nome.trim())                { toast("Inserisci il nome della commessa", "warn"); return; }
  if (!(form.nOrdineOdoo || "").trim()) { toast("Inserisci il N. Prev/Ordine Odoo", "warn"); return; }
  if (!confirm("Inviare richiesta di sconto ai supervisori?")) return;
  const statoBackup = form.scontoStato;
  form.scontoStato = "inattesa";
  form.userId = currentUser.id; form.userName = currentUser.nome;
  if (!editId) {
    form.id = uid();
    offerte.push({ ...form });
    editId = form.id;
  } else {
    offerte = offerte.map(o => o.id === editId ? { ...form } : o);
  }
  // Save server-side PRIMA della mail. Se il server rifiuta (es. validazione),
  // l'offerta non e' in DB e mandare la mail farebbe arrivare al supervisore una
  // notifica per qualcosa che non esiste. Rollback in-memory dello scontoStato.
  const saveRes = await fbSaveOfferta({ ...form });
  if (saveRes && saveRes.error) {
    form.scontoStato = statoBackup;
    if (offerte.length && offerte[offerte.length - 1].id === form.id && statoBackup === "") {
      offerte.pop(); editId = null;
    }
    toast("Salvataggio fallito: " + saveRes.error, "err", 8000);
    return;
  }
  formDirty = false;
  let mailOk = false;
  try {
    const c2 = calcAll(form);
    const r = await apiPost(API.notificaSconto, {
      offerta: form.nome, cliente: form.cliente || "", utente: currentUser.nome,
      totale: c2.tFSconto, margine: c2.mP, tipo: "sconto", nOrdine: form.nOrdineOdoo || "",
      offerta_id: form.id,
    });
    mailOk = r && r.ok === true;
  } catch (e) { console.error("Notifica email fallita:", e); }
  render();
  if (mailOk) toast("Richiesta sconto inviata ai supervisori", "success", 4000);
  else toast("Stato salvato, ma notifica email NON inviata. Contatta il supervisore manualmente.", "warn", 6000);
}

function applicaSconto(stato){
  const tipo = document.getElementById("sconto-tipo")?.value || "pct";
  const valore = parseFloat(document.getElementById("sconto-valore")?.value) || 0;
  const nota = document.getElementById("sconto-nota")?.value || "";
  form.scontoStato = stato;
  form.scontoTipo = tipo;
  form.scontoValore = valore;
  form.scontoNota = nota;
  offerte = offerte.map(o => o.id === editId ? { ...form } : o);
  fbSaveOfferta({ ...form });
  render();
  toast(stato === "approvato" ? "Sconto approvato" : "Sconto rifiutato", stato === "approvato" ? "success" : "warn");
}

/* ============== PREZZO IMPOSTO ============== */

function togglePrezzoImposto(checked){
  syncFormFromDOM();
  formDirty = true;
  if (checked) {
    form.prezzoImpostoAttivo = true;
    // Reset sconto direzione: i due sono mutualmente esclusivi
    form.scontoTipo = "pct"; form.scontoValore = 0; form.scontoNota = "";
    if (currentUser.ruolo === "admin" || currentUser.ruolo === "supervisore") {
      // Auto-approvazione per ruoli con permesso
      form.scontoStato = "approvato";
    } else {
      // User: in attesa di richiesta esplicita
      form.scontoStato = "";
    }
  } else {
    form.prezzoImpostoAttivo = false;
    form.prezzoImpostoValore = 0;
    form.scontoStato = "";
    form.scontoNota = "";
  }
  render();
}

// Chiamato dal bottone "Attiva Prezzo Imposto" sopra Dati Commessa.
// Attiva la modalità e imposta il valore di default al totale calcolato corrente, poi scrolla al campo.
function apriPrezzoImposto(){
  syncFormFromDOM();
  const c = calcAll(form);
  if (!form.prezzoImpostoValore || parseFloat(form.prezzoImpostoValore) <= 0) {
    form.prezzoImpostoValore = Math.round(c.tFCalc || c.tF || 0);
  }
  togglePrezzoImposto(true);
  setTimeout(() => {
    const inp = document.getElementById('prezzo-imposto-valore');
    if (inp) { inp.scrollIntoView({ behavior: 'smooth', block: 'center' }); inp.focus(); inp.select(); }
  }, 50);
}

async function chiediApprovazionePrezzoImposto(){
  syncFormFromDOM();
  if (!form.nome.trim())                { toast("Inserisci il nome della commessa", "warn"); return; }
  if (!(form.nOrdineOdoo || "").trim()) { toast("Inserisci il N. Prev/Ordine Odoo", "warn"); return; }
  const piVal = parseFloat(form.prezzoImpostoValore) || 0;
  if (piVal <= 0) { toast("Inserisci un prezzo target maggiore di zero.", "warn"); return; }
  if (!confirm("Inviare richiesta di approvazione del prezzo imposto a EUR " + fmt(piVal) + "?")) return;
  const statoBackup = form.scontoStato;
  form.scontoStato = "inattesa";
  form.userId = currentUser.id; form.userName = currentUser.nome;
  if (!editId) {
    form.id = uid();
    offerte.push({ ...form });
    editId = form.id;
  } else {
    offerte = offerte.map(o => o.id === editId ? { ...form } : o);
  }
  // Save server-side PRIMA della mail. Se il server rifiuta, niente notifica:
  // evita di mandare al supervisore una mail per un'offerta inesistente in DB.
  const saveRes = await fbSaveOfferta({ ...form });
  if (saveRes && saveRes.error) {
    form.scontoStato = statoBackup;
    if (offerte.length && offerte[offerte.length - 1].id === form.id && statoBackup === "") {
      offerte.pop(); editId = null;
    }
    toast("Salvataggio fallito: " + saveRes.error, "err", 8000);
    return;
  }
  formDirty = false;
  let mailOk = false;
  try {
    const c2 = calcAll(form, true);
    const r = await apiPost(API.notificaSconto, {
      offerta: form.nome, cliente: form.cliente || "", utente: currentUser.nome,
      totale: piVal, margine: c2.mP, tipo: "prezzo_imposto", nOrdine: form.nOrdineOdoo || "",
      offerta_id: form.id,
    });
    mailOk = r && r.ok === true;
  } catch (e) { console.error("Notifica email fallita:", e); }
  render();
  if (mailOk) toast("Richiesta prezzo imposto inviata ai supervisori", "success", 4000);
  else toast("Stato salvato, ma notifica email NON inviata. Contatta il supervisore manualmente.", "warn", 6000);
}

/* ============== EXPORT (singolo) ============== */

async function printPDF(){
  const btn = event.target;
  btn.textContent = "Generazione..."; btn.disabled = true;
  try {
    const blob = await apiPostBlob(API.generaPdf, { utente: currentUser.nome, form: stripForm() });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = offertaFileBaseName() + ".pdf";
    a.click();
  } catch (e) { toast("Errore: " + e.message, "error", 5000); }
  finally { btn.textContent = "PDF"; btn.disabled = false; }
}

async function exportExcel(){
  const btn = event.target;
  btn.textContent = "Generazione..."; btn.disabled = true;
  try {
    const blob = await apiPostBlob(API.generaExcel, { utente: currentUser.nome, form: stripForm() });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = offertaFileBaseName() + ".xlsx";
    a.click();
  } catch (e) { toast("Errore: " + e.message, "error", 5000); }
  finally { btn.textContent = "Excel"; btn.disabled = false; }
}

function stripForm(){
  return {
    nome: form.nome || "", cliente: form.cliente || "", tipo: form.tipo || "",
    data: form.data || "", note: form.note || "", nOrdineOdoo: form.nOrdineOdoo || "",
    speseGenerali: form.speseGenerali || "5",
    overmarkup: parseInt(form.overmarkup, 10) || 0,
    scontoStato: form.scontoStato || "", scontoTipo: form.scontoTipo || "pct", scontoValore: form.scontoValore || 0,
    prezzoImpostoAttivo: !!form.prezzoImpostoAttivo, prezzoImpostoValore: form.prezzoImpostoValore || 0,
    personale:    (form.personale || []).map(r => ({ categoria: r.categoria || "", oreG: r.oreG || 0, costoH: r.costoH || 0, markup: r.markup || 0 })),
    materiali:    (form.materiali || []).map(r => ({ desc: r.desc || "", qta: r.qta || 0, costoU: r.costoU || 0, markup: r.markup || 0 })),
    servizi:      (form.servizi || []).map(r => ({ desc: r.desc || "", qta: r.qta || 0, costoU: r.costoU || 0, markup: r.markup || 0 })),
    manutenzione: (form.manutenzione || []).map(r => ({ desc: r.desc || "", qta: r.qta || 0, costoU: r.costoU || 0, markup: r.markup || 0 })),
    trasferte:    (form.trasferte || []).map(r => ({ desc: r.desc || "", persone: r.persone || 0, giorni: r.giorni || 0, costoGiorno: r.costoGiorno || 0, vitto: r.vitto || 0, alloggio: r.alloggio || 0, km: r.km || 0, costoKm: r.costoKm || 0, markup: r.markup || 0 })),
  };
}

/* ============== EXPORT ARCHIVIO ============== */

function listaArchivio(){
  const isAdmin = currentUser.ruolo === "admin";
  const isSupervisore = currentUser.ruolo === "supervisore";
  let lista = [...offerte].reverse();
  if (!isAdmin && !isSupervisore) lista = lista.filter(o => o.userId === currentUser.id);
  else if (filterUser !== "all")  lista = lista.filter(o => o.userId === filterUser);
  return lista.map(o => {
    const c = calcAll(o); const ut = utenti.find(u => u.id === o.userId);
    return { nome: o.nome, cliente: o.cliente || "", data: o.data || "", nOrdineOdoo: o.nOrdineOdoo || "", utente: ut?.nome || "", tF: c.tFSconto, tC: c.tC, mE: c.mE, mP: c.mP };
  });
}

async function esportaArchivioPDF(){
  const lista = listaArchivio();
  if (lista.length === 0) { toast("Nessuna offerta corrispondente ai filtri", "warn", 3500); return; }
  const btn = event.target; btn.textContent = "..."; btn.disabled = true;
  try {
    const blob = await apiPostBlob(API.archivioPdf, { lista });
    const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = "archivio_offerte.pdf"; a.click();
  } catch (e) { toast("Errore: " + e.message, "error", 5000); }
  finally { btn.textContent = "PDF"; btn.disabled = false; }
}

async function esportaArchivioExcel(){
  const lista = listaArchivio();
  if (lista.length === 0) { toast("Nessuna offerta corrispondente ai filtri", "warn", 3500); return; }
  const btn = event.target; btn.textContent = "..."; btn.disabled = true;
  try {
    const blob = await apiPostBlob(API.archivioExcel, { lista });
    const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = "archivio_offerte.xlsx"; a.click();
  } catch (e) { toast("Errore: " + e.message, "error", 5000); }
  finally { btn.textContent = "Excel"; btn.disabled = false; }
}

/* ============== ODOO ============== */

let _odooSearchTimer = null;
// Cache locale autocomplete: chiave = query lowercase, valore = { ts, results }.
// TTL 30s evita richieste duplicate consecutive (es. utente cancella e ridigita lo stesso codice).
const _cercaOdooCache = new Map();
const _CERCA_ODOO_TTL_MS = 30000;
const _CERCA_ODOO_DEBOUNCE_MS = 250;

function aggiornaOdooField(val){
  val = (val || "").toUpperCase();
  const el = document.getElementById("f-nOrdineOdoo"); if (el) el.value = val;
  form.nOrdineOdoo = val; form.cliente = ""; form.nome = ""; form.tipo = "";
  ["f-cliente", "f-nome", "f-tipo"].forEach(id => { const e = document.getElementById(id); if (e) e.value = ""; });
}

// Debounce + cache: riduce ~80% delle chiamate /api/cerca_odoo.php durante autocomplete
// (vedi audit perf 2026-05-19: prima ogni keystroke -> 1 fetch; ora 1 fetch per pausa di typing >250ms,
// e risultati per la stessa query vengono riusati dalla cache locale per 30s).
function cercaSuOdoo(val){
  val = (val || "").trim();
  clearTimeout(_odooSearchTimer);
  if (val.length < 2) { rimuoviDropdown(); return; }
  _odooSearchTimer = setTimeout(async () => {
    const key = val.toLowerCase();
    const cached = _cercaOdooCache.get(key);
    if (cached && Date.now() - cached.ts < _CERCA_ODOO_TTL_MS) {
      if (cached.results.length) mostraDropdown(cached.results); else rimuoviDropdown();
      return;
    }
    try {
      const data = await apiPost(API.cercaOdoo, { query: val });
      const results = (data && data.ok && Array.isArray(data.risultati)) ? data.risultati : [];
      _cercaOdooCache.set(key, { ts: Date.now(), results });
      if (results.length) mostraDropdown(results); else rimuoviDropdown();
    } catch { rimuoviDropdown(); }
  }, _CERCA_ODOO_DEBOUNCE_MS);
}

function mostraDropdown(risultati){
  rimuoviDropdown();
  const input = document.getElementById("f-nOrdineOdoo"); if (!input) return;
  const wrap = input.parentElement; wrap.style.position = "relative";
  const dd = document.createElement("div"); dd.id = "odoo-dd"; dd.className = "odoo-dropdown";
  risultati.forEach(r => {
    const item = document.createElement("div"); item.className = "odoo-dropdown-item";
    item.innerHTML =
      '<span class="odoo-dd-code">' + esc(r.codice) + '</span>' +
      '<span class="odoo-dd-cliente">' + esc(r.cliente || "") + '</span>' +
      (r.nome ? '<span class="odoo-dd-nome">' + esc(r.nome) + '</span>' : '');
    item.addEventListener("mousedown", e => { e.preventDefault(); selezionaOdoo(r.codice); });
    dd.appendChild(item);
  });
  wrap.appendChild(dd);
}

function rimuoviDropdown(){
  const dd = document.getElementById("odoo-dd"); if (dd) dd.parentElement.removeChild(dd);
}

function selezionaOdoo(codice){
  const el = document.getElementById("f-nOrdineOdoo"); if (el) el.value = codice;
  form.nOrdineOdoo = codice; rimuoviDropdown(); caricaDaOdoo(codice);
}

async function gestisciParamOdoo(){
  const nOrdine = (window._pendingOdoo || "").trim().toUpperCase();
  window._pendingOdoo = "";
  if (!nOrdine) return;
  const esistente = offerte.find(o => (o.nOrdineOdoo || "").trim().toUpperCase() === nOrdine);
  if (esistente) {
    form = JSON.parse(JSON.stringify(esistente)); editId = esistente.id; formDirty = false; screen = "form"; render();
  } else {
    form = emptyForm(); form.nOrdineOdoo = nOrdine; editId = null; formDirty = false; screen = "form"; render();
    setTimeout(() => caricaDaOdoo(nOrdine), 200);
  }
  history.replaceState(null, "", window.location.pathname);
}

async function caricaDaOdoo(nOrdine){
  nOrdine = (nOrdine || "").trim().toUpperCase();
  if (!nOrdine) return;
  // Auto-prefisso "S0" se l'utente digita solo cifre.
  // Strip degli zeri iniziali per gestire 3900 / 03900 / 003900 -> tutti "S03900".
  if (/^\d+$/.test(nOrdine)) {
    const stripped = nOrdine.replace(/^0+/, "") || "0";
    nOrdine = "S0" + stripped;
    const inp = document.getElementById("f-nOrdineOdoo");
    if (inp) inp.value = nOrdine;
    form.nOrdineOdoo = nOrdine;
  }
  window._odooRequestCode = nOrdine;
  document.body.classList.add("odoo-loading");
  try {
    const data = await apiPost(API.leggiOdoo, { nOrdine });
    if (window._odooRequestCode !== nOrdine) return;
    if (data.error) {
      if (data.error === "Ordine non trovato") {
        toast("Ordine non trovato", "warn", 3500);
      } else if (data.error.indexOf("Non autorizzato") === 0) {
        toast("Non sei autorizzato a vedere questo ordine", "error", 4500);
      } else {
        toast("Errore Odoo: " + data.error, "error", 4000);
      }
      return;
    }
    if (!data.ok) return;
    if (data.cliente) { form.cliente = data.cliente; const el = document.getElementById("f-cliente"); if (el) el.value = data.cliente; }
    if (data.tipo)    { form.tipo    = data.tipo;    const el = document.getElementById("f-tipo");    if (el) el.value = data.tipo; }
    if (data.nome)    { form.nome    = data.nome;    const el = document.getElementById("f-nome");    if (el) el.value = data.nome; }
  } catch (e) { console.error("Errore Odoo:", e); }
  finally {
    document.body.classList.remove("odoo-loading");
  }
}

async function allegaAOdoo(){
  const nOrdine = (form.nOrdineOdoo || "").trim();
  if (!nOrdine) { toast("Inserisci il Numero Prev/Ordine Odoo prima di allegare.", "warn"); return; }
  const btn = document.getElementById("btn-odoo");
  if (btn) { btn.textContent = "Invio..."; btn.disabled = true; }
  document.body.classList.add("odoo-loading");
  try {
    const data = await apiPost(API.allegaOdoo,
      { nOrdine, utente: currentUser.nome, form: stripForm() },
      { timeoutMs: 120000 }
    );
    if (data.error) throw new Error(data.error);

    // Marca offerta come allegata: aggiorna stato locale e ridisegna pipeline
    const ts = data.allegataAt || new Date().toISOString().replace('T', ' ').slice(0, 19);
    form.allegataOdoo = ts;
    if (form.id) {
      offerte = offerte.map(o => o.id === form.id ? { ...o, allegataOdoo: ts } : o);
    }
    render();

    if (btn) { btn.textContent = "Allegati caricati!"; btn.style.background = "#16a34a"; }
    toast("PDF + Excel allegati a Odoo (" + (data.ordineNome || nOrdine) + ")", "success", 4000);
    setTimeout(() => { if (btn) { btn.textContent = "Allega a Odoo"; btn.style.background = ""; btn.disabled = false; } }, 3000);
  } catch (e) {
    if (e.name === "AbortError") toast("Timeout — operazione troppo lenta. Riprova.", "error", 5000);
    else toast("Errore: " + e.message, "error", 5000);
    console.error(e);
    if (btn) { btn.textContent = "Allega a Odoo"; btn.disabled = false; }
  } finally {
    document.body.classList.remove("odoo-loading");
  }
}

/* ============== BOOT ============== */

initApp();

function mostraBpUpdateBanner(){
  if (document.getElementById('bp-update-banner')) return;
  const b = document.createElement('div');
  b.id = 'bp-update-banner';
  b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:10001;background:#1e3a8a;color:white;padding:10px 20px;display:flex;align-items:center;justify-content:center;gap:18px;font-family:Lato,sans-serif;font-size:.92rem;box-shadow:0 2px 8px rgba(0,0,0,.2)';
  b.innerHTML = '<i class="fas fa-sync-alt" style="font-size:1.05rem"></i><span>Nuova versione di BP Template disponibile.</span><button onclick="location.reload()" style="background:white;color:#1e3a8a;border:none;padding:6px 18px;border-radius:5px;font-weight:bold;cursor:pointer;font-family:inherit;font-size:.85rem">Ricarica ora</button>';
  document.body.appendChild(b);
}

// Version check robusto: polling di version.json, indipendente dal SW lifecycle.
// Trigger su intervallo + focus + visibilitychange per scoperta rapida.
let _bpBaselineVer = null;
async function _bpCheckVersion(){
  if (document.getElementById('bp-update-banner')) return;
  try {
    const r = await fetch('version.json?_t=' + Date.now(), { cache: 'no-store' });
    if (!r.ok) return;
    const data = await r.json();
    if (!data || !data.v) return;
    if (_bpBaselineVer === null) { _bpBaselineVer = data.v; return; }
    if (data.v !== _bpBaselineVer) mostraBpUpdateBanner();
  } catch (_) {}
}
_bpCheckVersion();
setInterval(_bpCheckVersion, 60000);
window.addEventListener('focus', _bpCheckVersion);
document.addEventListener('visibilitychange', () => { if (!document.hidden) _bpCheckVersion(); });

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js', { updateViaCache: 'none' })
      .then(reg => {
        reg.update();
        setInterval(() => { reg.update().catch(()=>{}); }, 300000);
        reg.addEventListener('updatefound', () => {
          const nw = reg.installing;
          if (!nw) return;
          nw.addEventListener('statechange', () => {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              mostraBpUpdateBanner();
            }
          });
        });
      })
      .catch(e => console.log('SW errore:', e));
  });
}
