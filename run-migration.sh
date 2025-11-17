#!/bin/bash

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

clear

echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║          EMAIL TO S3 MIGRATION - COMPLETE RUNNER           ║${NC}"
echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
echo ""

# Function to check if containers are running
check_containers() {
    echo -e "${YELLOW}Checking Docker containers...${NC}"
    if ! docker-compose ps | grep -q "Up"; then
        echo -e "${RED}Error: Docker containers are not running!${NC}"
        echo -e "${YELLOW}Starting containers...${NC}"
        docker-compose up -d
        sleep 5
    fi
    echo -e "${GREEN}✓ Containers are running${NC}"
}

# Function to run migrations
run_migrations() {
    echo -e "${YELLOW}Running database migrations...${NC}"
    docker-compose exec app php artisan migrate --force
    echo -e "${GREEN}✓ Migrations completed${NC}"
}

# Function to dispatch jobs
dispatch_jobs() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}STEP 1: DISPATCHING MIGRATION JOBS TO QUEUE${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo ""

    docker-compose exec app php artisan emails:migrate-to-s3

    echo ""
    echo -e "${GREEN}✓ Jobs dispatched successfully${NC}"
}

# Function to start workers
start_workers() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}STEP 2: STARTING QUEUE WORKERS${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo ""

    read -p "How many concurrent workers do you want to run? (default: 10): " WORKERS
    WORKERS=${WORKERS:-10}

    ./queue-workers.sh start $WORKERS

    echo ""
    echo -e "${GREEN}✓ ${WORKERS} workers started${NC}"
}

# Function to monitor progress
monitor_progress() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}STEP 3: MONITORING MIGRATION PROGRESS${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo ""

    echo -e "${YELLOW}Opening real-time monitor...${NC}"
    echo -e "${YELLOW}Press Ctrl+C to stop monitoring${NC}"
    echo ""

    sleep 2
    docker-compose exec app php artisan emails:queue-status --watch
}

# Main execution
echo -e "${MAGENTA}Starting complete migration process...${NC}"
echo ""

# Step 1: Check containers
check_containers

# Step 2: Run migrations
run_migrations

echo ""
echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║                    MIGRATION OPTIONS                        ║${NC}"
echo -e "${CYAN}╚═══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo "1) Run complete migration (dispatch + workers + monitor)"
echo "2) Only dispatch jobs to queue"
echo "3) Only start workers"
echo "4) Only monitor progress"
echo "5) Check current status"
echo "0) Exit"
echo ""
read -p "Select option: " OPTION

case $OPTION in
    1)
        dispatch_jobs
        start_workers
        monitor_progress
        ;;
    2)
        dispatch_jobs
        echo ""
        echo -e "${YELLOW}Note: Jobs are now in queue. Run workers to process them!${NC}"
        echo -e "${YELLOW}Command: ./queue-workers.sh start 10${NC}"
        ;;
    3)
        start_workers
        echo ""
        echo -e "${YELLOW}Workers started. Monitor with: ./queue-workers.sh monitor${NC}"
        ;;
    4)
        monitor_progress
        ;;
    5)
        docker-compose exec app php artisan emails:queue-status
        ;;
    0)
        echo -e "${GREEN}Exiting...${NC}"
        exit 0
        ;;
    *)
        echo -e "${RED}Invalid option!${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}                    PROCESS COMPLETE                     ${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${CYAN}Useful commands:${NC}"
echo "  ./queue-workers.sh status       - Check worker processes"
echo "  ./queue-workers.sh monitor      - Real-time monitoring"
echo "  ./queue-workers.sh retry-failed - Retry failed jobs"
echo "  docker-compose exec app php artisan emails:queue-status - Quick status check"
