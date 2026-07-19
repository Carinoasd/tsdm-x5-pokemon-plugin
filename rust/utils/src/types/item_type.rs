use serde::{Deserialize, Serialize};

use strum_macros::{Display, EnumIter, EnumString};

use crate::types::pokemon_type::PokemonKind;

#[derive(Clone, Copy, Debug, EnumString, EnumIter, PartialEq, Serialize, Deserialize, Display)]
#[serde(rename_all = "snake_case")]
pub enum ItemArmorSite {
    #[strum(to_string = "头部")]
    Head,
    #[strum(to_string = "饰品")]
    Necklace,
    #[strum(to_string = "武器")]
    Weapon,
    #[strum(to_string = "衣服")]
    Armor,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize, Display)]
#[serde(rename_all = "snake_case")]
pub enum ItemTag {
    #[strum(to_string = "药品")]
    Drug,
    #[strum(to_string = "宠物球")]
    Ball(u64),
    #[strum(to_string = "升级素材")]
    Evolution(u64),
    #[strum(to_string = "强化道具")]
    Enhance,
    #[strum(to_string = "装备")]
    Armor(ItemArmorSite),
    #[strum(to_string = "特殊物品")]
    Special(String),
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub struct ItemLimit {
    pub min_level: u64,
    pub kind_require: Option<PokemonKind>,
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize, Default)]
#[serde(default, rename_all = "snake_case")]
pub struct ItemEffect {
    pub add_hit_points: i64,
    pub add_experience: i64,
    pub add_level: i64,
    pub add_intimacy: i64,
    pub attribute_add_hit_points: i64,
    pub attribute_add_attack: i64,
    pub attribute_add_defense: i64,
    pub attribute_add_special_attack: i64,
    pub attribute_add_special_defense: i64,
    pub attribute_add_speed: i64,
    pub capture: i64,

    /// PP恢复相关字段
    /// None表示恢复所有技能的PP，Some(N)表示恢复第N个技能槽（0-3）
    pub restore_pp_skill_slot: Option<u8>,
    /// 恢复的PP数量，负数表示按百分比恢复
    pub restore_pp_amount: i64,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct ItemType {
    pub id: u64,
    pub name: String,
    pub img_name: String,
    pub description: String,

    pub is_selling: bool,
    pub price: u64,
    pub tag: ItemTag,

    pub limits: ItemLimit,
    pub effects: ItemEffect,
}

impl ItemType {
    const _TYPE: &'static str = "item_type";
}

impl Default for ItemType {
    fn default() -> Self {
        Self {
            id: 0,
            name: "".to_owned(),
            img_name: "".to_owned(),
            description: "".to_owned(),
            tag: ItemTag::Drug,

            is_selling: false,
            price: 0,

            limits: ItemLimit {
                min_level: 0,
                kind_require: None,
            },
            effects: ItemEffect {
                add_hit_points: 0,
                add_experience: 0,
                add_level: 0,
                add_intimacy: 0,
                attribute_add_hit_points: 0,
                attribute_add_attack: 0,
                attribute_add_defense: 0,
                attribute_add_special_attack: 0,
                attribute_add_special_defense: 0,
                attribute_add_speed: 0,
                capture: 0,
                restore_pp_skill_slot: None,
                restore_pp_amount: 0,
            },
        }
    }
}
