FROM rust:1.82-bookworm AS builder
WORKDIR /src
COPY Cargo.toml Cargo.lock* ./
COPY src ./src
RUN cargo build --release --locked

FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --create-home --uid 10001 executor
COPY --from=builder /src/target/release/git-web-manager-executor /usr/local/bin/git-web-manager-executor
USER executor
EXPOSE 8787
HEALTHCHECK --interval=15s --timeout=3s --start-period=10s --retries=3 CMD curl --fail --silent http://127.0.0.1:8787/health || exit 1
ENTRYPOINT ["/usr/local/bin/git-web-manager-executor"]
