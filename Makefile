.PHONY: test phpmd phpcs lint

test:
	@bash ./run-tests.sh

phpmd:
	@bash ./run-lint.sh phpmd

phpcs:
	@bash ./run-lint.sh phpcs

lint:
	@bash ./run-lint.sh lint
