#!/usr/bin/env bash
# Build production musl binary for abel-products-daemon.
#
# Output: ../bin/products-daemon-linux-x86_64 (deploy path, shipped in vendor package)
#
# Prerequisites (one-time host setup):
#   rustup target add x86_64-unknown-linux-musl
#   sudo apt install musl-tools                  # provides musl-gcc
#
# Target CPU: x86-64-v3 (Haswell+, 2013). Verify on production server before first deploy:
#   grep -o 'avx2\|bmi2\|fma' /proc/cpuinfo | sort -u    # must list all three
#
# If production CPU is older, override via TARGET_CPU env var:
#   TARGET_CPU=x86-64-v2 ./build-release.sh     # SSE4.2 only (2009+, universally safe)

set -euo pipefail

cd "$(dirname "$0")"

TARGET_CPU="${TARGET_CPU:-x86-64-v3}"
TARGET="x86_64-unknown-linux-musl"
BIN_NAME="abel-products-daemon"
DEPLOY_BIN="products-daemon-linux-x86_64"

export RUSTFLAGS="-C target-cpu=${TARGET_CPU}"
export CC_x86_64_unknown_linux_musl="musl-gcc"
export CARGO_TARGET_X86_64_UNKNOWN_LINUX_MUSL_LINKER="musl-gcc"

echo "==> building ${BIN_NAME} (target=${TARGET}, cpu=${TARGET_CPU}, profile=release)"
cargo build --release --target "${TARGET}"

SRC="target/${TARGET}/release/${BIN_NAME}"
VENDOR_BIN="../bin/${DEPLOY_BIN}"

cp "${SRC}" "${VENDOR_BIN}"
chmod +x "${VENDOR_BIN}"

SIZE=$(stat -c%s "${VENDOR_BIN}")
echo "==> deployed $(numfmt --to=iec "${SIZE}") to ${VENDOR_BIN}"

if command -v file >/dev/null 2>&1; then
	file "${VENDOR_BIN}"
fi
