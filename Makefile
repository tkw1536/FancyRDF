.PHONY: all test lint fmt parallel-lint phpcs phpstan phpunit-assertions phpunit-noassertions phpcbf

all: lint test

lint: parallel-lint phpcs phpstan

parallel-lint:
	@echo "=> vendor/bin/parallel-lint"
	@vendor/bin/parallel-lint src tests

phpcs:
	@echo "=> vendor/bin/phpcs"
	@vendor/bin/phpcs

phpstan:
	@echo "=> vendor/bin/phpstan"
	@vendor/bin/phpstan analyse --memory-limit=1G -v

fmt: phpcbf

phpcbf:
	@echo "=> vendor/bin/phpcbf"
	@vendor/bin/phpcbf

test: phpunit-assertions phpunit-noassertions

phpunit-assertions:
	@echo "=> vendor/bin/phpunit (with assertions)"
	@php -dzend.assertions=1 vendor/bin/phpunit --display-deprecations --display-warnings 

phpunit-noassertions:
	@echo "=> vendor/bin/phpunit (without assertions)"
	@php -dzend.assertions=0 vendor/bin/phpunit --display-deprecations --display-warnings