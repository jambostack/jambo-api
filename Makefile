# =============================================================================
# Jambo API — PHPUnit Test Matrix (domain-segmented for ultra-fast runs)
# =============================================================================
# Usage:
#   make test-unit        → Entity/Enum/Twig/Service (no DB, ~5s)
#   make test-auth        → Auth controllers (~3s)
#   make test-admin       → Admin + AdminApi controllers (~10s)
#   make test-api         → API controllers (~15s)
#   make test-realtime    → Realtime/Mercure SSE security (~3s)
#   make test-hardening   → All Cycle*HardeningTest/SecurityHardeningTest (~60s)
#   make test-graphql     → GraphQL controllers
#   make test-events      → EventSubscriber tests
#   make test-command     → Command tests
#   make test-security    → tests/Security directory
#   make test-controller  → All controller tests (full)
#   make test-all         → Full suite
#   make test-fast        → unit + auth + realtime (< 15s, CI pre-push gate)
#   make test-ci          → Full suite, no coverage, one-shot
# =============================================================================

PHP        := php
PHPUNIT    := $(PHP) vendor/bin/phpunit
FLAGS      := --no-coverage

.PHONY: test-unit test-auth test-admin test-api test-settings test-realtime test-hardening \
        test-graphql test-events test-command test-security test-controller \
        test-all test-fast test-ci

## ── Domain suites ─────────────────────────────────────────────────────────────

test-unit:
	@echo "🧪 [unit] Entity / Enum / Twig / Service"
	$(PHPUNIT) --testsuite unit $(FLAGS)

test-auth:
	@echo "🔐 [auth] Authentication controllers"
	$(PHPUNIT) --testsuite auth $(FLAGS)

test-admin:
	@echo "🛡️  [admin] Admin + AdminApi controllers"
	$(PHPUNIT) --testsuite admin $(FLAGS)

test-api:
	@echo "🌐 [api] API controllers"
	$(PHPUNIT) --testsuite api $(FLAGS)

test-settings:
	@echo "⚙️  [settings] Settings controllers"
	$(PHPUNIT) --testsuite settings $(FLAGS)

test-realtime:
	@echo "⚡ [realtime] Realtime / Mercure SSE security"
	$(PHPUNIT) --testsuite realtime $(FLAGS)

test-hardening:
	@echo "🔒 [hardening] Cycle* security hardening tests"
	$(PHPUNIT) --testsuite controller --filter 'Cycle.*HardeningTest|Cycle.*SecurityHardeningTest' $(FLAGS)

test-graphql:
	@echo "📊 [graphql] GraphQL controller tests"
	$(PHPUNIT) --testsuite graphql $(FLAGS)

test-events:
	@echo "📡 [events] EventSubscriber tests"
	$(PHPUNIT) --testsuite events $(FLAGS)

test-command:
	@echo "⌨️  [command] Command tests"
	$(PHPUNIT) --testsuite command $(FLAGS)

test-security:
	@echo "🛡️  [security] Security tests"
	$(PHPUNIT) --testsuite security $(FLAGS)

test-controller:
	@echo "🧩 [controller] All controller tests"
	$(PHPUNIT) --testsuite controller $(FLAGS)

## ── Composite targets ─────────────────────────────────────────────────────────

test-fast:
	@echo "⚡ [fast] unit + auth + realtime (< 15s gate)"
	$(PHPUNIT) --testsuite unit $(FLAGS) && \
	$(PHPUNIT) --testsuite auth $(FLAGS) && \
	$(PHPUNIT) --testsuite realtime $(FLAGS)

test-all:
	@echo "🚀 [all] Full PHPUnit suite"
	$(PHPUNIT) $(FLAGS)

test-ci:
	@echo "🤖 [ci] Full suite (CI mode)"
	$(PHPUNIT) $(FLAGS) --order-by=random

## ── Translation parity check ──────────────────────────────────────────────────

test-i18n:
	@echo "🌍 [i18n] Translation parity"
	$(PHPUNIT) --filter testTranslationParityAcrossAllLocales $(FLAGS)
