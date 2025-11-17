#!/bin/bash

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo -e "${BLUE}     EMAIL MIGRATION QUEUE WORKER MANAGER        ${NC}"
echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo ""

case "$1" in
    start)
        WORKERS=${2:-10}
        echo -e "${GREEN}Starting ${WORKERS} queue workers...${NC}"
        for i in $(seq 1 $WORKERS); do
            docker-compose exec -d app php artisan queue:work \
                --queue=email-migration \
                --sleep=3 \
                --tries=3 \
                --timeout=300 \
                --memory=256 \
                --max-jobs=1000 &
            echo -e "  ✓ Worker $i started"
        done
        echo -e "${GREEN}All workers started successfully!${NC}"
        ;;

    stop)
        echo -e "${YELLOW}Stopping all queue workers...${NC}"
        docker-compose exec app php artisan queue:restart
        docker-compose exec app pkill -f "queue:work"
        echo -e "${GREEN}All workers stopped.${NC}"
        ;;

    status)
        echo -e "${BLUE}Checking worker status...${NC}"
        echo ""
        docker-compose exec app ps aux | grep -E "queue:work|PID" | grep -v grep
        ;;

    restart)
        echo -e "${YELLOW}Restarting queue workers...${NC}"
        $0 stop
        sleep 2
        $0 start $2
        ;;

    monitor)
        echo -e "${BLUE}Starting real-time monitoring...${NC}"
        echo -e "${YELLOW}Press Ctrl+C to stop${NC}"
        echo ""
        docker-compose exec app php artisan emails:queue-status --watch
        ;;

    clear-failed)
        echo -e "${YELLOW}Clearing failed jobs...${NC}"
        docker-compose exec app php artisan queue:flush
        echo -e "${GREEN}Failed jobs cleared.${NC}"
        ;;

    retry-failed)
        echo -e "${YELLOW}Retrying all failed jobs...${NC}"
        docker-compose exec app php artisan queue:retry all
        echo -e "${GREEN}Failed jobs re-queued.${NC}"
        ;;

    *)
        echo "Usage: ./queue-workers.sh {command} [options]"
        echo ""
        echo "Commands:"
        echo "  start [n]      - Start n workers (default: 10)"
        echo "  stop           - Stop all workers"
        echo "  status         - Show worker processes"
        echo "  restart [n]    - Restart with n workers"
        echo "  monitor        - Real-time queue monitoring"
        echo "  clear-failed   - Clear all failed jobs"
        echo "  retry-failed   - Retry all failed jobs"
        echo ""
        echo "Examples:"
        echo "  ./queue-workers.sh start 20    # Start 20 workers"
        echo "  ./queue-workers.sh monitor     # Watch real-time status"
        exit 1
        ;;
esac
