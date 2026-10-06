use serde::{Deserialize, Deserializer, Serialize, Serializer};

pub const EFFECT_KINDS: &[(&str, &str)] = &[
    ("move", "招式"),
    ("ability", "特性"),
    ("item", "道具"),
    ("weather", "天气"),
    ("field", "场地"),
    ("status", "状态"),
];
pub const EFFECT_HOOKS: &[(&str, &str)] = &[
    ("on_battle_start", "战斗开始"),
    ("on_switch_in", "入场"),
    ("on_switch_out", "退场"),
    ("on_before_move", "出招前"),
    ("on_damage_calc", "伤害计算"),
    ("on_hit", "命中后"),
    ("on_after_move", "出招后"),
    ("on_turn_end", "回合结束"),
    ("on_faint", "倒下"),
];
pub const EFFECT_STATS: &[(&str, &str)] = &[
    ("atk", "攻击"),
    ("def", "防御"),
    ("spatk", "特攻"),
    ("spdef", "特防"),
    ("speed", "速度"),
    ("accuracy", "命中率"),
    ("evasion", "闪避率"),
];
pub const EFFECT_STATUSES: &[(&str, &str)] = &[
    ("poison", "中毒"),
    ("burn", "烧伤"),
    ("paralysis", "麻痹"),
    ("sleep", "睡眠"),
    ("freeze", "冰冻"),
    ("confusion", "混乱"),
];

fn default_target() -> String {
    "self".into()
}
fn default_chance() -> u8 {
    100
}

/// Only effects implemented by battle_core_effect_schema are editable here.
#[derive(Clone, Debug, PartialEq)]
pub enum EffectParams {
    StagesBoost {
        stat: String,
        stages: i8,
        target: String,
    },
    StatusInflict {
        status: String,
        chance: u8,
    },
    Unsupported(serde_json::Value),
}

#[derive(Serialize, Deserialize)]
#[serde(tag = "code", rename_all = "snake_case")]
enum KnownEffectParams {
    StagesBoost {
        stat: String,
        stages: i8,
        #[serde(default = "default_target")]
        target: String,
    },
    StatusInflict {
        status: String,
        #[serde(default = "default_chance")]
        chance: u8,
    },
}

impl<'de> Deserialize<'de> for EffectParams {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        let value = serde_json::Value::deserialize(deserializer)?;
        let allowed_keys: &[&str] = match value.get("code").and_then(|code| code.as_str()) {
            Some("stages_boost") => &["code", "stat", "stages", "target"],
            Some("status_inflict") => &["code", "status", "chance"],
            _ => &[],
        };
        if value.as_object().is_some_and(|object| {
            object
                .keys()
                .any(|key| !allowed_keys.contains(&key.as_str()))
        }) {
            return Ok(Self::Unsupported(value));
        }
        Ok(
            match serde_json::from_value::<KnownEffectParams>(value.clone()) {
                Ok(KnownEffectParams::StagesBoost {
                    stat,
                    stages,
                    target,
                }) => Self::StagesBoost {
                    stat,
                    stages,
                    target,
                },
                Ok(KnownEffectParams::StatusInflict { status, chance }) => {
                    Self::StatusInflict { status, chance }
                }
                Err(_) => Self::Unsupported(value),
            },
        )
    }
}

impl Serialize for EffectParams {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        match self {
            Self::StagesBoost {
                stat,
                stages,
                target,
            } => KnownEffectParams::StagesBoost {
                stat: stat.clone(),
                stages: *stages,
                target: target.clone(),
            }
            .serialize(serializer),
            Self::StatusInflict { status, chance } => KnownEffectParams::StatusInflict {
                status: status.clone(),
                chance: *chance,
            }
            .serialize(serializer),
            Self::Unsupported(value) => value.serialize(serializer),
        }
    }
}

impl Default for EffectParams {
    fn default() -> Self {
        Self::StagesBoost {
            stat: "atk".into(),
            stages: 1,
            target: default_target(),
        }
    }
}

impl EffectParams {
    pub fn code(&self) -> &'static str {
        match self {
            Self::StagesBoost { .. } => "stages_boost",
            Self::StatusInflict { .. } => "status_inflict",
            Self::Unsupported(_) => "unsupported",
        }
    }

    pub fn label(&self) -> &'static str {
        match self {
            Self::StagesBoost { .. } => "能力阶级变化",
            Self::StatusInflict { .. } => "附加异常状态",
            Self::Unsupported(_) => "未支持的效果",
        }
    }
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct EffectData {
    pub id: u64,
    pub code: String,
    pub kind: String,
    pub hooks: Vec<String>,
    pub params: EffectParams,
    pub description: String,
    pub version: u64,
}

impl Default for EffectData {
    fn default() -> Self {
        Self {
            id: 0,
            code: String::new(),
            kind: "move".into(),
            hooks: vec!["on_after_move".into()],
            params: EffectParams::default(),
            description: String::new(),
            version: 1,
        }
    }
}

impl EffectData {
    pub fn validate(&self) -> Result<(), &'static str> {
        if self.code.trim().is_empty() || self.code.chars().count() > 40 {
            return Err("效果标识不能为空，最多 40 个字符");
        }
        if self.description.chars().count() > 255 {
            return Err("描述最多 255 个字符");
        }
        if self.version == 0 || self.version > u32::MAX as u64 {
            return Err("版本必须为 1 至 4294967295 的整数");
        }
        if !EFFECT_KINDS.iter().any(|(kind, _)| *kind == self.kind) {
            return Err("请选择有效的效果来源");
        }
        if self.hooks.is_empty()
            || self
                .hooks
                .iter()
                .any(|hook| !EFFECT_HOOKS.iter().any(|(known, _)| *known == hook))
        {
            return Err("请选择至少一个有效的触发时机");
        }
        match &self.params {
            EffectParams::StagesBoost {
                stat,
                stages,
                target,
            } => {
                if !EFFECT_STATS.iter().any(|(known, _)| *known == stat)
                    || *stages == 0
                    || !(-6..=6).contains(stages)
                    || !["self", "opponent"].contains(&target.as_str())
                {
                    return Err("能力变化需选择有效能力、目标及 -6 至 6 的非零阶级");
                }
            }
            EffectParams::StatusInflict { status, chance } => {
                if !EFFECT_STATUSES.iter().any(|(known, _)| *known == status) || *chance > 100 {
                    return Err("请选择有效异常状态和 0 至 100 的概率");
                }
            }
            EffectParams::Unsupported(_) => {
                return Err("此效果格式尚不支持，请先选择已支持的效果类型")
            }
        }
        Ok(())
    }

    /// Limits of the current skill-effect runtime, beyond the core JSON schema.
    pub fn binding_error(&self, power: u64) -> Option<&'static str> {
        if self.validate().is_err() {
            return Some("模板参数无效，需先在效果管理中修正");
        }
        if self.kind != "move" {
            return Some("当前技能仅支持招式来源的效果");
        }
        let expected_hook = match &self.params {
            EffectParams::StagesBoost { .. } if power == 0 => "on_after_move",
            EffectParams::StagesBoost { .. } => "on_hit",
            EffectParams::StatusInflict { status, .. } => {
                if status == "confusion" {
                    return Some("混乱效果尚未实现");
                }
                if power == 0 {
                    return Some("异常状态效果需要威力大于 0 的伤害技");
                }
                "on_hit"
            }
            EffectParams::Unsupported(_) => return Some("此效果类型尚未实现"),
        };
        if self.hooks.len() != 1 || self.hooks[0] != expected_hook {
            return Some(if power == 0 {
                "威力为 0 的能力变化技需选择「出招后」"
            } else {
                "伤害技需选择单一「命中后」时机"
            });
        }
        None
    }

    pub fn editor_error(&self) -> Option<&'static str> {
        let power = if self.hooks.len() == 1 && self.hooks[0] == "on_after_move" {
            0
        } else {
            1
        };
        self.binding_error(power)
    }
}

#[cfg(test)]
mod tests {
    use super::{EffectData, EffectParams};
    use serde_json::json;

    #[test]
    fn effect_templates_round_trip_with_php_wire_shape() {
        for params in [
            json!({"code":"stages_boost","stat":"accuracy","stages":-2,"target":"opponent"}),
            json!({"code":"status_inflict","status":"burn","chance":30}),
        ] {
            let wire = json!({"_TYPE":"effect_data","id":9,"code":"test_effect","kind":"move",
                "hooks":["on_hit"],"params":params,"description":"test","version":1});
            let effect: EffectData = serde_json::from_value(wire).unwrap();
            assert!(effect.validate().is_ok());
            assert_eq!(serde_json::to_value(&effect).unwrap()["params"], params);
        }
        let unknown = json!({"code":"heal","percent":50,"custom":{"keep":true}});
        let parsed: EffectParams = serde_json::from_value(unknown.clone()).unwrap();
        assert!(matches!(parsed, EffectParams::Unsupported(_)));
        assert_eq!(serde_json::to_value(parsed).unwrap(), unknown);
    }

    #[test]
    fn invalid_templates_are_rejected_before_saving() {
        let mut effect = EffectData {
            code: "test".into(),
            ..EffectData::default()
        };
        assert!(effect.validate().is_ok());
        effect.params = EffectParams::StagesBoost {
            stat: "atk".into(),
            stages: 0,
            target: "self".into(),
        };
        assert!(effect.validate().is_err());
        effect.params = EffectParams::StatusInflict {
            status: "burn".into(),
            chance: 101,
        };
        assert!(effect.validate().is_err());
        effect.params = EffectParams::default();
        effect.hooks.clear();
        assert!(effect.validate().is_err());
    }

    #[test]
    fn skill_binding_checks_actual_runtime_support() {
        let mut effect = EffectData {
            code: "test".into(),
            ..EffectData::default()
        };
        assert!(effect.binding_error(0).is_none());
        assert!(effect.binding_error(40).is_some());
        effect.hooks = vec!["on_hit".into()];
        assert!(effect.binding_error(40).is_none());
        effect.params = EffectParams::StatusInflict {
            status: "sleep".into(),
            chance: 100,
        };
        assert!(effect.binding_error(0).is_some());
        assert!(effect.binding_error(40).is_none());
        effect.params = EffectParams::StatusInflict {
            status: "confusion".into(),
            chance: 100,
        };
        assert!(effect.binding_error(40).is_some());
    }
}
