use serde::{Deserialize, Serialize};

use super::api_map::MapInfo;

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct MapRegion {
    pub id: String,
    pub name: String,
    pub maps: Vec<MapLocation>,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct MapLocation {
    pub map_id: u64,
    pub name: String,
    pub x: f32,
    pub y: f32,
    pub region: String,
}

impl MapRegion {
    pub fn from_maps(maps: &[MapInfo]) -> Vec<MapRegion> {
        let mut regions: std::collections::HashMap<String, MapRegion> =
            std::collections::HashMap::new();

        let region_names = get_region_names();

        for map in maps {
            let region_id = if map.region.is_empty() {
                "unknown".to_string()
            } else {
                map.region.clone()
            };

            let location = MapLocation {
                map_id: map.id,
                name: map.name.clone(),
                x: map.pos_x as f32,
                y: map.pos_y as f32,
                region: region_id.clone(),
            };

            regions
                .entry(region_id.clone())
                .or_insert_with(|| MapRegion {
                    id: region_id.clone(),
                    name: region_names
                        .get(&region_id)
                        .cloned()
                        .unwrap_or_else(|| "未知地区".to_string()),
                    maps: Vec::new(),
                })
                .maps
                .push(location);
        }

        let mut result: Vec<MapRegion> = regions.into_values().collect();
        result.sort_by(|a, b| {
            let order_a = get_region_order(&a.id);
            let order_b = get_region_order(&b.id);
            order_a.cmp(&order_b)
        });

        result
    }
}

fn get_region_names() -> std::collections::HashMap<String, String> {
    let mut names = std::collections::HashMap::new();
    // 英文region id映射
    names.insert("central".to_string(), "中央地区".to_string());
    names.insert("north".to_string(), "北部地区".to_string());
    names.insert("east".to_string(), "东部地区".to_string());
    names.insert("south".to_string(), "南部地区".to_string());
    names.insert("west".to_string(), "西部地区".to_string());
    names.insert("ocean".to_string(), "海洋地区".to_string());
    names.insert("cave".to_string(), "洞窟地区".to_string());
    names.insert("mountain".to_string(), "山脉地区".to_string());
    names.insert("sky".to_string(), "天空地区".to_string());
    names.insert("desert".to_string(), "沙漠地区".to_string());
    names.insert("city".to_string(), "城市地区".to_string());
    names.insert("volcano".to_string(), "火山地区".to_string());
    names.insert("wild".to_string(), "野外地区".to_string());
    names.insert("special".to_string(), "特殊地区".to_string());
    names.insert("unknown".to_string(), "未知地区".to_string());
    // 中文region直接映射（兼容旧数据库）
    names.insert("中央".to_string(), "中央地区".to_string());
    names.insert("北部".to_string(), "北部地区".to_string());
    names.insert("东部".to_string(), "东部地区".to_string());
    names.insert("南部".to_string(), "南部地区".to_string());
    names.insert("西部".to_string(), "西部地区".to_string());
    names.insert("西北".to_string(), "西北地区".to_string());
    names.insert("东北".to_string(), "东北地区".to_string());
    names.insert("西南".to_string(), "西南地区".to_string());
    names.insert("东南".to_string(), "东南地区".to_string());
    names.insert("海洋".to_string(), "海洋地区".to_string());
    names.insert("洞窟".to_string(), "洞窟地区".to_string());
    names.insert("山洞".to_string(), "洞窟地区".to_string());
    names.insert("山脉".to_string(), "山脉地区".to_string());
    names.insert("山谷".to_string(), "山脉地区".to_string());
    names.insert("天空".to_string(), "天空地区".to_string());
    names.insert("沙漠".to_string(), "沙漠地区".to_string());
    names.insert("城市".to_string(), "城市地区".to_string());
    names.insert("火山".to_string(), "火山地区".to_string());
    names.insert("野外".to_string(), "野外地区".to_string());
    names.insert("特殊".to_string(), "特殊地区".to_string());
    names.insert("未知".to_string(), "未知地区".to_string());
    names
}

fn get_region_order(region_id: &str) -> u8 {
    match region_id {
        // 英文region id排序
        "central" => 1,
        "north" => 2,
        "east" => 3,
        "south" => 4,
        "west" => 5,
        "ocean" => 6,
        "cave" => 7,
        "mountain" => 8,
        "sky" => 9,
        "desert" => 10,
        "city" => 11,
        "volcano" => 12,
        "wild" => 13,
        "special" => 14,
        "unknown" => 99,
        // 中文region排序（兼容旧数据库）
        "中央" => 1,
        "北部" => 2,
        "东北" => 2,
        "东部" => 3,
        "东南" => 3,
        "南部" => 4,
        "西南" => 4,
        "西部" => 5,
        "西北" => 5,
        "海洋" => 6,
        "洞窟" => 7,
        "山洞" => 7,
        "山脉" => 8,
        "山谷" => 8,
        "天空" => 9,
        "沙漠" => 10,
        "城市" => 11,
        "火山" => 12,
        "野外" => 13,
        "特殊" => 14,
        "未知" => 99,
        _ => 99,
    }
}

pub fn get_hoenn_regions() -> Vec<MapRegion> {
    Vec::new()
}
