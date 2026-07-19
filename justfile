# TSDM Pokemon Plugin for Discuz X5 — build recipes.
# Gitmoji commit convention: <emoji> <Capitalized English summary.>

set shell := ["bash", "-c"]
set windows-shell := ["bash.exe", "-c"]
set unstable
set lists

default:
    @just --list

# ── Bootstrap ────────────────────────────────────────────────

# Install celestia-devtools (commit-msg hook + format-markdown).
install:
    pip install "git+https://github.com/celestia-island/celestia-devtools.git"
    celestia-devtools init --no-hooks
    @echo "celestia-devtools installed. Run 'just commit-msg-hook-install' to enable commit lint."

# Install the gitmoji commit-msg hook locally.
commit-msg-hook-install *ARGS='':
    celestia-devtools hook install {{ARGS}}

# ── Formatting ───────────────────────────────────────────────

# Format Markdown docs.
markdown-fmt *ARGS='':
    celestia-devtools format-markdown . {{ARGS}}

# Check Markdown formatting (CI mode).
markdown-fmt-check:
    celestia-devtools format-markdown . --check

# Full format: Markdown + just syntax check.
fmt: markdown-fmt
    just --evaluate _devtools > /dev/null

# CI format check.
fmt-check: markdown-fmt-check

# ── Rust / WASM ──────────────────────────────────────────────

# Build all Rust crates (debug).
build *ARGS='':
    cargo build {{ARGS}}

# Build all Rust crates (release, WASM-optimized).
build-release *ARGS='':
    cargo build --release {{ARGS}}

# Run Rust tests.
test *ARGS='':
    cargo test {{ARGS}}

# Lint with clippy.
lint:
    cargo clippy --workspace --all-targets -- -D warnings

# ── Commit message ───────────────────────────────────────────

# Validate a commit message against the gitmoji convention.
# Usage: just commit-msg-lint .git/COMMIT_EDITMSG
commit-msg-lint FILE:
    celestia-devtools commit-msg-lint check {{FILE}}

# ── Docker ───────────────────────────────────────────────────

# Build the Docker image.
docker-build:
    docker build -f docker/Dockerfile -t tsdm-x5-pokemon-plugin .

# Run with docker-compose.
docker-up:
    docker compose -f docker/docker-compose.yml up -d

docker-down:
    docker compose -f docker/docker-compose.yml down

# ── Cache ────────────────────────────────────────────────────

# Guard against excessive target/ growth.
cache-guard *ARGS='':
    celestia-devtools cache-guard . {{ARGS}}

# Clean incremental compilation artifacts.
clean-incremental:
    celestia-devtools cache-guard . --clean-incremental

# Full clean.
clean:
    cargo clean
    rm -rf rust/*/dist/
