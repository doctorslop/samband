# Samband API

Backend-API för att samla och servera polishändelser med historik.

## Funktioner

- Samlar händelser från Polisens API var 5:e minut
- Sparar all data lokalt för historik (1+ år)
- Samma dataformat som Polisens API
- Utökad filtrering (datumintervall, paginering)
- API-nyckel-autentisering
- Rate limiting

## Krav

- Python 3.11+
- pip

## Installation

```bash
# Klona repo
git clone https://github.com/doctorslop/samband-api.git
cd samband-api

# Skapa virtuell miljö
python -m venv venv
source venv/bin/activate  # Linux/Mac
# eller: venv\Scripts\activate  # Windows

# Installera beroenden
pip install -r requirements.txt

# Kopiera och konfigurera
cp .env.example .env
nano .env  # Ändra API_KEY och ALLOWED_ORIGINS
```

## Konfiguration (.env)

```env
# Generera en stark nyckel: python -c "import secrets; print(secrets.token_urlsafe(32))"
API_KEY=din-hemliga-nyckel

# Tillåtna origins (din frontend)
ALLOWED_ORIGINS=https://sambandscentralen.se

# Övriga inställningar (standardvärden fungerar)
DATABASE_PATH=./data/events.db
FETCH_INTERVAL_MINUTES=5
RATE_LIMIT_PER_MINUTE=60
```

## Körning

### Utveckling

```bash
uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
```

### Produktion

```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 2
```

### Med systemd (rekommenderat)

```ini
# /etc/systemd/system/samband-api.service
[Unit]
Description=Samband API
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/samband-api
Environment="PATH=/opt/samband-api/venv/bin"
ExecStart=/opt/samband-api/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable samband-api
sudo systemctl start samband-api
```

## API-endpoints

Alla endpoints kräver `X-API-Key` header.

### GET /api/events

Hämta händelser (samma format som Polisen + metadata).

```bash
curl -H "X-API-Key: din-nyckel" "https://api.din-domain.se/api/events?location=Stockholm&from=2025-06-01&to=2025-06-30"
```

**Parametrar:**
- `location` - Filtrera på plats
- `type` - Filtrera på händelsetyp
- `date` - Filtrera på datum (YYYY, YYYY-MM, YYYY-MM-DD)
- `from` - Från datum (YYYY-MM-DD)
- `to` - Till datum (YYYY-MM-DD)
- `limit` - Max antal (1-1000, default 500)
- `offset` - Hoppa över N resultat
- `sort` - `desc` (nyast först) eller `asc`

**Svar:**
```json
{
  "events": [...],
  "total": 1234,
  "limit": 500,
  "offset": 0,
  "has_more": true
}
```

### GET /api/events/raw

Samma som ovan men returnerar endast array (exakt som Polisens API).

### GET /api/locations

Lista alla platser med antal händelser.

```json
[
  {"name": "Stockholm", "count": 4521},
  {"name": "Göteborg", "count": 2103}
]
```

### GET /api/types

Lista alla händelsetyper med antal.

### GET /api/stats

Statistik, valfritt per plats (`?location=Uppsala`).

```json
{
  "total": 847,
  "by_type": {"Trafikolycka": 203, "Stöld": 156},
  "by_month": {"2025-06": 89, "2025-05": 102},
  "date_range": {"oldest": "2025-01-01...", "latest": "2025-06-15..."}
}
```

### POST /api/fetch

Trigga manuell hämtning (max 6/minut).

### GET /health

Hälsokontroll (ingen auth krävs).

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

## Anrop från PHP (shared hosting)

```php
function fetchFromSambandAPI($endpoint, $params = []) {
    $baseUrl = 'https://api.din-domain.se';
    $apiKey = 'din-hemliga-nyckel';

    $url = $baseUrl . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $ctx = stream_context_create([
        'http' => [
            'header' => "X-API-Key: $apiKey",
            'timeout' => 10
        ]
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return null; // Fallback till Polisens API
    }

    return json_decode($response, true);
}

// Användning
$events = fetchFromSambandAPI('/api/events', [
    'location' => 'Uppsala',
    'from' => '2025-06-01',
    'to' => '2025-06-30'
]);

$locations = fetchFromSambandAPI('/api/locations');
```

## Backup

Databasen ligger i `./data/events.db`. Säkerhetskopiera regelbundet:

```bash
# Cron: daglig backup
0 3 * * * cp /opt/samband-api/data/events.db /backup/events-$(date +\%Y\%m\%d).db
```

## Licens

MIT
