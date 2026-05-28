#!/usr/bin/env bash
set -euo pipefail

apt_update() {
  local attempt

  for attempt in 1 2 3; do
    if sudo apt-get -o Acquire::Retries=3 update; then
      return 0
    fi

    echo "apt-get update failed (attempt ${attempt}/3). Clearing package lists before retry..."
    sudo rm -rf /var/lib/apt/lists/*
    sleep $((attempt * 10))
  done

  cat >&2 <<'EOF'
apt-get update is still failing.

If the error says "File has unexpected size" or "Mirror sync in progress",
the Ubuntu mirror is temporarily inconsistent. Wait a few minutes and rerun
this script, or switch the server from the DigitalOcean Ubuntu mirror to the
main Ubuntu archive before rerunning.
EOF

  return 1
}

apt_install() {
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

apt_update
apt_install \
  ca-certificates \
  curl \
  default-mysql-client \
  fonts-liberation \
  gnupg \
  libatk-bridge2.0-0 \
  libgbm1 \
  libnss3 \
  libxkbcommon0

curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
  | gpg --dearmor \
  | sudo tee /usr/share/keyrings/google-linux-signing-keyring.gpg >/dev/null

echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-linux-signing-keyring.gpg] http://dl.google.com/linux/chrome/deb/ stable main" \
  | sudo tee /etc/apt/sources.list.d/google-chrome.list >/dev/null

apt_update
apt_install google-chrome-stable

echo
echo "Set this in Forge environment:"
echo "BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome-stable"
