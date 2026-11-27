# FreshGuard — one binary, one database file, one SPA.
#
# docs/REWRITE.md is the contract; docs/architecture.md explains the pieces.
# `make check` is the only gate CI runs.

SHELL := /bin/sh

GO      ?= go
PNPM    ?= pnpm

# The Laravel vendor/ tree (deleted in step 10) looks like a Go vendor
# directory. -mod=readonly ignores it and refuses to rewrite go.mod.
export GOFLAGS ?= -mod=readonly

BIN     := bin/freshguard
VERSION := $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -X main.version=$(VERSION)

DEV_ADDR := 127.0.0.1:8080

SIZE_BUDGET_BYTES := 30000000
RSS_BUDGET_KB      := 51200
COLD_BUDGET_MS     := 200

.PHONY: dev web server test check gotest web-test web-check cover fmt fmt-check vet staticcheck complexity size rss cold clean clean-data

dev: web/node_modules
	@set -m; \
		server=""; vite=""; \
		cleanup() { \
			trap - EXIT INT TERM; \
			[ -z "$$server" ] || kill -TERM -- -"$$server" 2>/dev/null || true; \
			[ -z "$$vite" ] || kill -TERM -- -"$$vite" 2>/dev/null || true; \
			wait; \
		}; \
		trap cleanup EXIT INT TERM; \
		FRESHGUARD_ADDR=$(DEV_ADDR) FRESHGUARD_DATA_DIR=./data-dev $(GO) run ./cmd/freshguard & server=$$!; \
		( cd web && $(PNPM) run dev ) & vite=$$!; \
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

cover: web/node_modules
	$(GO) test -covermode=atomic -coverprofile=coverage.out ./...
	cd web && $(PNPM) run test:coverage

web-check: web/node_modules
	cd web && $(PNPM) run type-check && $(PNPM) run lint && $(PNPM) run test

check: fmt-check vet staticcheck complexity gotest web-check

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
	$(GO) run honnef.co/go/tools/cmd/staticcheck ./...

# Sonar flags functions over 15; keep the gate local so review catches nothing
# the agent could have caught before the push. -test includes _test.go files.
complexity:
	$(GO) run github.com/uudashr/gocognit/cmd/gocognit -test -exact-recursion -over 15 cmd internal

size: server
	@bytes=$$(wc -c < $(BIN) | tr -d ' '); \
	mb=$$(awk -v b="$$bytes" 'BEGIN { printf "%.2f", b / 1000000 }'); \
	echo "size: $$bytes bytes ($$mb MB), budget $(SIZE_BUDGET_BYTES) bytes"; \
	awk -v b="$$bytes" -v max="$(SIZE_BUDGET_BYTES)" 'BEGIN { exit (b > max) }' || { \
		echo "size: over budget"; \
		exit 1; \
	}

rss: server
	@tmp=$$(mktemp -d); \
	log="$$tmp/server.log"; \
	FRESHGUARD_ADDR=127.0.0.1:0 FRESHGUARD_DATA_DIR="$$tmp/data" ./$(BIN) serve >"$$log" 2>&1 & \
	pid=$$!; \
	trap 'kill $$pid 2>/dev/null; rm -rf "$$tmp"' EXIT INT TERM; \
	deadline=$$(($$(date +%s) + 10)); \
	addr=""; \
	until [ -n "$$addr" ] && curl -fsS --max-time 1 "http://$$addr/api/v1/health" >/dev/null 2>&1; do \
		kill -0 $$pid 2>/dev/null || { echo "rss: server exited before answering"; cat "$$log"; exit 1; }; \
		if [ "$$(date +%s)" -ge "$$deadline" ]; then \
			echo "rss: server did not answer within 10 s"; cat "$$log"; exit 1; \
		fi; \
		addr=$$(sed -n 's/.*msg=listening addr=\([^ ]*\) .*/\1/p' "$$log" | head -1); \
		sleep 0.05; \
	done; \
	kb=$$(ps -o rss= -p $$pid | tr -d ' '); \
	case "$$kb" in ''|*[!0-9]*) echo "rss: unreadable RSS value '$$kb'"; exit 1;; esac; \
	[ "$$kb" -gt 0 ] || { echo "rss: RSS is zero"; exit 1; }; \
	echo "rss: $$kb KiB, budget $(RSS_BUDGET_KB) KiB"; \
	[ "$$kb" -le "$(RSS_BUDGET_KB)" ] || { echo "rss: over budget"; exit 1; }

cold: server
	@tmp=$$(mktemp -d); \
	log="$$tmp/server.log"; \
	ms() { perl -MTime::HiRes=time -e 'printf "%.0f", time() * 1000'; }; \
	start=$$(ms); \
	FRESHGUARD_ADDR=127.0.0.1:0 FRESHGUARD_DATA_DIR="$$tmp/data" ./$(BIN) serve >"$$log" 2>&1 & \
	pid=$$!; \
	trap 'kill $$pid 2>/dev/null; rm -rf "$$tmp"' EXIT INT TERM; \
	addr=""; \
	until [ -n "$$addr" ] && curl -fsS --max-time 1 -o /dev/null "http://$$addr/api/v1/health" 2>/dev/null; do \
		kill -0 $$pid 2>/dev/null || { echo "cold: server exited before answering"; cat "$$log"; exit 1; }; \
		took=$$(( $$(ms) - start )); \
		if [ $$took -gt "$(COLD_BUDGET_MS)" ]; then echo "cold: over budget after $${took} ms"; exit 1; fi; \
		addr=$$(sed -n 's/.*msg=listening addr=\([^ ]*\) .*/\1/p' "$$log" | head -1); \
		sleep 0.005; \
	done; \
	took=$$(( $$(ms) - start )); \
	echo "cold: $${took} ms to the first health response, budget $(COLD_BUDGET_MS) ms"; \
	if [ $$took -gt "$(COLD_BUDGET_MS)" ]; then echo "cold: over budget"; exit 1; fi

clean:
	rm -rf bin internal/web/dist coverage.out
	@mkdir -p internal/web/dist
	@touch internal/web/dist/.gitkeep

clean-data:
	@echo "removing local databases: data/ and data-dev/"
	rm -rf data data-dev
