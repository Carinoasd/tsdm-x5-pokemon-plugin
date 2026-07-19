use serde::{Deserialize, Serialize};

/// API - 商店系统类型
///
/// 对应 pokemon_system/api/shop.php
/// 商店列表响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ShopListResponse {
    pub items: Vec<ShopItem>,
    pub total: usize,
    pub page: usize,
    pub per_page: usize,
    pub total_pages: usize,
}

/// 商店商品
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ShopItem {
    pub id: u64,
    pub name: String,
    pub description: String,
    pub type_id: u64,
    pub type_name: String,
    /// 物品图标文件名 (tpname)
    #[serde(default)]
    pub image: String,
    pub price: i64,
    pub stock: i64,
    pub effect: ItemEffect,
    pub can_buy: bool,
}

/// 物品效果
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ItemEffect {
    #[serde(default)]
    pub description: String,
    #[serde(rename = "type", default)]
    pub effect_type: String,
    #[serde(default)]
    pub addhp: i64,
    #[serde(default)]
    pub addexp: i64,
    #[serde(default)]
    pub addlv: i64,
}

/// 购买请求
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct BuyItemRequest {
    pub item_id: u64,
    pub quantity: u64,
}

/// 购买响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct BuyItemResponse {
    pub message: String,
    pub total_cost: i64,
    pub remaining_money: i64,
    pub items_purchased: u64,
}

/// 商品分类
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ShopCategory {
    pub id: u64,
    pub name: String,
    pub description: String,
}

/// 分类列表响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct CategoriesResponse {
    pub categories: Vec<ShopCategory>,
}

/// 商店宠物
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ShopPet {
    pub id: u64,
    pub name: String,
    pub type_1: String,
    #[serde(default)]
    pub type_2: Option<String>,
    pub hp: u32,
    pub atk: u32,
    pub def: u32,
    pub spatk: u32,
    pub spdef: u32,
    pub speed: u32,
    pub price: i64,
    pub can_buy: bool,
}

/// 宠物列表响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ShopPetListResponse {
    pub pets: Vec<ShopPet>,
    pub total: usize,
    pub page: usize,
    pub per_page: usize,
    pub total_pages: usize,
}

/// 购买宠物请求
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct BuyPetRequest {
    pub pokemon_type_id: u64,
}

/// 购买宠物响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct BuyPetResponse {
    pub message: String,
    pub total_cost: i64,
    pub remaining_money: i64,
    pub pokemon_name: String,
    pub pokemon_type_id: u64,
    #[serde(default)]
    pub site: u64,
}
