use crate::dioxus::state::AdminRoute;

#[allow(dead_code)]
pub fn all_routes() -> &'static [(AdminRoute, &'static str)] {
    &[
        (AdminRoute::GlobalConfig, "全局配置"),
        (AdminRoute::PokemonData, "宠物数据"),
        (AdminRoute::ItemData, "道具数据"),
        (AdminRoute::MapData, "地图设定"),
        (AdminRoute::UserData, "用户数据"),
        (AdminRoute::EvolutionData, "进化路线"),
        (AdminRoute::SkillType, "技能数据"),
        (AdminRoute::EffectData, "效果管理"),
    ]
}
