# Admin browser regressions

These tests run the real Dioxus admin application in Chromium, with an isolated,
in-memory HTTP API fixture. They need no forum account or database. The PHP
dispatch and validation tests are run separately by `python scripts/test/run.py`;
the live Discuz suites remain in `scripts/e2e/`.

## Run

Build the current frontend sources first (install `wasm-bindgen-cli` at the
`wasm-bindgen` version recorded in `Cargo.lock`):

```sh
rustup target add wasm32-unknown-unknown
cargo build --locked --release --target wasm32-unknown-unknown -p _admin -p _game
wasm-bindgen --target web --out-dir plugin/wasm --out-name _admin target/wasm32-unknown-unknown/release/_admin.wasm
wasm-bindgen --target web --out-dir plugin/wasm --out-name _game target/wasm32-unknown-unknown/release/_game.wasm
npm ci --prefix scripts/browser --ignore-scripts
cd scripts/browser
npx playwright install chromium
npm test
```

Node.js 20 or newer is required. On Linux, use `npx playwright install --with-deps
chromium` to install browser system libraries as well. CI compiles both WASM
frontends and regenerates their JavaScript bindings before running the tests.

## Coverage

- WASM startup, effect creation and editing, list refresh and deletion.
- Failed effect and skill saves retain the form and allow retry.
- Skill effect bindings survive subsequent edits and a full browser reload.
- Failed effect-list loading preserves an existing skill binding.
- Explicit unbinding and server rejection of deleting a referenced effect.
- No unexpected API requests or uncaught browser errors.

Failures write a screenshot, Playwright trace and request log to the ignored
`scripts/browser/artifacts/` directory. CI uploads these diagnostics. The fixture
contains test data only; it never connects to a live forum.
