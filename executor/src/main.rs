use axum::{extract::State, http::StatusCode, response::IntoResponse, routing::get, Json, Router};
use serde::Serialize;
use std::{env, net::SocketAddr, sync::Arc};
use tracing::info;

const VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Clone)]
struct AppState {
    version: &'static str,
}

#[derive(Serialize)]
struct ServiceStatus {
    status: &'static str,
    service: &'static str,
    version: &'static str,
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let bind = env::var("GWM_EXECUTOR_BIND").unwrap_or_else(|_| "0.0.0.0:8787".to_owned());
    let address: SocketAddr = bind
        .parse()
        .expect("GWM_EXECUTOR_BIND must be a valid socket address");
    let state = Arc::new(AppState { version: VERSION });
    let app = Router::new()
        .route("/health", get(health))
        .route("/v1/version", get(version))
        .with_state(state);

    info!(%address, version = VERSION, "Git Web Manager executor started");
    let listener = tokio::net::TcpListener::bind(address)
        .await
        .expect("executor could not bind its listener");
    axum::serve(listener, app)
        .await
        .expect("executor server failed");
}

async fn health(State(state): State<Arc<AppState>>) -> impl IntoResponse {
    (
        StatusCode::OK,
        Json(ServiceStatus {
            status: "ok",
            service: "git-web-manager-executor",
            version: state.version,
        }),
    )
}

async fn version(State(state): State<Arc<AppState>>) -> Json<ServiceStatus> {
    Json(ServiceStatus {
        status: "ok",
        service: "git-web-manager-executor",
        version: state.version,
    })
}
