# Sambandscentralen

Sambandscentralen visar polisens händelsenotiser med historik. Applikationen använder en egen VPS-backend för att lagra händelser långsiktigt och presenterar information om utryckningar över hela Sverige.

## Arkitektur

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Frontend      │────▶│    VPS API      │────▶│  Polisens API   │
│  (volohost.com) │     │ (193.181.23.219)│     │  (polisen.se)   │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                               ▼
                        ┌─────────────────┐
                        │    SQLite DB    │
                        │  (historik)     │
                        └─────────────────┘
```

- **Frontend**: PHP på delad hosting, anropar VPS API
- **VPS API**: Python/FastAPI, samlar och lagrar händelser
- **Fallback**: Om VPS är nere hämtas direkt från Polisens API

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

### 🔍 Sökning & Filtrering
- Fritextsökning i titel, sammanfattning och plats
- Filtrera på plats (län/kommun)
- Filtrera på händelsetyp
- Datumfiltrering med historik
- Snabbsökning: `Ctrl/Cmd + K`

### 📦 Historik
- Alla händelser sparas på VPS (1+ år)
- Bläddra bakåt i tiden via datumväljare
- Footern visar antal händelser i arkivet

### 📱 PWA-stöd
- Installation på hemskärmen
- Offline-stöd via Service Worker
- Caching för snabbare laddning

## Teknisk översikt

### Frontend (index.php)
- **PHP 8.x** - Serverhämtning och databehandling
- **Stale-while-revalidate** - Visar cache, uppdaterar i bakgrunden
- **VPS API-integration** - Med 5s timeout och fallback
- **HTML5 + CSS3 + Vanilla JS**
- **Leaflet.js 1.9.4** - Kartfunktionalitet

### Backend (api/)
- **Python 3.11+ / FastAPI**
- **SQLite med WAL-mode** - Kraschsäker lagring
- **Schemalagd hämtning** - Var 5:e minut
- **Daglig backup** - Med integritetskontroll
- **API-nyckel-auth** - Skyddar endpoints

Se [api/README.md](api/README.md) för backend-dokumentation.

## Installation

### Frontend (delad hosting)

1. Ladda upp alla filer utom `api/` till webbhotell
2. Konfigurera VPS-anslutning i `index.php`:
   ```php
   define('VPS_API_URL', 'http://din-vps-ip:8000');
   define('VPS_API_KEY', 'din-api-nyckel');
   ```

### Backend (VPS)

Se [api/README.md](api/README.md) för fullständig guide.

```bash
scp -r api/ user@din-vps:/opt/samband-api/
ssh user@din-vps
cd /opt/samband-api && ./start.sh
```

## Filer

| Fil/Katalog | Beskrivning |
|-------------|-------------|
| `index.php` | Huvudapplikation med frontend-logik |
| `css/styles.css` | Stilmallar |
| `js/app.js` | JavaScript-funktionalitet |
| `sw.js` | Service Worker för offline/caching |
| `manifest.json` | PWA-manifest |
| `offline.html` | Fallback vid offline |
| `icons/` | App-ikoner |
| `api/` | VPS backend (separat deploy) |

## Automatik

- **Uppdatering**: Var 5:e minut
- **Backup**: Dagligen kl 03:00
- **Logrensning**: Var 24:e timme (behåller 30 dagar)

## Responsiv design

- **Desktop** (>1024px) - Full layout med sidebar
- **Tablet** (768-1024px) - Anpassad utan sidebar
- **Mobil** (<768px) - Kolumnlayout, komprimerade kort

## Licens

Data från Polismyndigheten via öppet API. Se [polisen.se](https://polisen.se).
