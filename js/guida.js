// Guida utente in-app: 5 sezioni con sommario laterale sticky.
// Le immagini sono caricate da guida/img/* — se mancano si vede un placeholder.

function renderGuida(){
  const isAdminOrSup = currentUser && (currentUser.ruolo === 'admin' || currentUser.ruolo === 'supervisore');

  const img = (file, alt) =>
    '<div class="guida-img-wrap">' +
      '<img src="guida/img/' + file + '" alt="' + alt + '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">' +
      '<div class="guida-img-placeholder">' +
        '<i class="fas fa-image"></i>' +
        '<div class="guida-img-placeholder-title">' + alt + '</div>' +
        '<div class="guida-img-placeholder-hint">Aggiungi lo screenshot in <code>guida/img/' + file + '</code></div>' +
      '</div>' +
    '</div>';

  // Variante con marker numerati sopra l'immagine + legenda sotto.
  // markers = [{x:0-100, y:0-100, label:'spiegazione'}], coordinate in % dell'immagine.
  const imgAnno = (file, alt, markers) => {
    const dots = markers.map((m, i) =>
      '<span class="guida-marker" style="left:' + m.x + '%;top:' + m.y + '%">' + (i + 1) + '</span>'
    ).join('');
    const legend = markers.map((m, i) =>
      '<li><span class="guida-marker-mini">' + (i + 1) + '</span> ' + m.label + '</li>'
    ).join('');
    return '<div class="guida-img-wrap guida-img-anno">' +
      '<img src="guida/img/' + file + '" alt="' + alt + '" onerror="this.parentElement.querySelector(\'.guida-img-placeholder\').style.display=\'flex\';this.style.display=\'none\'">' +
      dots +
      '<div class="guida-img-placeholder">' +
        '<i class="fas fa-image"></i>' +
        '<div class="guida-img-placeholder-title">' + alt + '</div>' +
        '<div class="guida-img-placeholder-hint">Aggiungi lo screenshot in <code>guida/img/' + file + '</code></div>' +
      '</div>' +
    '</div>' +
    '<ol class="guida-marker-legend">' + legend + '</ol>';
  };

  const sommario =
    '<aside class="guida-toc">' +
      '<div class="guida-toc-title">Indice</div>' +
      '<a href="#sez-login">1. Accesso</a>' +
      '<a href="#sez-dashboard">2. Dashboard offerte</a>' +
      '<a href="#sez-form">3. Creare un\'offerta</a>' +
      '<a href="#sez-sconto">4. Sconto direzione</a>' +
      '<a href="#sez-prezzoimposto" class="guida-toc-sub">4c. Prezzo Imposto</a>' +
      '<a href="#sez-odoo">5. Allega a Odoo</a>' +
      '<div class="guida-toc-sep"></div>' +
      '<button class="btn btn-purple guida-print-btn" onclick="window.print()"><i class="fas fa-print"></i> Stampa / Esporta PDF</button>' +
    '</aside>';

  const sezLogin =
    '<section id="sez-login" class="guida-section">' +
      '<h2>1. Accesso all\'app</h2>' +
      '<p class="guida-lead">BP Template usa le <strong>stesse credenziali di Odoo</strong> (single sign-on). Non serve creare account separati.</p>' +
      imgAnno('1-login.png', 'Schermata di login', [
        { x: 50, y: 47, label: 'Inserisci email Odoo (es. cognome@ecotelitalia.it)' },
        { x: 50, y: 60, label: 'Password Odoo' },
        { x: 50, y: 73, label: 'Pulsante "Accedi" — la sessione resta aperta 7 giorni' },
      ]) +
      '<h3>Come accedere</h3>' +
      '<ol>' +
        '<li>Apri l\'app dal browser oppure cliccando lo smart button <strong>"BP Template"</strong> sulle Vendite di Odoo.</li>' +
        '<li>Nel campo <strong>Username</strong> inserisci la tua email Odoo (es. <code>cognome@ecotelitalia.it</code>).</li>' +
        '<li>Nella <strong>Password</strong> usa quella di Odoo. La sessione resta aperta per 7 giorni.</li>' +
      '</ol>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-info-circle"></i>' +
        '<div><strong>Primo accesso?</strong> L\'app riconosce la tua utenza Odoo e crea il profilo locale al volo, con ruolo <code>user</code>. Non serve che l\'amministratore ti registri prima.</div>' +
      '</div>' +
      '<h3>Ruoli</h3>' +
      '<ul>' +
        '<li><strong>user</strong> — vede solo le proprie offerte, le crea, modifica, elimina.</li>' +
        '<li><strong>supervisore</strong> — vede tutte le offerte, può approvare/rifiutare richieste sconto.</li>' +
        '<li><strong>admin</strong> — gestisce utenti, vede l\'audit log, può eliminare offerte di chiunque.</li>' +
      '</ul>' +
      '<h3>Cambio password al primo accesso</h3>' +
      '<p>Se l\'admin ti crea l\'utenza manualmente (caso raro), al primo login ti viene chiesto di cambiare la password. Se invece accedi via SSO Odoo, questo passaggio è saltato.</p>' +
    '</section>';

  const sezDashboard =
    '<section id="sez-dashboard" class="guida-section">' +
      '<h2>2. Dashboard offerte</h2>' +
      '<p class="guida-lead">Dopo il login vedi l\'elenco delle offerte. Gli utenti <code>user</code> vedono solo le proprie. Supervisori e admin vedono tutto.</p>' +
      imgAnno('2-dashboard.png', 'Dashboard offerte con KPI e tabella', [
        { x: 50, y: 14, label: 'KPI: totale offerte, ricavi, margine totale, margine medio %' },
        { x: 16, y: 23, label: 'Filtro per utente (admin/supervisore) e barra di ricerca' },
        { x: 21, y: 31, label: 'Click "Modifica" o "Riepilogo" su una riga per aprirla' },
        { x: 50, y: 49, label: 'Riga TOTALI sempre visibile in fondo, si aggiorna coi filtri' },
      ]) +
      '<h3>KPI in cima</h3>' +
      '<p>Quattro indicatori riassuntivi della tua selezione corrente:</p>' +
      '<ul>' +
        '<li><strong>Totale Offerte</strong> — conteggio</li>' +
        '<li><strong>Ricavi Totali</strong> — somma dei prezzi vendita</li>' +
        '<li><strong>Margine Totale</strong> — somma dei margini in euro</li>' +
        '<li><strong>Margine Medio %</strong> — media ponderata sui ricavi</li>' +
      '</ul>' +
      '<h3>Filtri</h3>' +
      '<ul>' +
        '<li><strong>Cerca</strong>: scrive in commessa, cliente, N. Odoo</li>' +
        (isAdminOrSup ? '<li><strong>Filtro per utente</strong> (solo admin/supervisore): mostra le offerte di un commerciale specifico</li>' : '') +
      '</ul>' +
      '<h3>Riga TOTALI</h3>' +
      '<p>In fondo alla tabella, banda grigio-blu con la sintesi: ricavi in bianco, costi in rosso chiaro, margine in verde. Cambia in tempo reale con i filtri.</p>' +
      '<h3>Esportazione archivio</h3>' +
      '<p>Pulsanti <strong>"PDF"</strong> e <strong>"Excel"</strong> in cima esportano l\'elenco filtrato:</p>' +
      '<ul>' +
        '<li><strong>PDF</strong>: pagina con KPI in alto + tabella + riga totali</li>' +
        '<li><strong>Excel</strong>: foglio con freeze pane, frecce filtro Excel native, semaforo automatico sul margine % (verde ≥20%, giallo 10-19%, rosso &lt;10%)</li>' +
      '</ul>' +
      '<h3>Azioni per riga</h3>' +
      '<ul>' +
        '<li><strong>Modifica</strong> — apre l\'offerta in modifica</li>' +
        '<li><strong>Riepilogo</strong> — vista di sola lettura con grafico margini</li>' +
        '<li><strong>Duplica</strong> (solo admin) — crea una copia da modificare</li>' +
        '<li><strong>Elimina</strong> — solo per la propria offerta o per admin</li>' +
      '</ul>' +
    '</section>';

  const sezForm =
    '<section id="sez-form" class="guida-section">' +
      '<h2>3. Creare o modificare un\'offerta</h2>' +
      '<p class="guida-lead">Click su <strong>"Nuovo"</strong> in alto a destra, oppure su <strong>"Modifica"</strong> da una riga esistente.</p>' +
      imgAnno('3-form.png', 'Form offerta con dati commessa e sezioni costi', [
        { x: 24, y: 7, label: 'Pulsanti SALVA / SCARTA / RIEPILOGO sempre in cima' },
        { x: 50, y: 37, label: 'N. Prev/Ordine Odoo — premi Tab e i campi Cliente, Nome e Tipo si popolano automaticamente' },
        { x: 30, y: 60, label: 'Inizio delle 5 sezioni di costo: Manodopera, Materiali, Servizi, Manutenzione, Trasferte' },
      ]) +
      '<h3>Aggancio a Odoo</h3>' +
      '<p>Il campo <strong>"N. Prev/Ordine Odoo"</strong> è la chiave per l\'integrazione:</p>' +
      '<ul>' +
        '<li>Scrivi il codice (es. <code>S04134</code>) e premi <strong>Tab</strong> o clicca fuori dal campo</li>' +
        '<li>L\'app interroga Odoo e popola automaticamente <strong>Cliente</strong>, <strong>Nome commessa</strong>, <strong>Tipo</strong> (i campi appaiono con tag <code>AUTO</code>)</li>' +
        '<li>Se cambi il codice, i 3 campi vengono svuotati e ripopolati col nuovo</li>' +
      '</ul>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-lightbulb"></i>' +
        '<div><strong>Scorciatoia</strong>: dallo smart button "BP Template" in Odoo, l\'app si apre già con il codice precompilato e i campi popolati.</div>' +
      '</div>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-magic"></i>' +
        '<div><strong>Auto-prefisso "S0"</strong>: se digiti solo cifre (es. <code>3900</code>), l\'app aggiunge automaticamente il prefisso e cerca <code>S03900</code>. Funziona anche con zeri iniziali (<code>03900</code>, <code>003900</code> → <code>S03900</code>).</div>' +
      '</div>' +
      '<div class="guida-callout guida-warn">' +
        '<i class="fas fa-user-shield"></i>' +
        '<div><strong>Vedi solo i tuoi ordini</strong>: gli utenti con ruolo <code>user</code> possono caricare solo i sale.order Odoo dove sono indicati come <strong>Addetto Vendite</strong>. Se digiti il numero di un ordine non tuo, ricevi il messaggio <em>"Non sei autorizzato a vedere questo ordine"</em>. Supervisori e admin vedono tutto.</div>' +
      '</div>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-bell"></i>' +
        '<div><strong>Messaggi a video</strong>: durante l\'interrogazione di Odoo, l\'app mostra al centro dello schermo un messaggio in caso di errore — <em>"Ordine non trovato"</em>, <em>"Non sei autorizzato"</em>, oppure dettaglio dell\'errore tecnico. I messaggi spariscono da soli dopo qualche secondo.</div>' +
      '</div>' +
      '<h3>Le 5 sezioni di costo</h3>' +
      '<ol>' +
        '<li><strong>Manodopera</strong> — categoria CCNL (B1/C3/D2…), ore/uomo, costo orario auto-calcolato dalla tabella CCNL</li>' +
        '<li><strong>Materiali</strong> — descrizione, quantità, costo unitario</li>' +
        '<li><strong>Servizi e Subappalti</strong> — stessa struttura dei materiali</li>' +
        '<li><strong>Manutenzione</strong> — stessa struttura dei materiali</li>' +
        '<li><strong>Trasferte</strong> — persone, giorni, costo giornata, vitto, alloggio, km, EUR/km</li>' +
      '</ol>' +
      '<p>Per ogni riga il <strong>markup %</strong> determina il Prezzo Vendita e di conseguenza il Margine, calcolati in tempo reale. Le righe vuote (tutte a zero) vengono saltate da PDF e Excel.</p>' +
      '<div class="guida-callout guida-warn">' +
        '<i class="fas fa-lock"></i>' +
        '<div><strong>Markup non modificabile per il commerciale</strong>: gli utenti con ruolo <code>user</code> vedono il campo <strong>Markup %</strong> in grigio (readonly). I default applicati sono: <strong>Manodopera 35%</strong>, <strong>Materiali 25%</strong>, <strong>Servizi 20%</strong>, <strong>Manutenzione 25%</strong>, <strong>Trasferte 10%</strong>. Solo <strong>supervisore</strong> e <strong>admin</strong> possono modificarli.</div>' +
      '</div>' +
      '<h3>Pulsante "Aggiungi riga"</h3>' +
      '<p>Sotto ogni tabella. La riga rossa <code>x</code> a destra elimina.</p>' +
      imgAnno('3b-calcolo.png', 'Tabella manodopera con calcoli live', [
        { x: 23, y: 22, label: 'Categoria CCNL: il costo orario si compila automaticamente' },
        { x: 67, y: 22, label: 'Markup % — la percentuale che imposti tu' },
        { x: 79, y: 22, label: 'Prezzo Vendita e Margine calcolati in tempo reale' },
        { x: 18, y: 32, label: 'Riga TOTALE di sezione, ben visibile per controllo a colpo d\'occhio' },
      ]) +
      '<h3>Bottom bar</h3>' +
      '<p>Sempre visibile in basso: <strong>totale offerta</strong> e <strong>margine %</strong> aggiornati a ogni cifra. Verde ≥20%, giallo 10-19%, rosso &lt;10%.</p>' +
      '<h3>Salvataggio</h3>' +
      '<p>Pulsante <strong>"Salva"</strong> in cima. Validazioni:</p>' +
      '<ul>' +
        '<li>Nome commessa obbligatorio</li>' +
        '<li>N. Prev/Ordine Odoo obbligatorio</li>' +
        '<li>Margine sotto la soglia minima del tuo ruolo: blocca il salvataggio (devi rivedere costi o chiedere sconto direzione)</li>' +
      '</ul>' +
      '<h3>Riepilogo</h3>' +
      '<p>Pulsante <strong>"Riepilogo"</strong> in cima al form apre una vista di sintesi a sola lettura con:</p>' +
      '<ul>' +
        '<li>Tabella per sezione (Manodopera / Materiali / Servizi / Manutenzione / Trasferte) con costo, prezzo vendita, margine e margine %</li>' +
        '<li>Riga finale <strong>TOTALE CALCOLATO</strong> e — se attivo — riga <strong>PREZZO IMPOSTO</strong> in viola</li>' +
        '<li>4 KPI: Costo, Prezzo Cliente/Imposto, Margine EUR, Margine %</li>' +
        '<li>Banner stato Odoo + bottoni <strong>MODIFICA · PDF · Excel · Allega a Odoo</strong></li>' +
      '</ul>' +
    '</section>';

  const sezSconto =
    '<section id="sez-sconto" class="guida-section">' +
      '<h2>4. Sconto direzione</h2>' +
      '<p class="guida-lead">Se il margine è sotto la soglia ammessa per il tuo ruolo, devi chiedere uno sconto al supervisore prima di chiudere l\'offerta.</p>' +
      imgAnno('4-sconto.png', 'Sezione sconto direzione + spese generali + totale finale', [
        { x: 27, y: 70, label: 'Sezione SCONTO DIREZIONE: tipo (% o EUR), valore e nota' },
        { x: 50, y: 76, label: 'APPROVA / RIFIUTA: solo per supervisori e admin' },
        { x: 27, y: 85, label: 'Spese Generali: percentuale di mark-up sull\'intera offerta' },
        { x: 88, y: 96, label: 'Pulsante SALVA OFFERTA conferma le modifiche' },
      ]) +
      '<h3>Stati di un\'offerta</h3>' +
      '<ul>' +
        '<li><strong>Bozza</strong> (default) — work-in-progress, modificabile</li>' +
        '<li><strong>In attesa</strong> — hai chiesto lo sconto, supervisori notificati via email</li>' +
        '<li><strong>Approvato</strong> — sconto applicato, offerta pronta</li>' +
        '<li><strong>Rifiutato</strong> — supervisore ha negato, devi rivedere</li>' +
      '</ul>' +
      '<h3>Come chiedere lo sconto (commerciale)</h3>' +
      '<ol>' +
        '<li>Compila l\'offerta normalmente</li>' +
        '<li>Nella sezione "Sconto Direzione" scegli tipo (% o EUR), valore e nota</li>' +
        '<li>Click su <strong>"Chiedi sconto"</strong></li>' +
        '<li>I supervisori ricevono una mail; lo stato dell\'offerta diventa "in attesa"</li>' +
      '</ol>' +
      '<h3>Come approvare/rifiutare (supervisore/admin)</h3>' +
      '<p>In topnav compare la voce <strong>"Da approvare"</strong> con il numero di richieste pendenti.</p>' +
      imgAnno('4b-approvazioni.png', 'Vista approvazioni per supervisore/admin', [
        { x: 22, y: 3, label: '"Da approvare" appare in topnav con un badge se ci sono richieste pendenti' },
        { x: 50, y: 22, label: 'Lista delle offerte in attesa di sconto: click per aprirla e decidere' },
      ]) +
      '<ul>' +
        '<li><strong>Approva</strong>: applica lo sconto e sblocca l\'offerta</li>' +
        '<li><strong>Rifiuta</strong>: invita il commerciale a rivedere (opzionalmente con nota motivazionale)</li>' +
      '</ul>' +
    '</section>';

  const sezPrezzoImposto =
    '<section id="sez-prezzoimposto" class="guida-section">' +
      '<h2>4c. Prezzo Imposto</h2>' +
      '<p class="guida-lead">Il <strong>Prezzo Imposto</strong> è un\'alternativa allo Sconto Direzione: invece di applicare uno sconto percentuale o in euro al totale calcolato, fissi direttamente il <strong>prezzo finale concordato</strong> con il cliente. L\'app calcola il margine residuo e — se necessario — chiede l\'approvazione al supervisore.</p>' +
      imgAnno('4c-prezzo-imposto.png', 'Form con barra Attiva Prezzo Imposto sopra Dati Commessa', [
        { x: 25, y: 5, label: 'Barra Prezzo Imposto attiva: pillola con il valore + badge stato (Approvato/Rifiutato/In attesa/Da inviare) + bottoni edit / rimuovi' },
        { x: 25, y: 88, label: 'Card "Dettaglio Prezzo Imposto" con sfondo lavanda: input valore in EUR + margine residuo + (per user) bottone "Chiedi approvazione"' },
        { x: 30, y: 97, label: 'Bottom bar del form: il "Prezzo a cliente" mostra il PREZZO IMPOSTO (non più il calcolato), con margine ricalcolato' },
      ]) +
      '<h3>Come attivarlo</h3>' +
      '<ol>' +
        '<li>Nel form offerta, sopra la card <strong>"Dati Commessa"</strong>, click sul bottone viola <strong>"Attiva Prezzo Imposto"</strong></li>' +
        '<li>L\'app pre-compila il valore con il <strong>totale calcolato arrotondato a EUR intero</strong>; modificalo nella card "Dettaglio Prezzo Imposto" che si apre sotto</li>' +
        '<li>Premi <strong>Tab</strong> o clicca fuori per confermare il valore — il riepilogo si aggiorna in tempo reale</li>' +
      '</ol>' +
      '<div class="guida-callout guida-warn">' +
        '<i class="fas fa-exchange-alt"></i>' +
        '<div><strong>Mutua esclusività</strong>: attivando il Prezzo Imposto, lo <strong>Sconto Direzione</strong> viene azzerato e nascosto. Le due feature non possono essere usate insieme. Per tornare allo sconto, rimuovi il prezzo imposto con la <strong>X</strong> sulla pillola.</div>' +
      '</div>' +
      '<h3>Workflow per ruolo</h3>' +
      '<ul>' +
        '<li><strong>Admin / Supervisore</strong> — il prezzo imposto è <em>auto-approvato</em> al primo click su "Attiva". Badge <span class="pi-badge pi-ok"><i class="fas fa-check-circle"></i> Approvato</span> immediato. Il riepilogo, il PDF e l\'Excel mostrano subito la riga <strong>PREZZO IMPOSTO</strong> sotto al TOTALE CALCOLATO. Modifiche successive al valore non richiedono ri-approvazione (lo stato rimane "Approvato").</li>' +
        '<li><strong>User (commerciale)</strong> — al click iniziale lo stato è <span class="pi-badge pi-draft"><i class="fas fa-pencil-alt"></i> Da inviare</span>: imposti il valore, poi premi <strong>"Chiedi approvazione"</strong> nella card dettaglio. I supervisori ricevono una mail e lo stato passa a <span class="pi-badge pi-wait"><i class="fas fa-hourglass-half"></i> In attesa</span>. Solo quando il supervisore approva, il riepilogo/PDF/Excel mostrano la riga PREZZO IMPOSTO.</li>' +
      '</ul>' +
      '<h3>I quattro stati di approvazione</h3>' +
      '<ul>' +
        '<li><span class="pi-badge pi-draft"><i class="fas fa-pencil-alt"></i> Da inviare</span> — hai attivato la modalità ma non hai ancora inviato la richiesta</li>' +
        '<li><span class="pi-badge pi-wait"><i class="fas fa-hourglass-half"></i> In attesa</span> — richiesta inviata, supervisori notificati via email</li>' +
        '<li><span class="pi-badge pi-ok"><i class="fas fa-check-circle"></i> Approvato</span> — prezzo imposto valido, applicato in PDF/Excel/Allega Odoo</li>' +
        '<li><span class="pi-badge pi-ko"><i class="fas fa-times-circle"></i> Rifiutato</span> — il supervisore ha negato (con eventuale motivazione)</li>' +
      '</ul>' +
      '<h3>Cosa cambia in PDF e Excel</h3>' +
      '<p>Quando il prezzo imposto è <strong>Approvato</strong>, il riepilogo finale mostra <strong>due righe</strong>:</p>' +
      '<ul>' +
        '<li><strong>TOTALE CALCOLATO (IVA ESCLUSA)</strong> — sfondo grigio chiaro: è il prezzo derivato da costi + markup + spese generali. Resta visibile per riferimento.</li>' +
        '<li><strong>PREZZO IMPOSTO (IVA ESCLUSA)</strong> — sfondo viola lavanda, testo viola scuro, bordo grigio: è il prezzo finale praticato al cliente. Su questa riga viene calcolato il margine reale.</li>' +
      '</ul>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-info-circle"></i>' +
        '<div>Il <strong>Prezzo Vendita</strong> nei KPI in cima al form mostra il prezzo imposto (quando approvato), <strong>non</strong> il calcolato. Idem nei KPI dell\'Allega Odoo.</div>' +
      '</div>' +
      '<h3>Approvare/rifiutare (supervisore/admin)</h3>' +
      '<p>Il workflow è lo stesso dello Sconto Direzione: la richiesta arriva nella vista <strong>"Da approvare"</strong> in topnav. Aprendo l\'offerta, vedi la card "Dettaglio Prezzo Imposto" con il valore richiesto e i bottoni <strong>Approva</strong> / <strong>Rifiuta</strong>.</p>' +
    '</section>';

  const sezOdoo =
    '<section id="sez-odoo" class="guida-section">' +
      '<h2>5. Allegare PDF + Excel a Odoo</h2>' +
      '<p class="guida-lead">Quando l\'offerta è pronta, un click la carica come <strong>allegato</strong> sulla Vendita Odoo corrispondente, in formato PDF e XLSX.</p>' +
      '<h3>Come raggiungere il pulsante "Allega a Odoo"</h3>' +
      '<p>Il pulsante <strong>non si trova nel form di modifica</strong>, ma nella vista <strong>Riepilogo</strong>. Procedura:</p>' +
      '<ol>' +
        '<li>Apri la <strong>Dashboard offerte</strong> (link "Offerte" in topnav)</li>' +
        '<li>Trova la riga dell\'offerta che vuoi allegare e click sul pulsante <strong>"Riepilogo"</strong></li>' +
        '<li>In cima alla vista Riepilogo trovi 4 pulsanti azione: <strong>Modifica · PDF · Excel · Allega a Odoo</strong></li>' +
      '</ol>' +
      imgAnno('5-allega.png', 'Vista Riepilogo con il pulsante Allega a Odoo', [
        { x: 22, y: 7, label: 'MODIFICA torna al form di compilazione' },
        { x: 28, y: 7, label: 'PDF / EXCEL scaricano il file localmente' },
        { x: 38, y: 7, label: 'ALLEGA A ODOO carica PDF + XLSX direttamente sulla Vendita' },
        { x: 30, y: 30, label: 'Banner di stato: "Allegati pronti per ..." conferma che il N. Odoo è valido' },
      ]) +
      '<h3>Cosa succede quando clicchi "Allega a Odoo"</h3>' +
      '<ol>' +
        '<li>L\'app verifica che il <strong>N. Prev/Ordine Odoo</strong> dell\'offerta esista davvero in Odoo</li>' +
        '<li>Genera al volo il PDF e l\'XLSX (gli stessi file che scarichi dai pulsanti PDF / Excel)</li>' +
        '<li>Li carica come allegato sulla <strong>Vendita</strong> corrispondente</li>' +
        '<li>Compare il messaggio <strong>"Allegati caricati!"</strong> e il timestamp viene salvato sull\'offerta</li>' +
        '<li>Tornando alla dashboard, sull\'offerta compare un\'<strong>icona link verde</strong> 🔗 a fianco del codice Odoo per indicare che è già stata allegata</li>' +
      '</ol>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-info-circle"></i>' +
        '<div><strong>Pre-requisito</strong>: il N. Odoo dell\'offerta deve corrispondere a una Vendita esistente (es. <code>S04134</code>). Se il codice è errato o la Vendita non esiste, l\'allega fallisce con messaggio di errore.</div>' +
      '</div>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-redo"></i>' +
        '<div><strong>Riallegare</strong>: puoi premere "Allega a Odoo" anche più volte. Ogni esecuzione carica una nuova coppia PDF+XLSX su Odoo (i precedenti restano come storico). Utile dopo modifiche all\'offerta.</div>' +
      '</div>' +
      '<div class="guida-callout guida-info">' +
        '<i class="fas fa-info-circle"></i>' +
        '<div>I file allegati su Odoo sono <strong>identici</strong> a quelli che scarichi dai pulsanti PDF/Excel — stesso layout, stessa palette, stesso contenuto.</div>' +
      '</div>' +
      '<h3>Cosa contiene il PDF</h3>' +
      '<ul>' +
        '<li>Header con logo Ecotel, dati commessa, cliente, N. Odoo</li>' +
        '<li>Una tabella per ogni sezione con righe attive (le sezioni vuote vengono saltate)</li>' +
        '<li>Riepilogo finale con costi, prezzi vendita, margine € e %, spese generali, eventuale sconto direzione, TOTALE COMMESSA</li>' +
      '</ul>' +
      '<h3>Cosa contiene l\'Excel</h3>' +
      '<ul>' +
        '<li>Tutte le formule sono <strong>vive</strong>: cambi un costo o un markup e Excel ricalcola</li>' +
        '<li>Il margine % finale ha semaforo automatico (verde/giallo/rosso)</li>' +
        '<li>Stampa: già configurato fit-to-page con header e numerazione pagine</li>' +
      '</ul>' +
      '<h3>Riprendere gli allegati da Odoo</h3>' +
      '<p>Sulla Vendita in Odoo, sezione <strong>Allegati</strong>, trovi due file con nome <code>BP Template DDMMYYYY.pdf</code> e <code>.xlsx</code>. Sono visibili a chiunque abbia accesso alla Vendita.</p>' +
    '</section>';

  return renderTopnav('guida') +
    '<div class="o-control-panel">' +
      '<div class="o-cp-breadcrumb">' +
        '<a onclick="screen=\'list\';render()">Offerte</a><span class="o-bc-sep">›</span>' +
        '<span class="o-bc-current">Guida Utente</span>' +
      '</div>' +
    '</div>' +
    '<div class="guida-page">' +
      sommario +
      '<main class="guida-content">' +
        '<header class="guida-header">' +
          '<h1>Guida Utente — BP Template</h1>' +
          '<div class="guida-sub">Versione corrente · ' + new Date().toLocaleDateString('it-IT') + '</div>' +
        '</header>' +
        sezLogin +
        sezDashboard +
        sezForm +
        sezSconto +
        sezPrezzoImposto +
        sezOdoo +
        '<footer class="guida-footer">' +
          'BP Template · Ecotel Italia · per supporto: <a href="mailto:sistemi-informativi@ecotelitalia.it">sistemi-informativi@ecotelitalia.it</a>' +
        '</footer>' +
      '</main>' +
    '</div>';
}
