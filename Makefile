COMPOSE_FILE := docker-compose.dev.yml
DC := docker compose -f $(COMPOSE_FILE)

.PHONY: up down restart logs status ps clean

## Start all services
up:
	$(DC) up -d --build

## Stop all services
down:
	$(DC) down

## Restart all services
restart: down up

## Tail logs for all services
logs:
	$(DC) logs -f

## Show service status
status:
	$(DC) ps

## Alias for status
ps: status

## Remove all containers, volumes, and images
clean:
	$(DC) down -v --rmi local

## Wait for all services to be healthy
wait:
	$(DC) up -d --wait

## Run MySQL CLI
mysql:
	$(DC) exec mysql mysql -u webcalendar -pwebcalendar_dev webcalendar

## Show MySQL tables
tables:
	$(DC) exec mysql mysql -u webcalendar -pwebcalendar_dev webcalendar -e "SHOW TABLES;"

## Run a command in the PHP-FPM container
php-exec:
	$(DC) exec php-fpm $(CMD)

## Show help
help:
	@echo "WCTNG Development Commands:"
	@echo "  make up        - Start all services"
	@echo "  make down      - Stop all services"
	@echo "  make restart   - Restart all services"
	@echo "  make logs      - Tail all service logs"
	@echo "  make status    - Show service status"
	@echo "  make clean     - Remove containers, volumes, images"
	@echo "  make wait      - Start and wait for healthy"
	@echo "  make mysql     - Open MySQL CLI"
	@echo "  make tables    - List database tables"
	@echo ""
	@echo "Ports:"
	@echo "  nginx:  $${PORT_NGINX:-47180}"
	@echo "  MySQL:  $${PORT_MYSQL:-47106}"
	@echo "  Vite:   $${PORT_VITE:-47173}"
