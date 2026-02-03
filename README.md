# Sambandscentralen

A real-time Swedish police event notification service built with Next.js. Fetches and displays police events from the Swedish Police API with interactive maps, statistics, and VMA (Important Public Announcements) integration.

## Features

- **Real-time Events** - Automatically fetches police events every 2 minutes
- **Multiple Views** - List, Map, Statistics, and VMA views
- **Interactive Map** - Leaflet-powered map showing events from the last 24 hours
- **Statistics Dashboard** - Visual charts showing event trends, top locations, and hourly distribution
- **VMA Integration** - Displays Important Public Announcements from Sveriges Radio
- **Advanced Filtering** - Filter by location, event type, or search terms
- **Event Details** - Lazy-loaded detailed information for each event
- **Responsive Design** - Works on desktop, tablet, and mobile
- **PWA Support** - Installable as a Progressive Web App
- **Dark Theme** - Modern dark UI optimized for readability

## Tech Stack

- **Framework**: [Next.js 14](https://nextjs.org/) with App Router
- **Language**: [TypeScript](https://www.typescriptlang.org/)
- **Database**: [SQLite](https://www.sqlite.org/) via [better-sqlite3](https://github.com/WiseLibs/better-sqlite3)
- **Maps**: [Leaflet](https://leafletjs.com/) with [react-leaflet](https://react-leaflet.js.org/)
- **Styling**: Custom CSS with CSS variables
- **Data Sources**:
  - [Swedish Police API](https://polisen.se/api/events)
  - [VMA API (Sveriges Radio)](https://vmaapi.sr.se/)

## Getting Started

### Prerequisites

- Node.js 18.x or later
- npm or yarn

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/doctorslop/samband.git
   cd samband
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Run the development server:
   ```bash
   npm run dev
   ```

4. Open [http://localhost:3000](http://localhost:3000) in your browser.

### Production Build

```bash
npm run build
npm start
```

## Project Structure

```
samband/
├── src/
│   ├── app/                    # Next.js App Router
│   │   ├── layout.tsx          # Root layout with metadata
│   │   ├── page.tsx            # Home page (Server Component)
│   │   ├── globals.css         # Global styles
│   │   └── api/                # API Route Handlers
│   │       ├── events/         # GET /api/events
│   │       ├── details/        # GET /api/details
│   │       └── vma/            # GET /api/vma
│   │
│   ├── components/             # React Components
│   │   ├── ClientApp.tsx       # Main client-side wrapper
│   │   ├── EventCard.tsx       # Individual event card
│   │   ├── EventList.tsx       # Event grid with pagination
│   │   ├── EventMap.tsx        # Full map view (Leaflet)
│   │   ├── MapModal.tsx        # Single location map modal
│   │   ├── Filters.tsx         # Search and filter controls
│   │   ├── Header.tsx          # Sticky header with navigation
│   │   ├── StatsView.tsx       # Statistics dashboard
│   │   ├── VMAView.tsx         # VMA alerts view
│   │   ├── Footer.tsx          # Footer with links
│   │   └── ScrollToTop.tsx     # Scroll to top button
│   │
│   ├── lib/                    # Server-side utilities
│   │   ├── db.ts               # SQLite database operations
│   │   ├── policeApi.ts        # Police API client
│   │   ├── vmaApi.ts           # VMA API client
│   │   └── utils.ts            # Formatting utilities
│   │
│   └── types/                  # TypeScript definitions
│       └── index.ts            # Shared type definitions
│
├── public/                     # Static assets
│   ├── manifest.json           # PWA manifest
│   ├── icons/                  # App icons
│   └── sound/                  # Audio files
│
├── data/                       # Data directory
│   └── events.db               # SQLite database (created at runtime)
│
├── package.json
├── tsconfig.json
├── next.config.js
└── README.md
```

## API Endpoints

### GET /api/events

Fetches paginated police events from the database.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | number | Page number (default: 1) |
| `location` | string | Filter by location name |
| `type` | string | Filter by event type |
| `search` | string | Search in name, summary, location |

**Response:**
```json
{
  "events": [...],
  "hasMore": true,
  "total": 1234
}
```

### GET /api/details

Fetches detailed text content for a specific event from polisen.se.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | Event URL path (e.g., `/aktuellt/handelser/...`) |

**Response:**
```json
{
  "success": true,
  "details": {
    "content": "Detailed event description..."
  }
}
```

### GET /api/vma

Fetches VMA (Important Public Announcements) from Sveriges Radio.

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `refresh` | string | Set to `1` to force cache refresh |

**Response:**
```json
{
  "success": true,
  "current": [...],
  "recent": [...]
}
```

## Database Schema

The SQLite database stores events with the following structure:

```sql
CREATE TABLE events (
  id INTEGER PRIMARY KEY,
  datetime TEXT,
  event_time TEXT,           -- When the event occurred
  publish_time TEXT,         -- When the event was published
  last_updated TEXT,         -- Last update timestamp
  name TEXT,
  summary TEXT,
  url TEXT,
  type TEXT,
  location_name TEXT,
  location_gps TEXT,
  raw_data TEXT,             -- Original JSON from API
  fetched_at TEXT,
  content_hash TEXT          -- For change detection
);

CREATE TABLE fetch_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fetched_at TEXT,
  events_fetched INTEGER,
  events_new INTEGER,
  success INTEGER,
  error_message TEXT
);
```

## Configuration

### Environment Variables

No environment variables are required for basic operation. The application uses sensible defaults.

### Cache Settings

| Setting | Value | Description |
|---------|-------|-------------|
| Page revalidation | 120s | How often Server Components refetch data |
| Police API cache | 120s | Minimum time between API calls |
| VMA cache | 300s | VMA feed cache duration |

### Next.js Config

Key settings in `next.config.js`:
- Security headers (X-Frame-Options, CSP, etc.)
- Webpack configuration for better-sqlite3

## Views

### List View (Default)
Displays events in a card-based grid layout with:
- Event type badge with color coding
- Location and timestamp
- Summary text
- Expandable details (lazy-loaded)
- Map link for events with GPS coordinates

### Map View
Interactive Leaflet map showing:
- Events from the last 24 hours
- Color-coded markers by event type
- Popup with event details and links
- Event count indicator

### Statistics View
Dashboard with:
- Key metrics (total, 24h, 7d, 30d counts)
- 7-day trend chart
- Events by weekday
- Hourly distribution (last 24h)
- Top event types
- Top locations

### VMA View
Important Public Announcements with:
- Active alerts (highlighted)
- Recent/historical alerts
- Severity indicators
- Expandable details
- Links to official sources

## Event Types

Events are color-coded by type:

| Type | Color | Icon |
|------|-------|------|
| Inbrott (Burglary) | Orange | 🔓 |
| Brand (Fire) | Red | 🔥 |
| Rån (Robbery) | Amber | 💰 |
| Trafikolycka (Traffic) | Blue | 🚗 |
| Misshandel (Assault) | Red | 👊 |
| Narkotikabrott (Drugs) | Green | 💊 |
| Bedrägeri (Fraud) | Purple | 🕵️ |
| Skadegörelse (Vandalism) | Amber | 🔨 |
| Stöld (Theft) | Orange | 🔓 |
| Mord/dråp (Murder) | Dark Red | ⚠️ |
| Sammanfattning (Summary) | Green | 📊 |
| Default | Yellow | 📌 |

## PWA Features

The application is a Progressive Web App with:
- Installable on desktop and mobile
- Offline-capable manifest
- App shortcuts for Map and Statistics views
- Custom app icon

## Development

### Running in Development

```bash
npm run dev
```

The development server runs on port 3000 with hot reload.

### Linting

```bash
npm run lint
```

### Type Checking

TypeScript errors are checked during build:
```bash
npm run build
```

## Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│                        User Request                          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   Server Component (page.tsx)                │
│  - Checks if data refresh needed (every 2 min)              │
│  - Fetches from Police API if stale                         │
│  - Queries SQLite database                                   │
│  - Formats events for UI                                     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                  Client Component (ClientApp.tsx)            │
│  - Handles view switching                                    │
│  - Manages UI state (filters, modals)                       │
│  - Renders appropriate view component                        │
└─────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
        ┌──────────┐   ┌──────────┐   ┌──────────┐
        │ EventList│   │ EventMap │   │StatsView │
        └──────────┘   └──────────┘   └──────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────┐
│                    API Routes (on demand)                    │
│  - /api/events - Pagination                                  │
│  - /api/details - Lazy-load event details                   │
│  - /api/vma - VMA alerts                                     │
└─────────────────────────────────────────────────────────────┘
```

## Browser Support

- Chrome (recommended)
- Firefox
- Safari
- Edge

## Migration from PHP

This project was converted from a PHP application. Key differences:

| Aspect | PHP Version | Next.js Version |
|--------|-------------|-----------------|
| Rendering | Server-side PHP | Server Components + Client hydration |
| Routing | Query params (`?view=`) | App Router (URL state preserved) |
| API | PHP endpoints | Route Handlers |
| Database | PDO | better-sqlite3 |
| Frontend | Vanilla JS | React Components |
| Build | None | Webpack via Next.js |

The original PHP files are preserved in the repository for reference.

## License

This project fetches data from public APIs. Please respect the terms of service of:
- [Polisen.se](https://polisen.se)
- [Sveriges Radio VMA API](https://sverigesradio.se)

## Acknowledgments

- Swedish Police for the public events API
- Sveriges Radio for the VMA feed
- OpenStreetMap contributors
- CartoDB for the dark map theme
- Leaflet.js for mapping
