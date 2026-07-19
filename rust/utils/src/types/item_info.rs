use serde::{Deserialize, Serialize};

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize, Default)]
pub struct ItemInfo {
    pub id: u64,
    pub owner: u64,
    pub type_id: u64,
    pub count: u64,
}

impl ItemInfo {
    const _TYPE: &'static str = "item_info";
}
