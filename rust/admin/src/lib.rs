#[cfg(target_arch = "wasm32")]
pub(crate) mod config;

#[cfg(target_arch = "wasm32")]
pub(crate) mod dioxus;

#[cfg(target_arch = "wasm32")]
mod web_entry;

#[cfg(target_arch = "wasm32")]
pub use web_entry::*;
