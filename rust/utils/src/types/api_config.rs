use serde::{Deserialize, Serialize};

/// 全局配置响应
#[derive(Clone, Debug, PartialEq, Deserialize, Serialize)]
pub struct GlobalConfigResponse {
    pub success: bool,
    #[serde(default)]
    pub data: GlobalConfigData,
}

#[derive(Clone, Debug, PartialEq, Deserialize, Serialize)]
#[serde(default)]
pub struct GlobalConfigData {
    #[serde(deserialize_with = "deserialize_optional_string")]
    pub ann_title: Option<String>,

    #[serde(deserialize_with = "deserialize_optional_string")]
    pub ann_url: Option<String>,

    #[serde(default)]
    pub news_announcements: Vec<NewsAnnouncement>,

    #[serde(default)]
    pub is_open: bool,

    #[serde(default)]
    pub is_enable_catch: bool,
}

fn deserialize_optional_string<'de, D>(deserializer: D) -> Result<Option<String>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    let value = String::deserialize(deserializer)?;
    if value.is_empty() {
        Ok(None)
    } else {
        Ok(Some(value))
    }
}

impl Default for GlobalConfigData {
    fn default() -> Self {
        Self {
            ann_title: None,
            ann_url: None,
            news_announcements: Vec::new(),
            is_open: true,
            is_enable_catch: true,
        }
    }
}

/// 新闻公告项
#[derive(Clone, Debug, PartialEq, Deserialize, Serialize)]
pub struct NewsAnnouncement {
    pub title: String,
    #[serde(default)]
    pub url: String,
}
