use crate::dioxus::prelude::*;
use crate::dioxus::{
    components::form_fields::{Col, FormSection, Row},
    pages::shared::ActionModal,
    state::{set_notice, AdminNoticeLevel},
    utils::api::{delete_effect_data, get_effect_data, list_all_effect_data, save_effect_data},
};
use _utils::types::effect_data::{EffectData, EffectParams, EFFECT_STATS, EFFECT_STATUSES};

#[component]
pub fn EffectDataPage() -> Element {
    let mut effects = use_resource(move || async move {
        list_all_effect_data()
            .await
            .map_err(|error| error.to_string())
    });
    let mut editor = use_signal(|| None::<EffectData>);
    let mut deleting = use_signal(|| None::<EffectData>);
    let mut busy = use_signal(|| false);
    let mut error = use_signal(|| None::<String>);
    let loaded = effects.read().clone();

    rsx! {
        section { class: "admin-page admin-data-page", "data-testid": "effects-page",
            div { class: "admin-page-header", style: "display:flex;",
                h2 { "效果管理" }
                div { class: "admin-actions",
                    button { class: "admin-btn", "data-testid": "effect-refresh", disabled: busy(),
                        onclick: move |_| effects.restart(), "刷新" }
                    button { class: "admin-btn admin-btn--primary", "data-testid": "effect-create", disabled: busy(),
                        onclick: move |_| { error.set(None); editor.set(Some(EffectData::default())); }, "新增效果" }
                }
            }
            p { class: "admin-field__help", "效果保存后，可在「技能数据」中绑定。当前支持能力阶级变化及伤害技命中后的异常状态。" }
            if editor().is_none() && deleting().is_none() {
                if let Some(message) = error() {
                    p { role: "alert", "data-testid": "effect-page-error", "{message}" }
                }
            }
            div { class: "admin-card data-table-card", style: "overflow:auto;",
                match loaded {
                    None => rsx! { p { role: "status", "正在加载效果…" } },
                    Some(Err(message)) => rsx! { p { role: "alert", "data-testid": "effect-load-error", "加载失败：{message}，请点击刷新重试。" } },
                    Some(Ok(rows)) => rsx! {
                        if rows.is_empty() { p { class: "empty-hint", "暂无效果，请点击新增效果。" } }
                        else {
                            table { class: "admin-table",
                                thead { tr { th { "ID" } th { "效果标识" } th { "效果类型" } th { "触发时机" } th { "描述" } th { "操作" } } }
                                tbody {
                                    for effect in rows {
                                        { let id = effect.id; let to_delete = effect.clone(); let hooks_text = effect.hooks.join(", "); rsx! {
                                            tr { key: "effect-{id}", "data-testid": "effect-row-{id}",
                                                td { "{id}" }
                                                td { "{effect.code}" }
                                                td { "{effect.params.label()}" }
                                                td { "{hooks_text}" }
                                                td { "{effect.description}" }
                                                td {
                                                    button { class: "admin-btn", "data-testid": "effect-edit-{id}", aria_label: "编辑效果 {id}", disabled: busy(),
                                                        onclick: move |_| {
                                                            busy.set(true); error.set(None);
                                                            spawn(async move {
                                                                match get_effect_data(id).await {
                                                                    Ok(data) => editor.set(Some(data)),
                                                                    Err(err) => error.set(Some(format!("加载效果失败：{err}"))),
                                                                }
                                                                busy.set(false);
                                                            });
                                                        }, "编辑" }
                                                    button { class: "admin-btn", "data-testid": "effect-delete-{id}", aria_label: "删除效果 {id}", disabled: busy(),
                                                        onclick: move |_| { error.set(None); deleting.set(Some(to_delete.clone())); }, "删除" }
                                                }
                                            }
                                        } }
                                    }
                                }
                            }
                        }
                    },
                }
            }
            if let Some(data) = editor() {
                EffectEditor { key: "editor-{data.id}", data, busy: busy(), error: error(),
                    on_close: move |_| { if !busy() { editor.set(None); error.set(None); } },
                    on_save: move |data| {
                        busy.set(true); error.set(None);
                        spawn(async move {
                            match save_effect_data(data).await {
                                Ok(saved) => {
                                    set_notice(AdminNoticeLevel::Success, format!("效果 #{} 已保存", saved.id));
                                    editor.set(None); effects.restart();
                                }
                                Err(err) => error.set(Some(format!("保存失败：{err}"))),
                            }
                            busy.set(false);
                        });
                    },
                }
            }
            if let Some(data) = deleting() {
                ActionModal { title: format!("删除效果 #{}", data.id),
                    on_close: move |_| { if !busy() { deleting.set(None); error.set(None); } },
                    p { "确定删除「{data.code}」？正在被技能引用的效果需要先解除绑定。" }
                    if let Some(message) = error() { p { role: "alert", "data-testid": "effect-delete-error", "{message}" } }
                    div { class: "admin-form-actions",
                        button { class: "admin-btn", "data-testid": "effect-cancel", disabled: busy(), onclick: move |_| deleting.set(None), "取消" }
                        button { class: "admin-btn admin-btn--primary", "data-testid": "effect-confirm-delete", disabled: busy(),
                            onclick: move |_| {
                                busy.set(true); error.set(None);
                                let id = data.id;
                                spawn(async move {
                                    match delete_effect_data(id).await {
                                        Ok(()) => { deleting.set(None); effects.restart(); set_notice(AdminNoticeLevel::Success, "效果已删除"); }
                                        Err(err) => error.set(Some(format!("删除失败：{err}"))),
                                    }
                                    busy.set(false);
                                });
                            }, "确认删除" }
                    }
                }
            }
        }
    }
}

#[component]
fn EffectChoice(
    id: &'static str,
    label: &'static str,
    value: String,
    options: Vec<(&'static str, &'static str)>,
    busy: bool,
    on_change: EventHandler<String>,
) -> Element {
    let missing = !options.iter().any(|(key, _)| *key == value);
    rsx! {
        div { class: "admin-field",
            label { class: "admin-field__label", r#for: id, "{label}" }
            select { class: "admin-input", id, "data-testid": id, value: value.clone(), disabled: busy,
                onchange: move |event| on_change.call(event.value()),
                if missing { option { value: value.clone(), selected: true, "{value}（已有配置）" } }
                for (key, text) in options { option { value: key, selected: key == value, "{text}" } }
            }
        }
    }
}

#[component]
fn EffectEditor(
    data: EffectData,
    busy: bool,
    error: Option<String>,
    on_save: EventHandler<EffectData>,
    on_close: EventHandler<()>,
) -> Element {
    let mut draft = use_signal(|| data);
    let current = draft();
    let mut version_input = use_signal(|| current.version.to_string());
    let mut stages_input = use_signal(|| match &current.params {
        EffectParams::StagesBoost { stages, .. } => stages.to_string(),
        _ => "1".into(),
    });
    let mut chance_input = use_signal(|| match &current.params {
        EffectParams::StatusInflict { chance, .. } => chance.to_string(),
        _ => "100".into(),
    });
    let number_error = if !version_input()
        .parse::<u64>()
        .is_ok_and(|value| value > 0 && value <= u32::MAX as u64)
    {
        Some("版本必须为 1 至 4294967295 的整数")
    } else {
        match current.params {
            EffectParams::StagesBoost { .. }
                if !stages_input()
                    .parse::<i8>()
                    .is_ok_and(|value| value != 0 && (-6..=6).contains(&value)) =>
            {
                Some("变化阶级必须为 -6 至 6 的非零整数")
            }
            EffectParams::StatusInflict { .. }
                if !chance_input().parse::<u8>().is_ok_and(|value| value <= 100) =>
            {
                Some("概率必须为 0 至 100 的整数")
            }
            _ => None,
        }
    };
    let validation = number_error
        .or_else(|| current.validate().err())
        .or_else(|| current.editor_error());
    let hooks_value = if current.hooks.len() == 1 {
        current.hooks[0].clone()
    } else {
        current.hooks.join(", ")
    };
    let hook_options = if matches!(current.params, EffectParams::StagesBoost { .. }) {
        vec![
            ("on_after_move", "出招后（威力为 0 的变化技）"),
            ("on_hit", "命中后（威力大于 0 的伤害技）"),
        ]
    } else {
        vec![("on_hit", "命中后（威力大于 0 的伤害技）")]
    };
    rsx! {
        ActionModal { title: if current.id == 0 { "新增效果".into() } else { format!("编辑效果 #{}", current.id) }, on_close,
            div { class: "admin-form-editor", "data-testid": "effect-editor",
                FormSection { title: "基本信息".to_string(),
                    Row {
                        Col { span: 6,
                            div { class: "admin-field",
                                label { class: "admin-field__label", r#for: "effect-code", "效果标识" }
                                input { class: "admin-input", id: "effect-code", "data-testid": "effect-code", value: current.code, maxlength: "40", disabled: busy,
                                    oninput: move |event| draft.write().code = event.value() }
                            }
                        }
                        Col { span: 6,
                            EffectChoice { id: "effect-kind", label: "来源", value: current.kind, options: vec![("move", "招式")], busy,
                                on_change: move |value| draft.write().kind = value }
                        }
                    }
                    div { class: "admin-field",
                        label { class: "admin-field__label", r#for: "effect-description", "描述" }
                        textarea { class: "admin-textarea", id: "effect-description", "data-testid": "effect-description", rows: "2", maxlength: "255", value: current.description, disabled: busy,
                            oninput: move |event| draft.write().description = event.value() }
                    }
                    div { class: "admin-field",
                        label { class: "admin-field__label", r#for: "effect-version", "版本" }
                        input { class: "admin-input", id: "effect-version", "data-testid": "effect-version", r#type: "number", min: "1", value: version_input(), disabled: busy,
                            oninput: move |event| { let text = event.value(); if let Ok(value) = text.parse() { draft.write().version = value; } version_input.set(text); } }
                    }
                }
                FormSection { title: "效果设置".to_string(),
                    EffectChoice { id: "effect-type", label: "效果类型", value: current.params.code().to_string(),
                        options: vec![("stages_boost", "能力阶级变化"), ("status_inflict", "附加异常状态")], busy,
                        on_change: move |value: String| {
                            let mut data = draft.write();
                            if value == "status_inflict" {
                                data.params = EffectParams::StatusInflict { status: "burn".into(), chance: 100 };
                                chance_input.set("100".into());
                                data.hooks = vec!["on_hit".into()];
                            } else {
                                data.params = EffectParams::default();
                                stages_input.set("1".into());
                                data.hooks = vec!["on_after_move".into()];
                            }
                        }
                    }
                    fieldset { class: "admin-field",
                        legend { class: "admin-field__label", "触发时机" }
                        if !hook_options.iter().any(|(key, _)| *key == hooks_value) {
                            p { role: "status", "已有配置：{hooks_value}。请选择当前支持的单一时机。" }
                        }
                        for (key, text) in hook_options {
                            label {
                                input { r#type: "radio", name: "effect-hook", id: "effect-hook-{key}", "data-testid": "effect-hook-{key}",
                                    checked: hooks_value == key, disabled: busy,
                                    onchange: move |_| draft.write().hooks = vec![key.into()] }
                                "{text}"
                            }
                        }
                    }
                    match current.params {
                        EffectParams::StagesBoost { stat, target, .. } => rsx! {
                            EffectChoice { id: "effect-stat", label: "能力", value: stat, options: EFFECT_STATS.to_vec(), busy,
                                on_change: move |value| { if let EffectParams::StagesBoost { stat, .. } = &mut draft.write().params { *stat = value; } } }
                            div { class: "admin-field",
                                label { class: "admin-field__label", r#for: "effect-stages", "变化阶级（-6 至 6，不能为 0）" }
                                input { class: "admin-input", id: "effect-stages", "data-testid": "effect-stages", r#type: "number", min: "-6", max: "6", step: "1", value: stages_input(), disabled: busy,
                                    oninput: move |event| { let text = event.value(); if let Ok(value) = text.parse() { if let EffectParams::StagesBoost { stages, .. } = &mut draft.write().params { *stages = value; } } stages_input.set(text); } }
                            }
                            EffectChoice { id: "effect-target", label: "目标", value: target, options: vec![("self", "自己"), ("opponent", "对手")], busy,
                                on_change: move |value| { if let EffectParams::StagesBoost { target, .. } = &mut draft.write().params { *target = value; } } }
                        },
                        EffectParams::StatusInflict { status, .. } => rsx! {
                            EffectChoice { id: "effect-status", label: "异常状态", value: status,
                                options: EFFECT_STATUSES.iter().copied().filter(|(key, _)| *key != "confusion").collect(), busy,
                                on_change: move |value| { if let EffectParams::StatusInflict { status, .. } = &mut draft.write().params { *status = value; } } }
                            div { class: "admin-field",
                                label { class: "admin-field__label", r#for: "effect-chance", "触发概率（%）" }
                                input { class: "admin-input", id: "effect-chance", "data-testid": "effect-chance", r#type: "number", min: "0", max: "100", value: chance_input(), disabled: busy,
                                    oninput: move |event| { let text = event.value(); if let Ok(value) = text.parse() { if let EffectParams::StatusInflict { chance, .. } = &mut draft.write().params { *chance = value; } } chance_input.set(text); } }
                            }
                            p { class: "admin-field__help", "需要威力大于 0 的伤害技；命中后对对手施加状态，已有异常状态时不会覆盖。" }
                        },
                        EffectParams::Unsupported(value) => rsx! {
                            p { role: "alert", "此模板包含尚未支持的配置，原始数据已保留。请选择已支持的效果类型后再保存。" }
                            pre { "{value}" }
                        },
                    }
                }
                if let Some(message) = error { p { role: "alert", "data-testid": "effect-error", "{message}" } }
                else if let Some(message) = validation { p { role: "status", "data-testid": "effect-validation", "{message}" } }
                div { class: "admin-form-actions",
                    button { class: "admin-btn", "data-testid": "effect-cancel", disabled: busy, onclick: move |_| on_close.call(()), "取消" }
                    button { class: "admin-btn admin-btn--primary", "data-testid": "effect-save", disabled: busy || validation.is_some(),
                        onclick: move |_| on_save.call(draft()), "保存效果" }
                }
            }
        }
    }
}
