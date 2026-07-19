use serde::{Deserialize, Serialize};

use crate::types::api_config::NewsAnnouncement;

/// API - 话题数据类型
/// 对应 pokemon_system/api/topics.php
///
/// 话题列表响应
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct TopicsResponse {
    /// 新闻公告列表（最多 6 条）
    #[serde(default)]
    pub news_announcements: Vec<NewsAnnouncement>,
    pub topics: Vec<Topic>,
    pub total: usize,
}

/// 单个话题
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Topic {
    pub id: u64,
    pub title: String,
    pub author: String,
    #[serde(rename = "author_id")]
    pub author_id: u64,
    /// 格式化的日期 (m-d)
    pub date: String,
    /// Unix 时间戳
    pub timestamp: u64,
    /// 查看次数
    pub views: u64,
    /// 回复数
    pub replies: u64,
    /// 是否置顶
    #[serde(rename = "is_pinned")]
    pub is_pinned: bool,
}
