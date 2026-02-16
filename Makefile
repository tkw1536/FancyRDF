.PHONY: all test lint fmt

all: lint test

lint:
	@echo "=> endor/bin/parallel-lint"
	@vendor/bin/parallel-lint src tests
	
	@echo "=> vendor/bin/phpcs"
	@vendor/bin/phpcs

	@echo "=> vendor/bin/phpstan"
	@vendor/bin/phpstan analyse

fmt:
	@echo "=> vendor/bin/phpcbf"
	@vendor/bin/phpcbf

test:
	@echo "=> vendor/bin/phpunit (with assertions)"
	@php -dzend.assertions=1 vendor/bin/phpunit --display-deprecations
	@echo "=> vendor/bin/phpunit (without assertions)"
	@php -dzend.assertions=0 vendor/bin/phpunit  --display-deprecations