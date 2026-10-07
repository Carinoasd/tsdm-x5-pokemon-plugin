# Game and admin browser regressions

These tests load the compiled game and admin WASM in Chromium. An isolated local HTTP server
provides API fixtures; no forum account or production data is used. PHP and real
database behavior are also covered by the optional live integration below.

Build both WASM frontends and their CSS before running:

```sh
cd scripts/browser
npm ci
npx playwright install chromium
npm test
```

Use `npm run test:battle`, `npm run test:inventory`, `npm run test:player`, or
`npm run test:admin` to run one suite.

The battle suite covers touch and keyboard item details, PP selection, pending
request guards, lost-response retries with the same request ID, revision
conflicts, expired login, battles ended in another tab, inventory timeouts,
delayed party loading, and battle report loading and clipboard sharing.
It also checks selecting duplicate-species Boss configurations after level sorting,
including configuration index zero and preserving the chosen Boss across a retry.
Hybrid maps keep both ordinary adventure and explicit Boss choices available.
The inventory suite covers item search and warehouse filtering, sorting, and
selection across larger collections.
The player suite covers duplicate purchases and initialization, pet selection,
rename failures, healing state, account load retries, delayed balance updates,
and changing item targets during loading without duplicate item use. It also checks
equipment selection across Pokemon, retrying equipment and skill reads, duplicate
skill mutations, and pet-list updates that outlive navigation or arrive out of order.
It checks keyboard navigation, badge visibility during delayed reads, notification
expiration after navigation, and completing equipment changes after leaving the page.
Equipment stacks already in use remain visible and cannot displace another item.
The admin suite checks saving maps, skills, and evolution rules, duplicate-create
guards, retaining rejected drafts for retry, and delayed user-data responses.
It verifies that changing users cannot show or save another user's items, that
closing a user clears pending confirmation dialogs, and that older metadata
responses cannot overwrite newer saved values.
Granting items also keeps the selected item and quantity after a rejected request,
blocks duplicate submissions, and waits for the updated inventory before unlocking.

Screenshots and Playwright traces are written to the ignored `artifacts/`
directory. Set `PLAYWRIGHT_BROWSERS_PATH` if Chromium is installed in a custom
location, `GAME_WASM_DIRECTORY` to test a different game bundle, or
`ADMIN_WASM_DIRECTORY` to test a different admin bundle.

## Live PHP and MariaDB integration

`live-player.cjs` sends every API request from the real game WASM to PHP CGI and
MariaDB. It checks purchasing medicine, finding it in the backpack, and using it
on an eligible Pokemon, then starting a battle, using a skill, reloading the battle,
and viewing its stored report.
Assertions also read the database to verify money, inventory, HP, PP, revisions, and
request receipts. Cosmetic images are placeholders; API responses are never mocked.

Use a disposable local MariaDB server and a database user allowed to create and
drop test databases. PHP CLI and CGI both need `mysqli`. From the repository root:

```sh
TSDM_DB_HOST=127.0.0.1 TSDM_DB_PORT=3306 TSDM_DB_USER=root \
  TSDM_DB_PASSWORD=test-password node scripts/browser/live-player.cjs
```

Set `TSDM_PHP_CLI`, `TSDM_PHP_CGI`, or `TSDM_PHP_INI` when PHP executables or their
configuration are outside the default paths. The runner creates a random
`tsdm_test_browser_*` database, checks its ownership marker before inspection or
deletion, and removes that database in `finally`, including after a failed test.
It supplies only the Discuz login boundary and minimal forum tables, so it does
not test the forum's actual login or deployment routing. The production formhash
wrapper is used, and PHP receives the header sent by the browser.

This suite is separate from `npm test`, which does not require MariaDB. Its request
transcript, screenshots, and trace are saved under `artifacts/live-player/`.
