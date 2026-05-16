// Utility globali: toast, formattazione date IT, helper modal.

/* ============== ESCAPE HTML (anti-XSS) ============== */
// Usare SEMPRE su dati utente (form.nome, o.cliente, form.note, ecc.) prima
// di iniettarli in template literal con innerHTML.
const _ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => _ESC_MAP[c]); }
// esc per attributi HTML (senza permettere apici): identico a esc(), funziona ovunque
const escAttr = esc;

/* ============== TOAST ============== */

function toast(msg, type = "info", duration = 3000){
  let host = document.getElementById("bp-toast-host");
  if (!host) {
    host = document.createElement("div");
    host.id = "bp-toast-host";
    host.style.cssText = "position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10000;display:flex;flex-direction:column;gap:10px;pointer-events:none;align-items:center";
    document.body.appendChild(host);
  }
  // Stile Odoo Enterprise: card bianca, border-left colorato, icona FA del colore di stato.
  // Colori da palette Odoo (--o-success/--o-warning/--o-danger/--o-info).
  const styles = {
    info:    { color: "#17a2b8", icon: "fa-info-circle" },
    success: { color: "#28a745", icon: "fa-check-circle" },
    error:   { color: "#dc3545", icon: "fa-times-circle" },
    warn:    { color: "#f0ad4e", icon: "fa-exclamation-triangle" },
  };
  const s = styles[type] || styles.info;
  const t = document.createElement("div");
  t.style.cssText = `pointer-events:none;
    background:#fff;color:#212529;
    border:1px solid #dee2e6;border-left:5px solid ${s.color};
    padding:16px 24px 16px 20px;border-radius:6px;
    font-family:'Lato',-apple-system,BlinkMacSystemFont,Arial,sans-serif;
    font-size:1rem;font-weight:500;line-height:1.35;
    box-shadow:0 4px 16px rgba(0,0,0,.10);
    min-width:300px;max-width:520px;
    display:flex;align-items:center;gap:14px;
    transform:scale(.94);opacity:0;transition:all .22s ease-out`;
  const icon = document.createElement("i");
  icon.className = "fas " + s.icon;
  icon.style.cssText = "color:" + s.color + ";font-size:1.5rem;flex-shrink:0";
  const span = document.createElement("span");
  span.textContent = msg;
  t.appendChild(icon);
  t.appendChild(span);
  host.appendChild(t);
  requestAnimationFrame(() => { t.style.transform = "scale(1)"; t.style.opacity = "1"; });
  setTimeout(() => {
    t.style.transform = "scale(.94)"; t.style.opacity = "0";
    setTimeout(() => t.remove(), 250);
  }, duration);
}

/* ============== FILE NAME OFFERTA ============== */
// Schema: BP_<nOrdineOdoo>_<data>. Fallback nOrdineOdoo→BOZZA, data→oggi.
// Sanitizza caratteri non validi per filename (Windows + Unix).
function offertaFileBaseName(){
  const n = (typeof form !== 'undefined' && form && (form.nOrdineOdoo || '').trim()) || 'BOZZA';
  const d = (typeof form !== 'undefined' && form && form.data) || new Date().toISOString().slice(0, 10);
  return ('BP_' + n + '_' + d).replace(/[\/\\:*?"<>|\s]+/g, '_');
}

/* ============== DATE IT ============== */

function fmtDataIT(s){
  if (!s) return "";
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s;
  return m[3] + "/" + m[2] + "/" + m[1];
}

function fmtDataTimeIT(s){
  if (!s) return "";
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[T ]?(\d{2}):(\d{2})/);
  if (!m) return fmtDataIT(s);
  return m[3] + "/" + m[2] + "/" + m[1] + " " + m[4] + ":" + m[5];
}

/* ============== MODAL ============== */

function openModal(html, opts = {}){
  const back = document.createElement("div");
  back.id = "bp-modal-back";
  back.style.cssText = "position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px";
  const card = document.createElement("div");
  card.style.cssText = `background:white;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.2);
    width:100%;max-width:${opts.maxWidth || '480px'};max-height:90vh;overflow:auto`;
  card.innerHTML = html;
  back.appendChild(card);
  back.addEventListener("click", e => { if (e.target === back && opts.dismissOnBack !== false) closeModal(); });
  document.body.appendChild(back);
  return card;
}

function closeModal(){
  const back = document.getElementById("bp-modal-back");
  if (back) back.remove();
}
