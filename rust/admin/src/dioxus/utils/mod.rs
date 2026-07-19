pub mod api;
pub mod clipboard;
pub mod code_editor;
pub mod json_tree;

use wasm_bindgen::JsCast;

#[allow(dead_code)]
pub fn preload_payload_size() -> usize {
    let Some(window) = web_sys::window() else {
        return 0;
    };
    let Some(document) = window.document() else {
        return 0;
    };
    let Some(element) = document.get_element_by_id("entry_preload_data") else {
        return 0;
    };
    match element.dyn_into::<web_sys::HtmlTextAreaElement>() {
        Ok(textarea) => textarea.value().len(),
        Err(_) => 0,
    }
}
