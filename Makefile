COMPOSE_FILE := docker-compose.dev.yml
DC := docker compose -f $(COMPOSE_FILE)
TEST_DATABASE_URL := mysql://webcalendar:webcalendar_dev@mysql:3306/webcalendar_test

.PHONY: up down restart logs status ps clean test-db test

## Start all services
## var/ is gitignored and the container runs as www-data, which cannot create it
## inside the bind-mounted checkout — without this a fresh clone 500s on every
## request with "Unable to create the cache directory".
up:
	mkdir -p webcalendar-api/var/cache webcalendar-api/var/log
	chmod -R 777 webcalendar-api/var
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

## Provision the functional-test database: core schema, API migrations, admin
## fixture. Mirrors the CI steps in .github/workflows/api.yml. The functional
## suite logs in as admin/admin, and the schema seeds no users, so a database
## that has not been through this will 401 on nearly every test.
test-db:
	$(DC) exec -T mysql mysql -u root -p$${MYSQL_ROOT_PASSWORD:-wctng_root_secret} \
		-e "CREATE DATABASE IF NOT EXISTS webcalendar_test CHARACTER SET utf8mb4; \
		    GRANT ALL PRIVILEGES ON webcalendar_test.* TO 'webcalendar'@'%'; FLUSH PRIVILEGES;"
	$(DC) exec -T mysql sh -c 'mysql -u root -p$${MYSQL_ROOT_PASSWORD:-wctng_root_secret} webcalendar_test' \
		< webcalendar-api/vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/mysql-schema.sql
	$(DC) exec -T -e DATABASE_URL=$(TEST_DATABASE_URL) php-fpm php bin/console migrations:migrate --no-interaction
	$(DC) exec -T -e DATABASE_URL=$(TEST_DATABASE_URL) php-fpm php bin/console webcalendar:install --force --admin-password=admin

## Run the API test suite against the test database
test:
	$(DC) exec -T -e DATABASE_URL=$(TEST_DATABASE_URL) php-fpm ./vendor/bin/phpunit

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
	@echo "  make test-db   - Provision the functional-test database"
	@echo "  make test      - Run the API test suite against it"
	@echo ""
	@echo "Ports:"
	@echo "  nginx:  $${PORT_NGINX:-47180}"
	@echo "  MySQL:  $${PORT_MYSQL:-47106}"
	@echo "  Vite:   $${PORT_VITE:-47173}"
