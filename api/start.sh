#!/bin/bash
#
# Samband API Startup Script
# Usage: ./start.sh [dev|prod]
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Samband API${NC}"
echo "============"

# Check Python
if ! command -v python3 &> /dev/null; then
    echo -e "${RED}Error: Python 3 is required${NC}"
    exit 1
fi

# Check/create virtual environment
if [ ! -d "venv" ]; then
    echo -e "${YELLOW}Creating virtual environment...${NC}"
    python3 -m venv venv
fi

# Activate virtual environment
source venv/bin/activate

# Install/update dependencies
echo -e "${YELLOW}Installing dependencies...${NC}"
pip install -q -r requirements.txt

# Check .env file
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}Creating .env from example...${NC}"
    cp .env.example .env

    # Generate API key
    API_KEY=$(python3 -c "import secrets; print(secrets.token_urlsafe(32))")
    sed -i "s/your-secret-api-key-here/$API_KEY/" .env

    echo -e "${GREEN}Generated API key: $API_KEY${NC}"
    echo -e "${YELLOW}Please update ALLOWED_ORIGINS in .env${NC}"
fi

# Create data directories
mkdir -p data/backups

# Parse arguments
MODE="${1:-prod}"

if [ "$MODE" = "dev" ]; then
    echo -e "${YELLOW}Starting in development mode...${NC}"
    export ENVIRONMENT=development
    uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
else
    echo -e "${GREEN}Starting in production mode...${NC}"
    export ENVIRONMENT=production
    uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 2
fi
