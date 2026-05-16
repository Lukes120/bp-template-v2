// Wrapper fetch verso /api/*.php.
// L'auth è basata su cookie HTTP-only `bp_session` (settato da api/login.php).
// Tutti i fetch usano credentials:'include' per inviare il cookie.

const API = {
  utenti:           'api/utenti.php',
  offerte:          'api/offerte.php',
  login:            'api/login.php',
  logout:           'api/logout.php',
  cercaOdoo:        'api/cerca_odoo.php',
  leggiOdoo:        'api/leggi_odoo.php',
  allegaOdoo:       'api/allega_odoo.php',
  generaPdf:        'api/genera_pdf.php',
  generaExcel:      'api/genera_excel.php',
  archivioPdf:      'api/archivio_pdf.php',
  archivioExcel:    'api/archivio_excel.php',
  notificaSconto:   'api/notifica_sconto.php',
  notificaEsito:    'api/notifica_esito.php',
  reinviaMail:      'api/reinvia_mail.php',
  audit:            'api/audit.php',
};

const FETCH_OPTS = { credentials: 'same-origin' };

// CSRF double-submit: leggi il cookie 'bp_csrf' settato al login e iniettalo nell'header X-Bp-Csrf
function _csrfToken(){
  const m = document.cookie.match(/(?:^|; )bp_csrf=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
}
function _mutatingHeaders(extra){
  return Object.assign({ 'X-Bp-Csrf': _csrfToken() }, extra || {});
}

async function _handle(res){
  if (res.status === 401) {
    // sessione scaduta o utente sloggato lato server
    if (typeof currentUser !== 'undefined' && currentUser) {
      currentUser = null; screen = 'login';
      if (typeof toast === 'function') toast('Sessione scaduta — accedi di nuovo', 'warn');
      if (typeof render === 'function') render();
    }
    throw new Error('unauthorized');
  }
  return res;
}

async function apiGet(url){
  const res = await _handle(await fetch(url, FETCH_OPTS));
  return res.json();
}

async function apiPost(url, body){
  const res = await _handle(await fetch(url, {
    ...FETCH_OPTS,
    method: 'POST',
    headers: _mutatingHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(body),
  }));
  return res.json();
}

async function apiDelete(url){
  const res = await _handle(await fetch(url, { ...FETCH_OPTS, method: 'DELETE', headers: _mutatingHeaders() }));
  return res.json();
}

async function apiPostBlob(url, body){
  const res = await _handle(await fetch(url, {
    ...FETCH_OPTS,
    method: 'POST',
    headers: _mutatingHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(body),
  }));
  return res.blob();
}

// Helpers di alto livello
const fbSaveUtente   = u  => apiPost(API.utenti, u);
const fbDelUtente    = id => apiDelete(API.utenti + '?id=' + encodeURIComponent(id));
const fbSaveOfferta  = o  => apiPost(API.offerte, o);
const fbDelOfferta   = id => apiDelete(API.offerte + '?id=' + encodeURIComponent(id));
