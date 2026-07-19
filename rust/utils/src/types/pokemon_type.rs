use serde::{Deserialize, Serialize};

use strum_macros::{Display, EnumIter, EnumString};

use crate::functions::get_random_points_0_to_31;

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize, Default)]
#[serde(rename_all = "snake_case")]
pub enum PokemonSex {
    Male,
    Female,
    #[default]
    Unknown,
}

#[derive(Clone, Copy, EnumString, EnumIter, Debug, PartialEq, Serialize, Deserialize, Display)]
#[serde(rename_all = "snake_case")]
pub enum PokemonKind {
    #[strum(to_string = "普通")]
    Normal,
    #[strum(to_string = "火")]
    Fire,
    #[strum(to_string = "水")]
    Water,
    #[strum(to_string = "草")]
    Grass,
    #[strum(to_string = "电")]
    Electric,
    #[strum(to_string = "冰")]
    Ice,
    #[strum(to_string = "格斗")]
    Fighting,
    #[strum(to_string = "毒")]
    Poison,
    #[strum(to_string = "地面")]
    Ground,
    #[strum(to_string = "飞行")]
    Flying,
    #[strum(to_string = "超能")]
    Psychic,
    #[strum(to_string = "虫")]
    Bug,
    #[strum(to_string = "岩石")]
    Rock,
    #[strum(to_string = "幽灵")]
    Ghost,
    #[strum(to_string = "龙")]
    Dragon,
    #[strum(to_string = "恶")]
    Dark,
    #[strum(to_string = "钢")]
    Steel,
    #[strum(to_string = "妖精")]
    Fairy,
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize, Default)]
pub struct PokemonAttributes {
    pub hit_points: u64,
    pub attack: u64,
    pub defense: u64,
    pub special_attack: u64,
    pub special_defense: u64,
    pub speed: u64,
}

impl PokemonAttributes {
    pub fn from_0_to_31_random_numbers() -> Self {
        let randoms = get_random_points_0_to_31();

        Self {
            hit_points: randoms[0],
            attack: randoms[1],
            defense: randoms[2],
            special_attack: randoms[3],
            special_defense: randoms[4],
            speed: randoms[5],
        }
    }
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct PokemonType {
    pub id: u64,
    pub name: String,
    pub description: String, // 对应列 txt

    pub cost: u64,        // 对应列 money
    pub is_selling: bool, // 对应列 shop

    pub sex_weight: Option<f32>, // 对应列 sex，取值范围为 Option<[0.0, 1.0]>，数据库中的原始数据记录范围为 [-1, 1000]
    pub initial_statistic: PokemonAttributes, // 初始个体值
    pub initial_base_points: PokemonAttributes, // 初始努力值
    pub kind: (PokemonKind, Option<PokemonKind>),
    pub is_legendary: bool, // 对应列 god，是否为神兽

    pub map_ids: Vec<u64>,
    pub evolution_info_ids: Vec<u64>,

    pub capture_weight: u64,          // 对应列 capture
    pub meet_weight: u64,             // 对应列 met
    pub birth_order: u64,             // 对应列 birthodds，从宠物蛋孵化到该宠物的几率
    pub strength_weight: u64,         // 对应列 strength
    pub drop_money_range: (u64, u64), // 对应列 minmoney 与列 maxmoney，宠物自身设置的金钱掉落数额范围
}

impl PokemonType {
    const _TYPE: &'static str = "pokemon_type";
}

impl Default for PokemonType {
    fn default() -> Self {
        Self {
            id: 0,
            name: "".to_owned(),
            description: "".to_owned(),

            cost: 0,
            is_selling: false,

            sex_weight: Some(0.5),
            initial_statistic: PokemonAttributes::default(),
            initial_base_points: PokemonAttributes::default(),
            kind: (PokemonKind::Normal, None),
            is_legendary: false,

            map_ids: vec![],
            evolution_info_ids: vec![],

            capture_weight: 0,
            meet_weight: 10,
            birth_order: 0,
            strength_weight: 1,
            drop_money_range: (0, 0),
        }
    }
}
