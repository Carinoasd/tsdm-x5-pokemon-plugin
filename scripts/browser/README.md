# Game browser regressions

These tests load the compiled game WASM in Chromium. An isolated local HTTP server
provides API fixtures; no forum account or production data is used. PHP and real
database behavior are tested separately.

Build the game WASM and CSS before running:

```sh
cd scripts/browser
npm ci
npx playwright install chromium
npm test
```

Use `npm run test:battle` or `npm run test:inventory` to run one suite.

The battle suite covers touch and keyboard item details, PP selection, pending
request guards, lost-response retries with the same request ID, revision
conflicts, expired login, battles ended in another tab, inventory timeouts,
delayed party loading, and battle report loading and clipboard sharing.
The inventory suite covers item search and warehouse filtering, sorting, and
selection across larger collections.

Screenshots and Playwright traces are written to the ignored `artifacts/`
directory. Set `PLAYWRIGHT_BROWSERS_PATH` if Chromium is installed in a custom
location, or `GAME_WASM_DIRECTORY` to test a different game bundle.
