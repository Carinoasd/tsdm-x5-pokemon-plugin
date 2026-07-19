use dioxus::prelude::*;

#[component]
pub fn Footer() -> Element {
    rsx! {
        footer { class: "app-footer",
            div { class: "footer-inner",
                span { class: "footer-copyright", "© TSDM 天使动漫 宝可梦插件 V3" }
                div { class: "footer-powered",
                    div { class: "powered-line", "Designed by langyo" }
                    div { class: "powered-models", "via GLM 5 · Qwen 3.5 Plus · Claude Opus 4.7 · Minimax M2.5 · Kimi K2.5" }
                }
            }
        }
    }
}
