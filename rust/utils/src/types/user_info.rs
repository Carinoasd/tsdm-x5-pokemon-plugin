use serde::{Deserialize, Serialize};

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct UserInfo {
    pub id: u64,
    pub name: String,
    pub win_count: u64,
    pub lose_count: u64,
    pub money: u64,
    pub experience: u64,

    #[serde(default)]
    pub total_battles: u64,
    #[serde(default = "default_strength")]
    pub strength: u64,
    #[serde(default = "default_str")]
    pub str: u64,
    #[serde(default = "default_boxnum")]
    pub boxnum: u64,
    #[serde(default)]
    pub allure: u64,
    #[serde(default)]
    pub capture: u64,

    pub pokemon_list: Vec<u64>,
    pub item_list: Vec<u64>,
}

fn default_strength() -> u64 {
    1
}
fn default_str() -> u64 {
    100
}
fn default_boxnum() -> u64 {
    9
}

impl UserInfo {
    const _TYPE: &'static str = "user_info";
}

impl Default for UserInfo {
    fn default() -> Self {
        Self {
            id: 0,
            name: "".to_owned(),
            win_count: 0,
            lose_count: 0,
            money: 0,
            experience: 0,
            total_battles: 0,
            strength: 1,
            str: 100,
            boxnum: 9,
            allure: 0,
            capture: 0,
            pokemon_list: vec![],
            item_list: vec![],
        }
    }
}
