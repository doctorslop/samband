# Sambandscentralen

Sambandscentralen visar polisens händelsenotiser med historik. Applikationen är självständig och lagrar händelser lokalt i SQLite - ingen extern server behövs.

## Arkitektur

```
┌─────────────────┐     ┌─────────────────┐
│   Webbserver    │────▶│  Polisens API   │
│   (PHP+SQLite)  │     │  (polisen.se)   │
└─────────────────┘     └─────────────────┘
        │
        ▼
┌─────────────────┐
│    SQLite DB    │
│   (data/)       │
└─────────────────┘
```

- **Allt-i-ett**: PHP på delad hosting (t.ex. Hostinger)
- **Auto-init**: Databasen skapas automatiskt vid första besök
- **Historik**: Händelser sparas permanent i SQLite

## Funktioner

### 📋 Lista-vy
- Händelser som kort med datum, tid, typ och sammanfattning
- Infinite scroll för automatisk laddning
- Animerade kort med hover-effekter

### 🗺️ Karta-vy
- Interaktiv karta baserad på Leaflet.js
- Färgkodade markörer per händelsetyp
- Popup-rutor med händelsedetaljer
- Ljust/mörkt karttema

### 📊 Statistik-vy
- Översikt senaste 24h och 7 dagar
- Vanligaste händelsetyper med stapeldiagram
- Händelser per plats och timmesfördelning

### 📰 Pressmeddelanden
- Samlade från alla polisregioner
- Sökning och filtrering per region

### 🔍 Sökning & Filtrering
- Fritextsökning i titel, sammanfattning och plats
- Filtrera på plats (län/kommun)
- Filtrera på händelsetyp
- Snabbsökning: `Ctrl/Cmd + K`

### 📦 Historik
- Alla händelser sparas lokalt i SQLite
- Bläddra bakåt i tiden
- Footern visar antal händelser i arkivet

### 📱 PWA-stöd
- Installation på hemskärmen
- Offline-stöd via Service Worker
- Caching för snabbare laddning

## Teknisk översikt

### Allt-i-ett (index.php)
- **PHP 8.x** - Serverhämtning och databehandling
- **SQLite med WAL-mode** - Kraschsäker lagring
- **Auto-fetch** - Hämtar nya händelser var 10:e minut
- **HTML5 + CSS3 + Vanilla JS**
- **Leaflet.js 1.9.4** - Kartfunktionalitet

### Datalagring
- **data/events.db** - SQLite-databas (skapas automatiskt)
- **WAL-mode** - Säker mot krascher
- **Permanent lagring** - Händelser raderas aldrig

## Installation

### Delad hosting (Hostinger, etc.)

1. Ladda upp alla filer till webbhotellet:
   ```
   index.php
   css/
   js/
   manifest.json
   offline.html
   icons/
   ```

2. Besök sidan - databasen skapas automatiskt!

3. **Valfritt**: Sätt upp cron-jobb för bakgrundshämtning:
   ```
   */10 * * * * curl -s https://din-domän.se/index.php > /dev/null
   ```
   (Behövs inte - sidan hämtar ny data vid varje besök om det gått 10+ minuter)

### Krav
- PHP 8.0+
- PDO SQLite-extension (standard på de flesta hosting)
- Skrivbar `data/`-katalog (skapas automatiskt)

## Filer

| Fil/Katalog | Beskrivning |
|-------------|-------------|
| `index.php` | Huvudapplikation (PHP + API + Frontend) |
| `css/styles.css` | Stilmallar |
| `js/app.js` | JavaScript-funktionalitet |
| `js/sw.js` | Service Worker för offline/caching |
| `manifest.json` | PWA-manifest |
| `offline.html` | Fallback vid offline |
| `icons/` | App-ikoner |
| `data/` | SQLite-databas (skapas automatiskt) |

## Konfiguration

Anpassa i toppen av `index.php`:

```php
define('CACHE_TIME', 600);           // Hämtintervall (sekunder)
define('EVENTS_PER_PAGE', 40);       // Händelser per sida
define('USER_AGENT', 'FreshRSS/1.28.0 (Linux; https://freshrss.org)');
```

## Automatik

- **Datahämtning**: Var 10:e minut (vid sidbesök)
- **Lokal lagring**: Alla händelser sparas permanent
- **Låsning**: Förhindrar parallella hämtningar

## Responsiv design

- **Desktop** (>1024px) - Full layout med sidebar
- **Tablet** (768-1024px) - Anpassad utan sidebar
- **Mobil** (<768px) - Kolumnlayout, komprimerade kort

## Licens

Data från Polismyndigheten via öppet API. Se [polisen.se](https://polisen.se).
