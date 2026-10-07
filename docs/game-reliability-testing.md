# Game reliability and interface tests

## Behavior covered

| Area | Expected behavior | Verification |
|------|-------------------|--------------|
| Battle reconnect | An interrupted action retains its request ID; recovery reads the current battle; retry replays a committed result without repeating it. | PHP replay tests, MariaDB concurrency, Chromium lost-response scenarios |
| Database transactions | Money, inventory, HP, PP, events and action receipts commit or roll back together. | Independent PHP CGI processes against MariaDB |
| PHP/Rust contracts | Real PHP responses deserialize into the Rust models used by the game. | Generated fixtures decoded by the Rust integration test |
| Battle reports | Players can open persisted turns and copy the existing BBCode report. | PHP event grouping and Chromium report/retry/clipboard scenarios |
| Inventory and storage | Item search runs before pagination; storage filters and ordering apply to the whole collection; hidden selections cannot be released. | PHP search tests, Rust filter tests, Chromium collections over 60 entries |
| Touch and keyboard items | Opening an item displays its details; only explicit confirmation consumes it; pending actions prevent double submission. | Chromium touch, Enter/Space and pending-request checks |

The server protocol, compatibility behavior and retention rules are described in
[Battle request replay](battle-request-replay.md).

## Fast checks

```sh
python scripts/test/run.py
cargo fmt --all -- --check
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
cargo clippy -p _game --lib --target wasm32-unknown-unknown -- -D warnings
```

## Real MariaDB and contract checks

Requirements: MariaDB 10.11, PHP 8.3 or later with `mysqli`, PHP CGI, and Rust.
Use a disposable local database server. The configured database user needs
permission to create and drop databases. Each run generates its own
`tsdm_test_<random>` database, creates the real plugin schema, and removes only
that database in its cleanup block. No existing Discuz database is selected.

```sh
export TSDM_DB_HOST=127.0.0.1
export TSDM_DB_PORT=3306
export TSDM_DB_USER=root
export TSDM_DB_PASSWORD='<your-local-test-password>'
export TSDM_PHP_CGI=php-cgi
mkdir -p target
export TSDM_API_CONTRACT_FIXTURES="$PWD/target/api-contract-fixtures.json"
php scripts/test/live_database.php
cargo test -p _utils --test php_api_contract --locked -- --ignored
```

On Windows, set the same environment variables in PowerShell, point
`TSDM_PHP_CGI` to `php-cgi.exe`, and pass `-c <test-php.ini>` to PHP if needed.
Workers inherit that configuration. The test uses the real API dispatcher,
request parsing, response hooks and endpoint SQL. A small Discuz boundary supplies
the fixture account and forwards database calls to `mysqli`; it does not test
forum authentication or the Discuz SQL safety parser.

The parent holds the account row lock until both worker connections reach their
own lock attempt. This checks actual database serialization. An injected failure
on receipt insertion verifies that preceding gameplay writes also roll back.
The CI job repeats these checks with an isolated MariaDB service and passes the
fresh PHP response file directly to Rust. The contract test is explicitly ignored
in the ordinary Rust suite because it requires those generated responses.

## Browser checks

See [Game browser regressions](../scripts/browser/README.md). These tests load the
actual compiled game WASM with an isolated HTTP fixture server. They cover UI
behavior; the MariaDB suite separately covers server transaction behavior.

The repository's complete Sass build requires the maintainer's ignored
`styles-core/variables` dependency. When it is unavailable, preserve the shipped
base CSS and compile only added rules that do not depend on those private
variables. Do not replace the shipped CSS with Sass's generated error page.
