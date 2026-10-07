use crate::prelude::*;
use crate::{components::common::Modal, utils::api_client::NewApiClient};

#[component]
pub fn BattleLogPanel(battle_id: u64, on_close: EventHandler<()>) -> Element {
    let mut report =
        use_resource(move || async move { NewApiClient::new().get_battle_log(battle_id).await });
    let mut copy_status = use_signal(String::new);
    rsx! {
        Modal {
            is_open: true,
            title: "逐回合战报".to_string(),
            on_close,
            div { class: "battle-log-panel", "data-testid": "battle-log-panel",
                button { class: "btn btn-secondary", "data-testid": "battle-log-refresh",
                    onclick: move |_| { copy_status.set(String::new()); report.restart(); },
                    "重新载入"
                }
                match &*report.read() {
                    None => rsx! { p { role: "status", "正在载入战报..." } },
                    Some(Err(error)) => rsx! {
                        p { role: "alert", "data-testid": "battle-log-error", "战报载入失败：{error}。请重新载入。" }
                    },
                    Some(Ok(data)) => {
                        let bbcode = data.bbcode.clone();
                        rsx! {
                            div { class: "battle-log-events", "data-testid": "battle-log-events",
                                if data.turns.is_empty() {
                                    if data.lines.is_empty() { p { "尚无战斗事件。" } }
                                    for (index, line) in data.lines.iter().enumerate() {
                                        p { key: "line-{index}", "{line}" }
                                    }
                                } else {
                                    for turn in &data.turns {
                                        section { key: "turn-{turn.turn}", class: "battle-log-turn",
                                            h4 { "第 {turn.turn} 回合" }
                                            for (index, line) in turn.lines.iter().enumerate() {
                                                p { key: "line-{index}", "{line}" }
                                            }
                                        }
                                    }
                                }
                            }
                            label { r#for: "battle-share-text", "可贴到论坛的战报" }
                            textarea { id: "battle-share-text", "data-testid": "battle-share-text",
                                readonly: true, rows: 5, value: "{data.bbcode}" }
                            button { class: "btn btn-primary", "data-testid": "battle-log-copy",
                                onclick: move |_| {
                                    let text = bbcode.clone();
                                    spawn(async move {
                                        match copy_report(text).await {
                                            Ok(()) => copy_status.set("已复制战报，可贴到论坛。".to_string()),
                                            Err(()) => copy_status.set("无法自动复制，请选取上方文字后复制。".to_string()),
                                        }
                                    });
                                },
                                "复制战报"
                            }
                            p { role: "status", "data-testid": "battle-copy-status", "{copy_status}" }
                        }
                    },
                }
            }
        }
    }
}

async fn copy_report(text: String) -> Result<(), ()> {
    #[cfg(target_arch = "wasm32")]
    {
        let window = web_sys::window().ok_or(())?;
        wasm_bindgen_futures::JsFuture::from(window.navigator().clipboard().write_text(&text))
            .await
            .map_err(|_| ())?;
        Ok(())
    }
    #[cfg(not(target_arch = "wasm32"))]
    {
        let _ = text;
        Err(())
    }
}
