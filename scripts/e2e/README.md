# E2E Tests

End-to-end test suite for Pokemon Plugin admin API.

## Usage

```bash
python scripts/e2e/run.py
```

Requires Docker/Podman containers `tsdm-app` and `tsdm-db` to be running.

## Test Coverage

Currently ~42 tests covering:
- All 10 admin entities (pokemon, items, maps, evolutions, skills, config, users, etc.)
- CRUD operations: count, list, get, set, filter
- Special operations: pokemon_info, item_info per-user queries
- Global config read/write

## Adding Tests

Add new test cases in `run.py` using the `test()` and `run_api()` helpers:

```python
test("label", run_api("count::entity"), expect_count=42)
test("label", run_api("list::entity", {"from":"0","count":"10"}))
```
