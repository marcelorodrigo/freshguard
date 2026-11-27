# FreshGuard — one binary, one database file, one SPA.
#
# docs/REWRITE.md is the contract; docs/architecture.md explains the pieces.
# `make check` is the only gate CI runs.

SHELL := /bin/sh

GO      ?= go
PNPM    ?= pnpm
BIN     := bin/freshguard
VERSION := $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -X main.version=$(VERSION)

STATICCHECK := $(shell command -v staticcheck 2>/dev/null || echo "$$($(GO) env GOPATH)/bin/staticcheck")

DEV_ADDR := 127.0.0.1:8080

SIZE_BUDGET_BYTES := 30000000
RSS_BUDGET_KB      := 51200
COLD_BUDGET_MS     := 200

.PHONY: dev web server test check gotest web-test web-check fmt fmt-check vet staticcheck size rss cold clean

dev: web/node_modules
	@trap 'kill 0' EXIT INT TERM; \
		FRESHGUARD_ADDR=$(DEV_ADDR) FRESHGUARD_DATA_DIR=./data-dev $(GO) run ./cmd/freshguard & \
		( cd web && $(PNPM) run dev ) & \
		wait

web: web/node_modules
	cd web && $(PNPM) run build
	@touch internal/web/dist/.gitkeep

web/node_modules: web/package.json web/pnpm-lock.yaml
	cd web && $(PNPM) install --frozen-lockfile

server:
	CGO_ENABLED=0 $(GO) build -trimpath -ldflags="$(LDFLAGS)" -o $(BIN) ./cmd/freshguard

gotest:
	$(GO) test ./...

web-test: web/node_modules
	cd web && $(PNPM) run test

test: gotest web-test

web-check: web/node_modules
	cd web && $(PNPM) run type-check && $(PNPM) run lint && $(PNPM) run test

check: fmt-check vet staticcheck gotest web-check

fmt:
	gofmt -w cmd internal

fmt-check:
	@unformatted=$$(gofmt -l cmd internal); \
	if [ -n "$$unformatted" ]; then \
		echo "gofmt: formatting needed, run make fmt:"; \
		echo "$$unformatted"; \
		exit 1; \
	fi

vet:
	$(GO) vet ./...

staticcheck:
	@test -x "$(STATICCHECK)" || { \
		echo "staticcheck not found: go install honnef.co/go/tools/cmd/staticcheck@latest"; \
		exit 1; \
	}
	$(STATICCHECK) ./...

size: server
	@bytes=$$(wc -c < $(BIN) | tr -d ' '); \
	mb=$$(awk -v b="$$bytes" 'BEGIN { printf "%.2f", b / 1000000 }'); \
	echo "size: $$bytes bytes ($$mb MB), budget $(SIZE_BUDGET_BYTES) bytes"; \
	awk -v b="$$bytes" -v max="$(SIZE_BUDGET_BYTES)" 'BEGIN { exit (b > max) }' || { \
		echo "size: over budget"; \
		exit 1; \
	}

rss: server
	@port=18080; \
	tmp=$$(mktemp -d); \
	FRESHGUARD_ADDR=127.0.0.1:$$port FRESHGUARD_DATA_DIR="$$tmp" ./$(BIN) serve >/dev/null 2>&1 & \
	pid=$$!; \
	trap 'kill $$pid 2>/dev/null; rm -rf "$$tmp"' EXIT INT TERM; \
	i=0; \
	until curl -fsS "http://127.0.0.1:$$port/api/v1/health" >/dev/null 2>&1; do \
		i=$$((i + 1)); \
		if [ $$i -gt 200 ]; then echo "rss: server did not start"; exit 1; fi; \
		sleep 0.05; \
	done; \
	kb=$$(ps -o rss= -p $$pid | tr -d ' '); \
	echo "rss: $$kb KiB, budget $(RSS_BUDGET_KB) KiB"; \
	if [ "$$kb" -gt "$(RSS_BUDGET_KB)" ]; then echo "rss: over budget"; exit 1; fi

cold: server
	@port=18081; \
	tmp=$$(mktemp -d); \
	ms() { perl -MTime::HiRes=time -e 'printf "%.0f", time() * 1000'; }; \
	start=$$(ms); \
	FRESHGUARD_ADDR=127.0.0.1:$$port FRESHGUARD_DATA_DIR="$$tmp" ./$(BIN) serve >/dev/null 2>&1 & \
	pid=$$!; \
	trap 'kill $$pid 2>/dev/null; rm -rf "$$tmp"' EXIT INT TERM; \
	until curl -fsS -o /dev/null "http://127.0.0.1:$$port/api/v1/health" 2>/dev/null; do \
		sleep 0.005; \
	done; \
	end=$$(ms); \
	took=$$((end - start)); \
	echo "cold: $${took} ms to the first health response, budget $(COLD_BUDGET_MS) ms"; \
	if [ $$took -gt "$(COLD_BUDGET_MS)" ]; then echo "cold: over budget"; exit 1; fi

clean:
	rm -rf bin data data-dev
	rm -rf internal/web/dist
	@mkdir -p internal/web/dist
	@touch internal/web/dist/.gitkeep
