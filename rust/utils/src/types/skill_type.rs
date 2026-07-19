use serde::{Deserialize, Deserializer, Serialize, Serializer};

use super::pokemon_type::PokemonKind;

/// 能力值类型
#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum StatType {
    Attack,
    Defense,
    SpecialAttack,
    SpecialDefense,
    Speed,
}

/// 状态效果类型
#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum StatusEffect {
    Burn,      // 烧伤
    Freeze,    // 冰冻
    Paralysis, // 麻痹
    Poison,    // 中毒
    Sleep,     // 睡眠
    Confusion, // 混乱
}

/// 技能效果目标
#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Target {
    MySelf,   // 自己
    Opponent, // 对手
}

#[derive(Clone, Copy, Debug, PartialEq)]
pub enum SkillEffect {
    /// 物理伤害技能
    PhysicalDamage { kind: PokemonKind, power: u64 },

    /// 特殊伤害技能
    SpecialDamage { kind: PokemonKind, power: u64 },

    /// 能力值提升/降低
    StatBoost {
        stat: StatType,
        stages: i8, // -6 到 +6 的能力等级变化
        target: Target,
    },

    /// 施加状态效果
    InflictStatus {
        effect: StatusEffect,
        chance: u8, // 0-100 的触发概率
    },

    /// 治疗技能
    Heal {
        percent: u8, // 恢复百分比 (0-100)
    },

    /// 先制/后制攻击
    Priority {
        kind: PokemonKind,
        power: u64,
        priority: i8, // 优先级，正数表示先制，负数表示后制
    },

    /// 反伤技能
    Recoil {
        kind: PokemonKind,
        power: u64,
        recoil_percent: u8, // 反伤百分比
    },

    /// 一击必杀技能
    OneHitKO { kind: PokemonKind },

    /// 固定伤害技能
    FixedDamage { damage: u64 },

    /// 其他未分类效果（逐步替换）
    Others { kind: PokemonKind, power: u64 },
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum TaggedSkillEffect {
    PhysicalDamage {
        kind: PokemonKind,
        power: u64,
    },
    SpecialDamage {
        kind: PokemonKind,
        power: u64,
    },
    StatBoost {
        stat: StatType,
        stages: i8,
        target: Target,
    },
    InflictStatus {
        effect: StatusEffect,
        chance: u8,
    },
    Heal {
        percent: u8,
    },
    Priority {
        kind: PokemonKind,
        power: u64,
        priority: i8,
    },
    Recoil {
        kind: PokemonKind,
        power: u64,
        recoil_percent: u8,
    },
    OneHitKO {
        kind: PokemonKind,
    },
    FixedDamage {
        damage: u64,
    },
    Others {
        kind: PokemonKind,
        power: u64,
    },
}

#[derive(Clone, Copy, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
enum LegacySkillEffect {
    PhysicalDamage((PokemonKind, u64)),
    SpecialDamage((PokemonKind, u64)),
    Others((PokemonKind, u64)),
}

#[derive(Clone, Copy, Debug, PartialEq, Deserialize)]
#[serde(untagged)]
enum SkillEffectWire {
    Tagged(TaggedSkillEffect),
    Legacy(LegacySkillEffect),
}

impl From<TaggedSkillEffect> for SkillEffect {
    fn from(value: TaggedSkillEffect) -> Self {
        match value {
            TaggedSkillEffect::PhysicalDamage { kind, power } => {
                Self::PhysicalDamage { kind, power }
            }
            TaggedSkillEffect::SpecialDamage { kind, power } => Self::SpecialDamage { kind, power },
            TaggedSkillEffect::StatBoost {
                stat,
                stages,
                target,
            } => Self::StatBoost {
                stat,
                stages,
                target,
            },
            TaggedSkillEffect::InflictStatus { effect, chance } => {
                Self::InflictStatus { effect, chance }
            }
            TaggedSkillEffect::Heal { percent } => Self::Heal { percent },
            TaggedSkillEffect::Priority {
                kind,
                power,
                priority,
            } => Self::Priority {
                kind,
                power,
                priority,
            },
            TaggedSkillEffect::Recoil {
                kind,
                power,
                recoil_percent,
            } => Self::Recoil {
                kind,
                power,
                recoil_percent,
            },
            TaggedSkillEffect::OneHitKO { kind } => Self::OneHitKO { kind },
            TaggedSkillEffect::FixedDamage { damage } => Self::FixedDamage { damage },
            TaggedSkillEffect::Others { kind, power } => Self::Others { kind, power },
        }
    }
}

impl From<LegacySkillEffect> for SkillEffect {
    fn from(value: LegacySkillEffect) -> Self {
        match value {
            LegacySkillEffect::PhysicalDamage((kind, power)) => {
                Self::PhysicalDamage { kind, power }
            }
            LegacySkillEffect::SpecialDamage((kind, power)) => Self::SpecialDamage { kind, power },
            LegacySkillEffect::Others((kind, power)) => Self::Others { kind, power },
        }
    }
}

impl From<SkillEffect> for TaggedSkillEffect {
    fn from(value: SkillEffect) -> Self {
        match value {
            SkillEffect::PhysicalDamage { kind, power } => Self::PhysicalDamage { kind, power },
            SkillEffect::SpecialDamage { kind, power } => Self::SpecialDamage { kind, power },
            SkillEffect::StatBoost {
                stat,
                stages,
                target,
            } => Self::StatBoost {
                stat,
                stages,
                target,
            },
            SkillEffect::InflictStatus { effect, chance } => Self::InflictStatus { effect, chance },
            SkillEffect::Heal { percent } => Self::Heal { percent },
            SkillEffect::Priority {
                kind,
                power,
                priority,
            } => Self::Priority {
                kind,
                power,
                priority,
            },
            SkillEffect::Recoil {
                kind,
                power,
                recoil_percent,
            } => Self::Recoil {
                kind,
                power,
                recoil_percent,
            },
            SkillEffect::OneHitKO { kind } => Self::OneHitKO { kind },
            SkillEffect::FixedDamage { damage } => Self::FixedDamage { damage },
            SkillEffect::Others { kind, power } => Self::Others { kind, power },
        }
    }
}

impl Serialize for SkillEffect {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: Serializer,
    {
        match *self {
            SkillEffect::PhysicalDamage { kind, power } => {
                LegacySkillEffect::PhysicalDamage((kind, power)).serialize(serializer)
            }
            SkillEffect::SpecialDamage { kind, power } => {
                LegacySkillEffect::SpecialDamage((kind, power)).serialize(serializer)
            }
            SkillEffect::Others { kind, power } => {
                LegacySkillEffect::Others((kind, power)).serialize(serializer)
            }
            _ => TaggedSkillEffect::from(*self).serialize(serializer),
        }
    }
}

impl<'de> Deserialize<'de> for SkillEffect {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: Deserializer<'de>,
    {
        match SkillEffectWire::deserialize(deserializer)? {
            SkillEffectWire::Tagged(value) => Ok(value.into()),
            SkillEffectWire::Legacy(value) => Ok(value.into()),
        }
    }
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct SkillType {
    pub id: u64,
    pub name: String,
    pub description: String,
    pub available_pokemons: Vec<u64>,

    pub min_level_limit: u64,
    pub use_times_limit: u64,
    pub effect: SkillEffect,
}

impl SkillType {
    const _TYPE: &'static str = "skill_type";
}

impl Default for SkillType {
    fn default() -> Self {
        Self {
            id: 0,
            name: "".to_string(),
            available_pokemons: vec![],
            description: "".to_string(),

            min_level_limit: 0,
            use_times_limit: 0,
            effect: SkillEffect::PhysicalDamage {
                kind: PokemonKind::Normal,
                power: 20,
            },
        }
    }
}
