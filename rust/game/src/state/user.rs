use crate::prelude::*;

use _utils::types::api_user::{InventoryStatsResponse, UserProfileResponse};

#[derive(Clone, PartialEq, Default)]
pub struct UserState {
    pub profile: Option<UserProfileResponse>,
    pub inventory: Option<InventoryStatsResponse>,
    pub profile_loaded: bool,
    pub inventory_loaded: bool,
}

impl UserState {
    pub fn get_money(&self) -> i64 {
        self.profile.as_ref().and_then(|p| p.money).unwrap_or(0)
    }

    pub fn get_username(&self) -> &str {
        self.profile
            .as_ref()
            .map(|p| p.username.as_str())
            .unwrap_or("")
    }

    pub fn is_in_battle(&self) -> bool {
        self.profile.as_ref().map(|p| p.npcid > 0).unwrap_or(false)
    }
}

pub fn use_user_profile_state() {
    let loaded = crate::state::USER_STATE.read().profile_loaded;

    use_effect(move || {
        if loaded {
            return;
        }

        spawn(async move {
            let api = crate::utils::api_client::NewApiClient::new();
            match api.get_user_profile().await {
                Ok(data) => {
                    let npcid = data.npcid;
                    let mut state = crate::state::USER_STATE.write();
                    state.profile = Some(data);
                    state.profile_loaded = true;
                    drop(state);

                    // 最后一击可能已结束战斗，但客户端尚未收到结果。
                    // 按已加载的 uid 查待确认操作，先进入冒险页确认原请求。
                    if crate::state::load_pending_battle().is_some() {
                        *crate::components::layout::CURRENT_PAGE.write() =
                            crate::components::layout::Page::Adventure;
                    } else if npcid > 0 {
                        recover_battle();
                    }
                }
                Err(_e) => {
                    crate::state::USER_STATE.write().profile_loaded = true;
                }
            }
        });
    });
}

fn recover_battle() {
    spawn(async move {
        let api = crate::utils::api_client::NewApiClient::new();
        match api.recover_battle().await {
            Ok(_scene) => {
                *crate::components::layout::CURRENT_PAGE.write() =
                    crate::components::layout::Page::Adventure;
            }
            Err(_e) => {}
        }
    });
}

pub fn refresh_user_profile_state() {
    spawn(async move {
        let api = crate::utils::api_client::NewApiClient::new();
        match api.get_user_profile().await {
            Ok(data) => {
                crate::state::USER_STATE.write().profile = Some(data);
            }
            Err(_e) => {}
        }
    });
}

pub fn use_inventory_state() {
    let loaded = crate::state::USER_STATE.read().inventory_loaded;

    use_effect(move || {
        if loaded {
            return;
        }

        spawn(async move {
            let api = crate::utils::api_client::NewApiClient::new();
            match api.get_inventory_stats().await {
                Ok(data) => {
                    let mut state = crate::state::USER_STATE.write();
                    state.inventory = Some(data);
                    state.inventory_loaded = true;
                }
                Err(_e) => {
                    crate::state::USER_STATE.write().inventory_loaded = true;
                }
            }
        });
    });
}

pub fn refresh_inventory_state() {
    spawn(async move {
        let api = crate::utils::api_client::NewApiClient::new();
        match api.get_inventory_stats().await {
            Ok(data) => {
                crate::state::USER_STATE.write().inventory = Some(data);
            }
            Err(_e) => {}
        }
    });
}
