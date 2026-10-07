use crate::prelude::*;

use _utils::types::api_user::{InventoryStatsResponse, UserProfileResponse};

#[derive(Clone, PartialEq, Default)]
pub struct UserState {
    pub profile: Option<UserProfileResponse>,
    pub inventory: Option<InventoryStatsResponse>,
    pub profile_loaded: bool,
    pub profile_loading: bool,
    pub profile_error: Option<String>,
    pub profile_request: u64,
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
    use_effect(move || {
        let state = crate::state::USER_STATE.peek();
        if state.profile_loaded || state.profile_loading {
            return;
        }
        drop(state);
        request_user_profile(true);
    });
}

fn request_user_profile(navigate_to_battle: bool) {
    let request_id = {
        let mut state = crate::state::USER_STATE.write();
        state.profile_request += 1;
        state.profile_loading = true;
        state.profile_error = None;
        state.profile_request
    };
    dioxus_core::spawn_forever(async move {
        let api = crate::utils::api_client::NewApiClient::new();
        let result = api.get_user_profile().await;
        let mut state = crate::state::USER_STATE.write();
        if state.profile_request != request_id {
            return;
        }
        state.profile_loaded = true;
        state.profile_loading = false;
        match result {
            Ok(data) => {
                let npcid = data.npcid;
                state.profile = Some(data);
                drop(state);

                // 最后一击可能已结束战斗，但客户端尚未收到结果。
                // 按已加载的 uid 查待确认操作，先进入冒险页确认原请求。
                if !navigate_to_battle {
                    return;
                }
                if crate::state::load_pending_battle().is_some() {
                    *crate::components::layout::CURRENT_PAGE.write() =
                        crate::components::layout::Page::Adventure;
                } else if npcid > 0 {
                    recover_battle();
                }
            }
            Err(error) => state.profile_error = Some(error.to_string()),
        }
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
    let navigate = crate::state::USER_STATE.peek().profile.is_none();
    request_user_profile(navigate);
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
