# UpTime-Server build. Release builds are reproducible: same commit + same Go version
# (see GO_IMAGE) => byte-identical binaries => same SHA-256 as published in SHA256SUMS.

MODULE      := github.com/LtAkmal/UpTime-Server
VERSION     ?= $(shell cat VERSION)
COMMIT      ?= $(shell git rev-parse HEAD 2>/dev/null || echo unknown)
# Commit time (UTC), not the wall clock, so rebuilding later gives the same bytes.
BUILD_TIME  ?= $(shell TZ=UTC git log -1 --format=%cd --date=format-local:%Y-%m-%dT%H:%M:%SZ 2>/dev/null || echo unknown)
GO          ?= go
GO_IMAGE    ?= golang:1.27.1-bookworm
PLATFORMS   ?= linux/amd64 linux/arm64
DIST        := dist

LDFLAGS := -s -w -buildid= \
	-X $(MODULE)/internal/buildinfo.Version=$(VERSION) \
	-X $(MODULE)/internal/buildinfo.Commit=$(COMMIT) \
	-X $(MODULE)/internal/buildinfo.BuildTime=$(BUILD_TIME)
GOFLAGS_REPRO := -trimpath -buildvcs=false

.PHONY: all build test vet checksum release docker-release vectors collector-digest clean

all: test build

## build: agent and verifier for the current platform into dist/
build:
	@mkdir -p $(DIST)
	CGO_ENABLED=0 $(GO) build $(GOFLAGS_REPRO) -ldflags '$(LDFLAGS)' -o $(DIST)/uptime-agent ./cmd/uptime-agent
	CGO_ENABLED=0 $(GO) build $(GOFLAGS_REPRO) -ldflags '$(LDFLAGS)' -o $(DIST)/uptime-verify ./cmd/uptime-verify

## test: unit tests, vet and formatting
test: vet
	$(GO) test -count=1 ./...
	@test -z "$$(gofmt -l .)" || (echo "gofmt needed:"; gofmt -l .; exit 1)

vet:
	$(GO) vet ./...

## checksum: SHA256SUMS for everything in dist/
checksum:
	cd $(DIST) && find . -maxdepth 1 -type f ! -name SHA256SUMS -printf '%f\n' | LC_ALL=C sort | xargs sha256sum > SHA256SUMS
	@cat $(DIST)/SHA256SUMS

## release: static binaries for every platform plus SHA256SUMS
release:
	@test -z "$$(git status --porcelain 2>/dev/null)" || (echo "refusing to release a dirty tree"; exit 1)
	@rm -rf $(DIST) && mkdir -p $(DIST)
	@for p in $(PLATFORMS); do \
		os=$${p%/*}; arch=$${p#*/}; \
		for cmd in uptime-agent uptime-verify; do \
			echo "build $$cmd $$os/$$arch"; \
			CGO_ENABLED=0 GOOS=$$os GOARCH=$$arch $(GO) build $(GOFLAGS_REPRO) -ldflags '$(LDFLAGS)' -o $(DIST)/$$cmd-$(VERSION)-$$os-$$arch ./cmd/$$cmd || exit 1; \
		done; \
	done
	@$(MAKE) --no-print-directory checksum

## docker-release: the same release inside the pinned Go image (recommended for reproducibility)
docker-release:
	docker run --rm -v "$$PWD":/src -w /src -e HOME=/tmp $(GO_IMAGE) \
		sh -c 'git config --global --add safe.directory /src && make release GO=go'

## vectors: regenerate the cross-language test vectors used by the PHP collector tests
vectors:
	$(GO) test ./protocol -run TestVectors -update

## collector-digest: digest of collector/laravel/app/Uptime as reported by the collector
collector-digest:
	@scripts/collector-digest.sh collector/laravel/app/Uptime

clean:
	rm -rf $(DIST)
