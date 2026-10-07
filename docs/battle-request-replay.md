# Battle request replay

Battle mutations accept these optional JSON fields:

```json
{
  "request_id": "6d9e2b20-5a7f-4d52-8ae1-e0f6025b618c",
  "engine_battle_id": 42,
  "expected_revision": 3,
  "skill_id": 4
}
```

This applies to `start`, `turn`, `flee`, `capture`, `use_item`,
`use_item_on_skill`, `switch_pokemon`, and `replace_pokemon`.
The request ID contains 16–64 ASCII letters, digits, underscores, or hyphens.
Both numeric fields must be JSON integers. `start` uses `0` for both fields;
other actions use the `engine_battle_id` and `revision` from the latest scene.
Existing action parameters stay unchanged.

Keep the same ID and complete payload when retrying after a lost response.
A committed request returns its original success envelope, including timestamp,
without repeating damage, rewards, or resource consumption. A rolled back
request can execute on retry. Generate a new ID for each new player action.
Requests without an ID remain supported, but clients must not retry those
mutations automatically.

| HTTP status | `error_code` | Client behavior |
| --- | --- | --- |
| 400 | `invalid_battle_request` | Correct the malformed request. |
| 401 / 403 | Authentication or formhash failure | Keep an unconfirmed request and retry its original ID after signing in or refreshing. |
| 409 | `request_id_conflict` | The ID was committed with a different action or payload. Recover before another action. |
| 409 | `battle_state_conflict` | Recover the current scene; use a new ID if the player acts again. |
| 409 | `request_expired` | Recover the current scene. This ID cannot execute again. |

Success scenes keep the legacy string `battle_id` and add numeric
`engine_battle_id`, numeric `revision`, and `phase`. The phase is `active`,
`awaiting_switch`, or `ended`. The revision advances for each accepted mutation,
including mutations from older clients. Opening the PP skill selection menu
does not advance it; `use_item_on_skill` does. The selection response also carries
the current metadata.

`GET ?action=recover` returns one consistent current scene under the account
lock. It does not advance the revision. HTTP 404 means there is no current
battle. Completed action responses retain the true battle ID, so clients can
keep showing the result and loading its report after recovery returns 404.

A replayed response describes the original operation, which may be older than
the current battle. The game rechecks recovery before applying a replayed active
scene or PP menu, so another tab ending or replacing that battle cannot make an
old response restore it. A terminal receipt remains available to display its
result and report. Network calls that block battle controls have bounded waits;
an interrupted mutation retains its ID for confirmation.

`GET ?action=battle_log&battle_id=42` reads a report belonging to the signed-in
user, including ended battles. It returns `battle_id`, `kind`, `turn`, `phase`,
`result`, `rules_version`, `schema_version`, `names`, `lines`, `events`, and
`bbcode`. `turns` groups server-rendered lines as
`[{"turn": 1, "lines": ["..."]}]`. These read endpoints do not require a request ID.

## Storage and upgrades

`pm_battle.revision` guards stale scenes. `pm_battle_action` has a unique key on
`(uid, request_id)` and stores the action, complete request fingerprint, battle
ID, response, and creation time. Gameplay changes, revision, and receipt commit
in one transaction. All battle mutations acquire the same account lock.
Lazy schema changes finish before opening that transaction.

Responses can be replayed for 30 days. Later successful keyed requests for the
same user clear older response bodies. Small key and fingerprint tombstones
remain permanently, so an old `start` request cannot create another encounter
after its response expires. Operators must retain these tombstones to preserve
that guarantee.

Fresh installs include both schema changes. Existing installations can run
`migrations/2026-10-battle-actions.sql`, or use the API's lazy upgrade.
