# Start dev environment
up:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml up -d --remove-orphans;
	@echo 'App is running on http://localhost';

# Start dev environment with forced build
up\:build:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml up -d --build;

# Stop dev environment
down:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml down;

# Show logs - format it using less
logs:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml logs -f --tail=10 | less -S +F;

# Exec sh on php container
exec\:php:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php sh;

# Exec sh on Node container
exec\:node:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node sh;

# Init project
init:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml run php composer install;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm install;
	chmod -R 777 log;
	chmod -R 777 temp;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:schema-tool:drop --force --full-database;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:schema-tool:create;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console doctrine:fixtures:load;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php vendor/bin/generator generate

.PHONY: init-mini
init-mini:
	make db
	make build
	make generate
	make clean-temp
	make clean-log
	make clean-tempMedia

#DB
db-drob:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:schema-tool:drop --force --full-database;

db-create:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:schema-tool:create;

db-load:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console doctrine:fixtures:load --no-interaction;
.PHONY: db
db:
	make db-drob
	make db-create
	make db-load

#Stylelint
.PHONY: stylelint
stylelint:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run stylelint

#Stylelint Fix
.PHONY: stylelint-fix
stylelint-fix:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run stylelint:fix

#ESLint
.PHONY: eslint
eslint:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run eslint

#ESLint Fix
.PHONY: eslint-fix
eslint-fix:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run eslint:fix

#Code Sniffer
.PHONY: cs
cs:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/phpcs --cache=./qa/phpcs/phpcs.cache --standard=./qa/phpcs/ruleset.xml --extensions=php --encoding=utf-8 --tab-width=4 -sp --colors app

#Code Sniffer Fix it
.PHONY: cs-fix
cs-fix:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/phpcbf --cache=./qa/phpcs/phpcs.cache --standard=./qa/phpcs/ruleset.xml --extensions=php --encoding=utf-8 --tab-width=4 -sp --colors app

#PHPSTAN
.PHONY: phpstan
phpstan:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/phpstan analyse --level 7 --ansi app tests

#DB-TESTS
.PHONY: db-tests
db-tests:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:validate-schema --skip-sync

code-checker:
	@echo "nette/code-checker není kompatibilní s nette/utils 4.x — přeskočeno"

code-checker-fix:
	@echo "nette/code-checker není kompatibilní s nette/utils 4.x — přeskočeno"

.PHONY: qa
qa:
	make stylelint
	make stylelint-fix
	make eslint
	make eslint-fix
	make cs
	make cs-fix
	make phpstan
	make tests
	make db-tests

# Migrations
diff:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console orm:schema-tool:drop --force --full-database;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console migrations:migrate --allow-no-migration --no-interaction;
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php bin/console migrations:diff;
	git add migrations/*
	make init

# Deployment
.PHONY: deploy-test
deploy-test:
	@echo $(env);
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php php deployment deployment-$(env).ini -t

.PHONY: deploy
deploy:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php php deployment deployment-$(env).ini
# Fix
.PHONY: fix
fix:
	make stylelint-fix
	make eslint-fix
	make cs-fix
	make code-checker-fix

.PHONY: build build-webpack build-criticalCss clean-temp

# build = webpack + critical-css
build: build-webpack build-criticalCss

# webpack only
build-webpack:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run build:webpack

# critical-css only (nejprve clean-temp)
build-criticalCss: clean-temp
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run build:criticalCss

serve:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec node npm run serve

watchdog:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/watchdog analyse --config=./qa/watchdog/watchdog.neon

generate:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php vendor/bin/generator generate

generate\:update:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php composer update clown/generator
	make generate


.PHONY: clean-all
clean-all:
	make clean-temp
	make clean-log
	make clean-tempMedia


.PHONY: clean-temp
clean-temp:
	echo "eec726b2" | sudo -S chown libormatejka temp/ -R
	rm -rf temp
	mkdir temp
	sudo chmod 777 temp/ -R
	@echo 'Temp directory ownership changed, directory has been removed and recreated with appropriate permissions.'

.PHONY: clean-log
clean-log:
	echo "eec726b2" | sudo -S chown libormatejka log/ -R
	rm -rf log
	mkdir log
	sudo chmod 777 log/ -R
	@echo 'Temp directory ownership changed, directory has been removed and recreated with appropriate permissions.'

.PHONY: clean-tempMedia
clean-tempMedia:
	echo "eec726b2" | sudo -S chown libormatejka www/media/temp/ -R
	rm -rf www/media/temp
	mkdir www/media/temp
	sudo chmod 777 www/media/temp -R
	@echo 'Temp directory ownership changed, directory has been removed and recreated with appropriate permissions.'

.PHONY: clean-media
clean-media:
	echo "eec726b2" | sudo -S chown libormatejka www/media/content/2024 -R
	rm -rf www/media/content/2024
	rm -rf www/media/content/2025
	mkdir www/media/content/2024
	mkdir www/media/content/2025
	sudo chmod 777 www/media/content/2024 -R
	sudo chmod 777 www/media/content/2025 -R
	@echo 'Temp directory ownership changed, directory has been removed and recreated with appropriate permissions.'

# Unit testy pro entity
.PHONY: tests-entity
tests-entity:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/phpunit tests/Unit/Model/Database/Entity --colors --testdox

#PHP TESTS
.PHONY: tests-integrate
tests-integrate:
	docker-compose --project-name collectorboycz -f .docker/docker-compose.yml exec php ./vendor/bin/phpunit tests --colors --testdox

# Spuštění všech testů (entity + integrační testy)
.PHONY: tests
tests: tests-entity tests-integrate
