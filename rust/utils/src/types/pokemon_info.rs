use serde::{Deserialize, Serialize};

use strum_macros::{Display, EnumString};

use crate::types::pokemon_type::{PokemonAttributes, PokemonSex};

#[derive(Clone, Copy, Debug, PartialEq, EnumString, Display, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PokemonSite {
    #[strum(to_string = "正在直接跟随玩家")]
    Header = 1,
    #[strum(to_string = "位于背包")]
    Bag,
    #[strum(to_string = "位于仓库")]
    Store,
    #[strum(to_string = "正在医院接受治疗")]
    Hospital,
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
pub struct PokemonSkillInfo {
    pub type_id: u64,
    pub count: u64,
}

#[derive(Clone, Copy, Debug, PartialEq, EnumString, Display, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PokemonStatus {
    #[strum(to_string = "正常")]
    Normal,
    #[strum(to_string = "生病阶段一")]
    Sick1,
    #[strum(to_string = "生病阶段二")]
    Sick2,
    #[strum(to_string = "生病阶段三")]
    Sick3,
    #[strum(to_string = "饥饿阶段一")]
    Hungry1,
    #[strum(to_string = "饥饿阶段二")]
    Hungry2,
    #[strum(to_string = "疲惫")]
    Tired,
    #[strum(to_string = "兴奋阶段一")]
    Excited1,
    #[strum(to_string = "兴奋阶段二")]
    Excited2,
    #[strum(to_string = "兴奋阶段三")]
    Excited3,
    #[strum(to_string = "受伤")]
    Hurt,
    #[strum(to_string = "快乐阶段一")]
    Happy1,
    #[strum(to_string = "快乐阶段二")]
    Happy2,
    #[strum(to_string = "快乐阶段三")]
    Happy3,
    #[strum(to_string = "惊慌")]
    Shock,
    #[strum(to_string = "自恋阶段一")]
    SelfLove1,
    #[strum(to_string = "自恋阶段二")]
    SelfLove2,
    #[strum(to_string = "愤怒阶段一")]
    Angry1,
    #[strum(to_string = "愤怒阶段二")]
    Angry2,
    #[strum(to_string = "死亡")]
    Dead,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct PokemonInfo {
    pub id: u64,
    pub type_id: u64,
    pub owner: u64,
    pub name: String,
    pub site: PokemonSite,

    pub level: u64,
    pub experience: u64,
    pub intimacy: u64,
    pub using_ball_id: u64,
    pub is_shiny: bool, // 对应列 sg，是否为闪光宠物
    pub status: PokemonStatus,

    pub sex: PokemonSex,
    pub statistic: PokemonAttributes,   // 个体值，先天固定
    pub base_points: PokemonAttributes, // 努力值，又称基础点数，可通过后天训练提升
    pub skills: Vec<PokemonSkillInfo>,
    pub armor_slots_id: (Option<u64>, Option<u64>, Option<u64>, Option<u64>),
}

impl PokemonInfo {
    const _TYPE: &'static str = "pokemon_info";
}

impl Default for PokemonInfo {
    fn default() -> Self {
        Self {
            id: 0,
            type_id: 0,
            owner: 0,
            name: "".into(),
            site: PokemonSite::Store,

            level: 1,
            experience: 0,
            intimacy: 50,
            using_ball_id: 1,
            is_shiny: false,
            status: PokemonStatus::Normal,

            sex: PokemonSex::Male,
            statistic: PokemonAttributes::from_0_to_31_random_numbers(),
            base_points: Default::default(),

            skills: vec![],
            armor_slots_id: (None, None, None, None),
        }
    }
}
