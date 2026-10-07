//! Validate responses produced by real PHP routes, rather than duplicated JSON.
use _utils::types::api_battle::{
    BattleItemsResponse, BattleLogResponse, BattleScene, SkillSelectionResponse,
};
use _utils::types::api_user::InventoryResponse;
use serde_json::Value;

#[test]
#[ignore = "Run scripts/test/live_database.php first and set TSDM_API_CONTRACT_FIXTURES"]
fn real_php_responses_match_rust_models() {
    let path = std::env::var("TSDM_API_CONTRACT_FIXTURES")
        .expect("TSDM_API_CONTRACT_FIXTURES must point to freshly generated PHP responses");
    let fixtures: Value = serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
    for name in [
        "start", "pp_used", "healed", "retried", "turn", "recover", "captured",
    ] {
        let scene: BattleScene = serde_json::from_value(fixtures[name].clone())
            .unwrap_or_else(|error| panic!("PHP {name} response: {error}"));
        assert!(
            scene.engine_battle_id > 0,
            "{name} must identify its engine row"
        );
        assert!(
            scene.revision > 0,
            "{name} must expose a committed revision"
        );
        assert_eq!(scene.my_pokemon.instance_id, 501);
        assert_eq!(scene.my_pokemon.id, 1);
    }
    let items: BattleItemsResponse = serde_json::from_value(fixtures["items"].clone()).unwrap();
    assert!(items
        .items
        .iter()
        .any(|item| item.id == 18 && item.nums == 10));
    let selection: SkillSelectionResponse =
        serde_json::from_value(fixtures["pp_selection"].clone()).unwrap();
    assert!(selection.requires_skill_selection);
    assert_eq!(selection.item_id, 18);
    assert_eq!(selection.available_skills[0].id, 701);
    assert_eq!(selection.available_skills[0].skill_id, 12);
    assert_eq!(selection.available_skills[0].current_pp, 2);
    assert_eq!(selection.available_skills[0].max_pp, 20);
    let inventory: InventoryResponse =
        serde_json::from_value(fixtures["inventory"].clone()).unwrap();
    assert_eq!(inventory.total, 3);
    assert!(inventory
        .items
        .iter()
        .any(|item| item.type_id == 18 && item.id != 18));
    let log: BattleLogResponse = serde_json::from_value(fixtures["log"].clone()).unwrap();
    assert_eq!(
        log.battle_id,
        fixtures["start"]["engine_battle_id"].as_u64().unwrap()
    );
    assert!(!log.events.is_empty());
    assert!(!log.turns.is_empty());
    assert!(!log.bbcode.is_empty());
}
