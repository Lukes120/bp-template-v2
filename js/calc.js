// Calcoli costi/ricavi/margini.
// SOURCE OF TRUTH duplicato: questo file DEVE rimanere allineato con core/calcoli.php (bp_calc_all).

// Soglie semaforo margine % (semantica colore):
//   >= GREEN  -> verde
//   >= YELLOW -> giallo
//   altrimenti -> rosso
// Usate in mc(), mpClass() (views.js), bp_mail_color_margine (PHP), pdf.php, excel.php.
// In PHP la copia di queste soglie sta in core/calcoli.php (BP_MARGINE_GREEN, BP_MARGINE_YELLOW).
const MARGINE_THRESHOLDS = { green: 20, yellow: 10 };

// Soglie minime di margine % per ruolo: sotto questi valori il salvataggio è bloccato
// e l'utente è invitato a chiedere uno sconto motivato (che richiede approvazione).
// NB: distinte dalle soglie semaforo sopra.
const MARGINE_MIN_RUOLO = {
  user:        15,
  viewer:      15, // viewer ha gli stessi vincoli di user (in pratica viewer non crea offerte)
  supervisore: 5,
  admin:       0,
};

function uid(){ return Date.now().toString(36) + Math.random().toString(36).slice(2); }
function pf(v){ return parseFloat(v) || 0; }
function fmt(n){ return isNaN(n) || n === "" ? "0,00" : Number(n).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtPct(n){ return isNaN(n) || n === "" ? "0,0" : Number(n).toLocaleString("it-IT", { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }
function mc(p){
  if (p >= MARGINE_THRESHOLDS.green)  return "green-txt";
  if (p >= MARGINE_THRESHOLDS.yellow) return "yellow-txt";
  return "red-txt";
}

function calcAll(f, previewPI = false){
  const cp = f.personale.map(r => { const b = pf(r.oreG) * pf(r.costoH); return { ...r, b, pv: b * (1 + pf(r.markup) / 100) }; });
  const cm = f.materiali.map(r => { const b = pf(r.qta) * pf(r.costoU); return { ...r, b, pv: b * (1 + pf(r.markup) / 100) }; });
  const cs = f.servizi.map(r => { const b = pf(r.qta) * pf(r.costoU); return { ...r, b, pv: b * (1 + pf(r.markup) / 100) }; });
  const cm2 = f.manutenzione.map(r => { const b = pf(r.qta) * pf(r.costoU); return { ...r, b, pv: b * (1 + pf(r.markup) / 100) }; });
  const ct = f.trasferte.map(r => {
    const p = pf(r.persone), g = pf(r.giorni);
    const b = pf(r.costoGiorno) * p * g + pf(r.vitto) * p * g + pf(r.alloggio) * p * g + pf(r.km) * pf(r.costoKm);
    return { ...r, b, pv: b * (1 + pf(r.markup) / 100) };
  });
  const s = (a, k) => a.reduce((t, r) => t + r[k], 0);
  const tCP = s(cp, 'b'), tVP = s(cp, 'pv');
  const tCM = s(cm, 'b'), tVM = s(cm, 'pv');
  const tCS = s(cs, 'b'), tVS = s(cs, 'pv');
  const tCM2 = s(cm2, 'b'), tVM2 = s(cm2, 'pv');
  const tCT = s(ct, 'b'), tVT = s(ct, 'pv');
  const tC = tCP + tCM + tCS + tCM2 + tCT;
  const tVL = tVP + tVM + tVS + tVM2 + tVT;
  const sg = tC * (pf(f.speseGenerali) / 100);
  // Overmarkup (0..30, step 5): maggiorazione sui ricavi che NON tocca le spese generali (sg resta su tC).
  // tVL_finale = tVL * (1 + overmarkup/100). Vedi anche bp_calc_all in core/calcoli.php.
  const overmarkup = pf(f.overmarkup) || 0;
  const overmarkupValore = tVL * (overmarkup / 100);
  const tVLfinale = tVL + overmarkupValore;
  const tF = tVLfinale + sg;
  const scontoApp = f.scontoStato === "approvato" ? pf(f.scontoValore) : 0;
  let scontoE = f.scontoTipo === "pct" ? tF * (scontoApp / 100) : scontoApp;
  // Totale "calcolato": tF al netto dell'eventuale sconto direzione approvato (ignora prezzo imposto).
  const tFCalc = tF - scontoE;
  const mECalc = tFCalc - tC;
  const mPCalc = tFCalc > 0 ? (mECalc / tFCalc) * 100 : 0;
  // Modalità "prezzo imposto": se attiva e approvata, sostituisce il totale post-sconto come prezzo praticato.
  // Lo sconto direzione e il prezzo imposto sono mutualmente esclusivi in UI.
  // previewPI=true: anteprima nel form mentre l'utente digita il PI (non aspetta l'approvazione).
  // Default false → comportamento originale: PI riflesso nel margine solo se approvato.
  const prezzoImpostoOk = !!f.prezzoImpostoAttivo && (previewPI || f.scontoStato === "approvato");
  let tFSconto = prezzoImpostoOk ? pf(f.prezzoImpostoValore) : tFCalc;
  if (prezzoImpostoOk) { scontoE = 0; }
  const mE = tFSconto - tC;
  const mP = tFSconto > 0 ? (mE / tFSconto) * 100 : 0;
  return { cp, cm, cs, cm2, ct, tCP, tVP, tCM, tVM, tCS, tVS, tCM2, tVM2, tCT, tVT, tC, tVL, tVLfinale, overmarkup, overmarkupValore, sg, tF, tFCalc, mECalc, mPCalc, tFSconto, scontoE, mE, mP, prezzoImpostoOk };
}

function emptyForm(){
  return {
    id: uid(), nome: "", cliente: "", tipo: "", nOrdineOdoo: "",
    data: new Date().toISOString().slice(0, 10), note: "", speseGenerali: "5", overmarkup: 0,
    personale: [
      { id: uid(), categoria: Object.keys(CATEGORIE)[5], oreG: "", costoH: CATEGORIE[Object.keys(CATEGORIE)[5]], markup: "35" },
      { id: uid(), categoria: Object.keys(CATEGORIE)[0], oreG: "", costoH: CATEGORIE[Object.keys(CATEGORIE)[0]], markup: "35" },
    ],
    materiali:    [{ id: uid(), desc: "", qta: "", costoU: "", markup: "25" }],
    servizi:      [{ id: uid(), desc: "", qta: "", costoU: "", markup: "20" }],
    manutenzione: [{ id: uid(), desc: "", qta: "", costoU: "", markup: "25" }],
    trasferte:    [{ id: uid(), desc: "", persone: "", giorni: "", costoGiorno: "", vitto: "", alloggio: "", km: "", costoKm: "0.30", markup: "10" }],
  };
}
