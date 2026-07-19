use serde::{Deserialize, Serialize};

use super::pokemon_type::PokemonSex;

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize, Default)]
#[serde(rename_all = "snake_case")]
pub enum EvolutionCompareType {
    #[default]
    Equal,
    Greater,
    Less,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(untagged)]
pub enum EvolutionLimitType {
    MinLevel(MinLevelVariant),
    UseItem(UseItemVariant),
    MinIntimacy(MinIntimacyVariant),
    CompareAttackAndDefense(CompareAttackAndDefenseVariant),
    Random(RandomVariant),
    Sex(SexVariant),
    IsBagHaveChairs(IsBagHaveChairsVariant),
}

// Helper structs for untagged deserialization
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct MinLevelVariant {
    pub min_level: u64,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct UseItemVariant {
    pub use_item: u64,
    #[serde(default)]
    pub item_name: String,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct MinIntimacyVariant {
    pub min_intimacy: u64,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub struct CompareAttackAndDefenseVariant {
    pub compare_attack_and_defense: EvolutionCompareType,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct RandomVariant {
    pub random: f32,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub struct SexVariant {
    pub sex: PokemonSex,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub struct IsBagHaveChairsVariant {
    pub is_bag_have_chairs: bool,
}

// Implement conversions for convenience
impl From<MinLevelVariant> for EvolutionLimitType {
    fn from(v: MinLevelVariant) -> Self {
        EvolutionLimitType::MinLevel(v)
    }
}

impl From<UseItemVariant> for EvolutionLimitType {
    fn from(v: UseItemVariant) -> Self {
        EvolutionLimitType::UseItem(v)
    }
}

impl From<MinIntimacyVariant> for EvolutionLimitType {
    fn from(v: MinIntimacyVariant) -> Self {
        EvolutionLimitType::MinIntimacy(v)
    }
}

impl From<CompareAttackAndDefenseVariant> for EvolutionLimitType {
    fn from(v: CompareAttackAndDefenseVariant) -> Self {
        EvolutionLimitType::CompareAttackAndDefense(v)
    }
}

impl From<RandomVariant> for EvolutionLimitType {
    fn from(v: RandomVariant) -> Self {
        EvolutionLimitType::Random(v)
    }
}

impl From<SexVariant> for EvolutionLimitType {
    fn from(v: SexVariant) -> Self {
        EvolutionLimitType::Sex(v)
    }
}

impl From<IsBagHaveChairsVariant> for EvolutionLimitType {
    fn from(v: IsBagHaveChairsVariant) -> Self {
        EvolutionLimitType::IsBagHaveChairs(v)
    }
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct EvolutionInfo {
    pub id: u64,
    pub source_id: u64,
    pub target_id: u64,
    #[serde(default)]
    pub source_name: String,
    #[serde(default)]
    pub target_name: String,

    pub condition: EvolutionLimitType,
    pub priority: u64,
    /// 道具名称（仅当 condition 为 UseItem 时有效）
    #[serde(default)]
    pub item_name: String,
}

impl EvolutionInfo {
    const _TYPE: &'static str = "evolution_info";
}

impl Default for EvolutionInfo {
    fn default() -> Self {
        Self {
            id: 0,
            source_id: 0,
            target_id: 0,
            source_name: String::new(),
            target_name: String::new(),

            condition: EvolutionLimitType::MinLevel(MinLevelVariant { min_level: 0 }),
            priority: 0,
            item_name: String::new(),
        }
    }
}
