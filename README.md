# MWD Admin Studio

Reskin SaaS complet al panoului de administrare WordPress: design modern, paletă de comenzi (⌘K), meniu lateral organizat pe secțiuni, manager de meniu și coloane pe roluri, login restilizat, dashboard și analitice integrate.

- **Versiune:** 2.1.0
- **Necesită:** WordPress 5.5+, PHP 7.2+
- **Licență:** GPL-2.0-or-later

## Instalare

Copiază directorul în `wp-content/plugins/mwd-admin-studio/` (sau încarcă arhiva ZIP din *Plugins → Add New → Upload*) și activează pluginul. Setările sunt în meniul **Admin Studio → Setări**.

## Module

| Modul | Fișier | Ce face |
|---|---|---|
| Setări | `includes/class-mwd-as-settings.php` | Pagina de setări (tab-uri), salvare + sanitizare centralizată |
| Skin | `includes/class-mwd-as-styler.php`, `assets/css/admin.css` | Design tokens (culori, radius, font, densitate) aplicate în tot adminul |
| Meniu | `includes/class-mwd-as-menu.php` | Reordonare / redenumire / ascundere pe roluri (meniu + sub-meniu) |
| Secțiuni sidebar | `includes/class-mwd-as-menucollapse.php`, `assets/js/menu-collapse.js` | Grupuri pliabile (manual sau automat), stare reținută per utilizator |
| Paletă de comenzi | `includes/class-mwd-as-palette.php`, `assets/js/palette.js` | Ctrl/⌘ + K: navigare, acțiuni rapide, căutare în conținut |
| Acces | `includes/class-mwd-as-guard.php` | Paginile ascunse devin și interzise (inclusiv editare/creare conținut) |
| Coloane | `includes/class-mwd-as-columns.php` | Ascunde coloane din tabelele de liste, pe roluri |
| Login | `includes/class-mwd-as-login.php`, `assets/css/login.css` | Layout split (brand + formular) sau centrat |
| Dashboard | `includes/class-mwd-as-dashboard.php` | KPI-uri, WooCommerce, vizitatori, acțiuni rapide |
| Analitice | `includes/class-mwd-as-tracker.php` | Tracker propriu (sesiuni, afișări, surse), fără servicii externe |
| White-label | `includes/class-mwd-as-branding.php` | Footer, logo WP, versiune, notificări pentru clienți |
| Import/export | `includes/class-mwd-as-export.php` | Configurație JSON, vizitatori CSV, comenzi CSV |

## Regula critică de stil

Nu se setează **niciodată** `overflow` / `position` / `z-index` pe `#adminmenuwrap` sau `#adminmenuback` — ar strica scroll-ul sidebar-ului și flyout-urile native ale sub-meniurilor. Stilul „pill" al item-elor folosește doar margin / padding / radius pe `<a>`, și doar când meniul e extins.

## Hook-uri pentru dezvoltatori

- `mwd_as_auto_group_rules` — regulile de grupare automată (`grup => [cuvinte-cheie]`)
- `mwd_as_menu_essentials` — id-urile `<li>` care rămân mereu vizibile în sidebar

## Changelog

### 2.1.0

**Performanță**
- Opțiunile pluginului sunt memorate pe durata cererii (înainte: zeci de citiri + procesări per pagină de admin).
- Rezumatul de analitice: ~18 interogări → 7 (agregare condiționată pe perioada curentă + anterioară într-o singură trecere); `SHOW TABLES` o singură dată per cerere.
- WooCommerce: vânzările se calculează direct în SQL (tabela de analitice `wc_order_stats`, apoi HPOS, apoi stocarea clasică) în loc să încarce până la 1000 de obiecte `WC_Order` per afișare a dashboard-ului.
- Cache-ul WooCommerce se golește automat la comandă nouă / schimbare de status / rambursare / stoc, deci cifrele sunt actuale fără a aștepta 5 minute.
- „Online acum" nu mai face cereri cât timp tab-ul browserului e ascuns și nu mai face o cerere inutilă la încărcare.
- Exportul CSV de vizitatori rulează pe loturi de 2.000 de rânduri (înainte: până la 50.000 în memorie).

**Dashboard**
- Grafic de trend interactiv: Vânzări / Comenzi / Vizite / Afișări (o metrică pe rând, fără axe duble), axă Y cu valori rotunde, tooltip la hover, total + variație față de perioada anterioară, tabel accesibil pentru cititoare de ecran; metrica aleasă e reținută.
- KPI noi: valoare medie comandă și rată de conversie (comenzi / vizite), cu variație.
- Card „Acum pe site": vizitatori activi (pagină, dispozitiv, țară), actualizat live.
- Card „Sănătate site": actualizări disponibile, versiune PHP, indexare dezactivată, erori PHP afișate public, lipsă HTTPS.
- Linkurile de comenzi funcționează și cu HPOS.

**Funcțional / corecturi**
- Statisticile WooCommerce erau plafonate la 500 de comenzi pe perioadă — magazinele mari vedeau vânzări subestimate. Acum sunt exacte; rambursările-copil sunt excluse.
- „Stoc redus" ia în calcul doar produsele cu gestiune de stoc activă (include variațiile, cu link la produsul părinte).
- Cu presetul „Light" (sidebar alb), titlul și cifrele din dashboard erau invizibile — foloseau culoarea sidebar-ului.
- Retenția datelor de analitice e configurabilă (30–730 zile).
- Opțiune „Șterge toate datele la dezinstalare" + `uninstall.php` (implicit dezactivată, ca o reinstalare să nu piardă istoricul).

### 2.0.0

**Design SaaS nou**
- Skin rescris pe design tokens: sidebar cu item-e „pill", sub-meniuri rotunjite, bule de notificare discrete, bara de admin coerentă cu sidebar-ul.
- Conținut: titluri, butoane, inputuri cu focus ring, tabele cu antet subtil și hover pe rând, notificări tip card, tab-uri native restilizate.
- Culoarea textului de pe accent se calculează automat (contrast) — funcționează și cu accente deschise.
- Densitate „Compact" opțională; fonturi noi: Manrope, Geist.
- Stilurile de conținut nu se mai aplică în editorul de blocuri (Gutenberg are propriul design).
- Pagina de login nouă: layout split (panou de brand cu mesaj + formular) sau centrat; logo ales din Media Library.
- Pagina de setări reconstruită ca aplicație: navigare laterală, switch-uri, segmented controls, previzualizare live a paletei, 7 preseturi, bară „modificări nesalvate", Ctrl/⌘ + S pentru salvare.

**Meniu mai eficient**
- Paletă de comenzi **Ctrl/⌘ + K**: caută în tot meniul (inclusiv sub-pagini), acțiuni rapide filtrate pe capabilități, căutare în articole / pagini / produse, istoric „Recente". Buton „Caută" în bara de admin.
- Secțiunile din sidebar apar în poziția primului lor element (respectă ordinea din manager), nu mai sunt mutate la final; antete uppercase discrete, contor, indicator pentru pagina activă.
- Starea deschis/închis a secțiunilor e reținută per utilizator; mod acordeon opțional; separatoarele pot fi ascunse.
- Iconițe configurabile pentru fiecare secțiune (vizibile în meniul restrâns).
- Manager: filtrare, ochi de vizibilitate, iconițele reale ale meniului, contor elemente/ascunse.
- Roluri: comutator „Moștenește Implicit", copiere configurare dintr-un alt rol, resetare rol.

**Funcțional / corecturi**
- Managerul de meniu citește meniul *înainte* de ascundere/redenumire: elementele ascunse nu mai dispar din listă (înainte, o salvare ulterioară le „dez-ascundea" fără să vrei) și se afișează etichetele originale.
- Salvarea pe un tab de rol nu mai creează involuntar o configurare proprie — rolul poate moșteni în continuare „Implicit".
- Bula de notificări (ex. Comentarii) era tăiată greșit la redenumire (span-uri imbricate) — corectat.
- Managerul de meniu / ascunderea paginilor funcționează și cu skin-ul vizual oprit.
- Guard: administratorii pot fi scutiți de blocare (anti-lockout, implicit activ); blocarea acoperă și `post.php` / `post-new.php` / `term.php` pentru tipurile ascunse.
- Importul JSON trece prin aceeași sanitizare ca formularul (nu mai poate introduce HTML / valori invalide).
- Salvare cu redirect (PRG): refresh-ul nu mai retrimite formularul.
- Butonul „Restrânge meniul" rămâne ultimul în sidebar (secțiunile erau adăugate după el).
- Etichetele secțiunilor sunt inserate ca text (nu HTML) în sidebar.
- Link „Setări" în lista de plugin-uri; sub-meniul se numește „Setări", nu repetă brandul.
