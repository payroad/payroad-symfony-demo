.PHONY: build up down install migrate assets shell logs

build:
	docker compose build

up: build
	docker compose up -d

down:
	docker compose down

install: up
	docker compose exec app sh -c "composer install --no-interaction --no-progress"
	docker compose exec app sh -c "npm install && npm run build"

migrate: up
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

assets:
	docker compose exec app npm run watch

shell:
	docker compose exec app sh

logs:
	docker compose logs -f app
