# Samband API

Backend-API för att samla och servera polishändelser med långtidslagring.

## Funktioner

- Samlar händelser från Polisens API var 5:e minut
- Sparar all data permanent (ingen automatisk radering)
- Daglig automatisk backup med verifiering
- Samma dataformat som Polisens API
- Utökad filtrering (datumintervall, paginering)
- API-nyckel-autentisering
- Rate limiting (60 req/min)

## Databassäkerhet

| Skydd | Beskrivning |
|-------|-------------|
| **WAL-mode** | Write-Ahead Logging för kraschsäkerhet |
| **Integritetskontroll** | Körs vid uppstart - vägrar starta om DB är korrupt |
| **WAL checkpoint** | Automatisk tömning av WAL-fil till huvuddatabasen |
| **Verifierad backup** | Integritetskontroll + kontroll att antal händelser stämmer |
| **Auto-vacuum** | Inkrementell komprimering |

## Snabbstart

```bash
# Kopiera till VPS
scp -r api/ user@din-vps:/opt/samband-api/

# Starta
ssh user@din-vps
cd /opt/samband-api
./start.sh
```

Startscriptet skapar automatiskt virtuell miljö och datakataloger.

## Konfiguration (.env)

```env
# API-nyckel (statisk för stabilitet)
API_KEY=din-hemliga-nyckel

# Tillåtna origins (kommaseparerade)
ALLOWED_ORIGINS=https://din-frontend.se

# Databas
DATABASE_PATH=./data/events.db
BACKUP_PATH=./data/backups

# Schemaläggning
FETCH_INTERVAL_MINUTES=5
BACKUP_INTERVAL_HOURS=24

# Rate limiting
RATE_LIMIT_PER_MINUTE=60

# Miljö (development visar /docs)
ENVIRONMENT=production
```

## Körning

### Utveckling
```bash
./start.sh dev
```

### Produktion
```bash
./start.sh
```

### Med systemd (rekommenderat)
```bash
sudo cp samband-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable samband-api
sudo systemctl start samband-api

# Visa loggar
sudo journalctl -u samband-api -f
```

## API-endpoints

Alla endpoints utom `/health` kräver `X-API-Key` header.

### GET /health
Hälsokontroll (ingen auth).
```bash
curl http://localhost:8000/health
```

### GET /api/events
Hämta händelser med metadata.
```bash
curl -H "X-API-Key: KEY" "http://localhost:8000/api/events?location=Stockholm"
```

**Parametrar:**
| Parameter | Beskrivning |
|-----------|-------------|
| `location` | Plats (exakt matchning) |
| `type` | Händelsetyp |
| `date` | Datum (YYYY, YYYY-MM, YYYY-MM-DD) |
| `from` | Från datum (YYYY-MM-DD) |
| `to` | Till datum (YYYY-MM-DD) |
| `limit` | Max antal (1-1000, default 500) |
| `offset` | Hoppa över N |
| `sort` | `desc` (default) eller `asc` |

### GET /api/events/raw
Samma som ovan, returnerar endast array (kompatibelt med Polisens API).

### GET /api/locations
Alla platser med antal händelser.
```json
[
  {"name": "Stockholm", "count": 4521},
  {"name": "Göteborg", "count": 2103}
]
```

### GET /api/types
Alla händelsetyper med antal.

### GET /api/stats
Statistik med datumintervall.
```bash
curl -H "X-API-Key: KEY" "http://localhost:8000/api/stats"
```

Returnerar:
```json
{
  "total": 15234,
  "by_type": {"Trafikolycka": 2341, ...},
  "by_month": {"2026-01": 543, ...},
  "date_range": {
    "oldest": "2025-06-15T08:23:00",
    "latest": "2026-01-09T14:05:00"
  }
}
```

### GET /api/database
Databasinfo: händelser, storlek, senaste backup.

### POST /api/fetch
Trigga manuell hämtning (max 6/minut).

### POST /api/backup
Trigga manuell backup (max 2/timme).

## Datalagring

```
data/
├── events.db          # Huvuddatabas (SQLite, WAL-mode)
├── events.db-wal      # Write-ahead log
├── events.db-shm      # Shared memory
└── backups/
    ├── events_backup_20260108_030000.db
    └── ...
```

- **Händelser**: Sparas permanent
- **Loggar**: Rensas efter 30 dagar
- **Backups**: Behålls 30 dagar, verifieras vid skapande

## Backup-verifiering

Vid varje backup:
1. WAL checkpoint körs först (all data till huvudfil)
2. Backup skapas med SQLite backup API
3. Integritetskontroll på backup-filen
4. Jämförelse av antal händelser (källa vs backup)
5. Om något misslyckas: backup raderas, fel loggas

## Nginx reverse proxy

```nginx
server {
    listen 443 ssl http2;
    server_name api.din-domain.se;

    ssl_certificate /etc/letsencrypt/live/api.din-domain.se/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.din-domain.se/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Felsökning

### API startar inte
```bash
sudo journalctl -u samband-api -n 50
sudo lsof -i :8000
```

### Databasproblem
```bash
# Kontrollera integritet
sqlite3 data/events.db "PRAGMA integrity_check;"

# Återställ från backup
cp data/backups/events_backup_LATEST.db data/events.db
```

### Manuell WAL checkpoint
```bash
sqlite3 data/events.db "PRAGMA wal_checkpoint(TRUNCATE);"
```

## Licens

MIT
