# Samband API

Backend-API för att samla och servera polishändelser med historik.

## Funktioner

- Samlar händelser från Polisens API var 5:e minut
- Sparar all data för evigt (ingen automatisk radering)
- Daglig automatisk backup
- Samma dataformat som Polisens API
- Utökad filtrering (datumintervall, paginering)
- API-nyckel-autentisering
- Rate limiting
- WAL-mode för databas (robust mot krascher)

## Snabbstart

```bash
# Kopiera api/-mappen till VPS
scp -r api/ user@din-vps:/opt/samband-api/

# SSH till VPS och starta
ssh user@din-vps
cd /opt/samband-api
./start.sh
```

Startscriptet skapar automatiskt:
- Virtuell Python-miljö
- API-nyckel (visas i terminalen)
- Datakataloger

## Manuell installation

```bash
cd /opt/samband-api

# Skapa virtuell miljö
python3 -m venv venv
source venv/bin/activate

# Installera beroenden
pip install -r requirements.txt

# Konfigurera
cp .env.example .env
nano .env  # Ändra API_KEY och ALLOWED_ORIGINS

# Starta
./start.sh
```

## Konfiguration (.env)

```env
# Generera: python -c "import secrets; print(secrets.token_urlsafe(32))"
API_KEY=din-hemliga-nyckel

# Tillåtna origins
ALLOWED_ORIGINS=https://sambandscentralen.se

# Databas och backup
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
- `location` - Plats
- `type` - Händelsetyp
- `date` - Datum (YYYY, YYYY-MM, YYYY-MM-DD)
- `from` - Från datum
- `to` - Till datum
- `limit` - Max antal (1-1000)
- `offset` - Hoppa över N
- `sort` - `desc` eller `asc`

### GET /api/events/raw

Samma som ovan, men returnerar endast array (kompatibelt med Polisens API).

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

Statistik, valfritt filtrerat på plats.

```bash
curl -H "X-API-Key: KEY" "http://localhost:8000/api/stats?location=Uppsala"
```

### GET /api/database

Databasinfo: antal händelser, storlek, senaste backup.

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
    ├── events_backup_20260109_030000.db
    └── ...
```

- **Händelser**: Sparas för evigt, ingen automatisk radering
- **Loggar**: Rensas efter 30 dagar
- **Backups**: Behålls 30 dagar, sedan automatisk rensning

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
# Kontrollera loggar
sudo journalctl -u samband-api -n 50

# Kontrollera port
sudo lsof -i :8000
```

### Databasproblem
```bash
# Kontrollera integritet
sqlite3 data/events.db "PRAGMA integrity_check;"

# Återställ från backup
cp data/backups/events_backup_LATEST.db data/events.db
```

### Hög minnesanvändning
```bash
# Komprimera databas
sqlite3 data/events.db "VACUUM;"
```

## Licens

MIT
