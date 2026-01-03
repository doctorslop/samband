## Sambandscentralen 🚔

Sambandscentralen är en modern, responsiv webbapplikation som visar polisens händelsenotiser i realtid. Applikationen hämtar data från Polisens öppna API och presenterar information om utryckningar och händelser över hela Sverige.

## Funktioner

### 📋 Lista-vy
- Visar händelser som kort med datum, tid, typ och sammanfattning
- Infinite scroll för att ladda fler händelser automatiskt
- Animerade kort med hover-effekter

### 🗺️ Karta-vy
- Interaktiv karta baserad på Leaflet.js
- Händelser visas som färgkodade markörer
- Popup-rutor med händelsedetaljer
- Stöd för ljust/mörkt karttema

### 📊 Statistik-vy
- Översikt över händelser senaste 24h och 7 dagar
- Vanligaste händelsetyper med stapeldiagram
- Händelser per plats
- Timmesfördelning

### 🔍 Sökning & Filtrering
- Fritextsökning i händelsernas titel, sammanfattning och plats
- Filtrera på plats (län/kommun)
- Filtrera på händelsetyp
- Tangentbordsgenväg: `Ctrl/Cmd + K` för snabbsökning

### 📱 PWA-stöd (Progressive Web App)
- Kan installeras på hemskärmen
- Offline-stöd via Service Worker
- Caching-strategier för snabbare laddning

## Teknisk översikt

### Backend
- **PHP 3.0** - Serverhämtning och databehandling
- **Caching** - 5 minuters cache för API-anrop
- **AJAX-endpoints** - Stöd för paginering och statistik

### Frontend
- **HTML5 + CSS3 + Vanilla JavaScript**
- **Google Fonts** - DM Sans (brödtext), Playfair Display (rubriker)
- **Leaflet.js 1.9.4** - Interaktiv kartfunktionalitet
- **CartoDB tiles** - Kartbilder för ljust/mörkt tema

### API-integration
Applikationen använder Polisens öppna API för att hämta händelsedata.

**Bas-URL:** `https://polisen.se/api/events`

**Filtreringsparametrar:**
| Parameter | Beskrivning | Exempel |
|-----------|-------------|---------|
| `locationname` | Filtrera på plats (län/kommun) | `?locationname=Stockholm` |
| `type` | Filtrera på händelsetyp | `?type=Misshandel` |
| `DateTime` | Filtrera på datum/tid | `?DateTime=2026-01-03` |

**Exempel på API-anrop:**
```
# Alla händelser
https://polisen.se/api/events

# Händelser i Stockholm
https://polisen.se/api/events?locationname=Stockholm

# Händelser av typ "Trafikolycka" i Göteborg
https://polisen.se/api/events?locationname=Göteborg&type=Trafikolycka

# Händelser från ett specifikt datum
https://polisen.se/api/events?DateTime=2026-01-03
```

## Installation

1. Placera filerna på en webbserver med PHP-stöd
2. Säkerställ att webbservern har tillgång till `https://polisen.se`
3. Besök applikationen via webbläsaren

## Filer

| Fil | Beskrivning |
|-----|-------------|
| `index.php` | Huvudapplikation med PHP-backend, HTML, CSS och JavaScript |
| `sw.js` | Service Worker för offline-stöd och caching |
| `manifest.json` | PWA-manifest för installation |
| `offline.html` | Fallback-sida vid offline |
| `icons/` | App-ikoner för olika plattformar |

## Automatisk uppdatering

Applikationen uppdateras automatiskt var 5:e minut för att visa nya händelser.

## Responsiv design

- **Desktop** (>1024px) - Full layout med statistik-sidebar
- **Tablet** (768-1024px) - Anpassad layout utan sidebar
- **Mobil** (<768px) - Kolumnlayout, komprimerade kort

## Licens

Data tillhandahålls av Polismyndigheten via deras öppna API. Se [polisen.se](https://polisen.se) för mer information.
