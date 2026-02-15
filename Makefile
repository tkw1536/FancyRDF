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
	@echo "=> vendor/bin/phpunit"
	@vendor/bin/phpunit