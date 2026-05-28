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

  if switch_digitalocean_ubuntu_mirror; then
    for attempt in 1 2; do
      if sudo apt-get -o Acquire::Retries=3 update; then
        return 0
      fi

      echo "apt-get update failed after mirror switch (attempt ${attempt}/2). Clearing package lists before retry..."
      sudo rm -rf /var/lib/apt/lists/*
      sleep $((attempt * 10))
    done
  fi

  cat >&2 <<'EOF'
apt-get update is still failing.

If the error says "File has unexpected size" or "Mirror sync in progress",
the Ubuntu mirror is temporarily inconsistent. Wait a few minutes and rerun
this script, or switch the server from the DigitalOcean Ubuntu mirror to the
main Ubuntu archive before rerunning.
EOF

  return 1
}

switch_digitalocean_ubuntu_mirror() {
  local file
  local files=()
  local stamp

  mapfile -t files < <(sudo grep -rl 'mirrors.digitalocean.com/ubuntu' /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null || true)

  if [ "${#files[@]}" -eq 0 ]; then
    return 1
  fi

  stamp="$(date +%Y%m%d%H%M%S)"
  echo "DigitalOcean Ubuntu mirror still looks inconsistent; switching Ubuntu apt sources to archive.ubuntu.com..."

  for file in "${files[@]}"; do
    sudo cp "$file" "${file}.backup-${stamp}"
    sudo sed -i 's|http://mirrors.digitalocean.com/ubuntu|http://archive.ubuntu.com/ubuntu|g' "$file"
  done

  sudo rm -rf /var/lib/apt/lists/*
  return 0
}

apt_install() {
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

install_atk_bridge() {
  if apt-cache show libatk-bridge2.0-0t64 >/dev/null 2>&1; then
    apt_install libatk-bridge2.0-0t64
    return
  fi

  apt_install libatk-bridge2.0-0
}

ensure_mysqldump() {
  if command -v mysqldump >/dev/null 2>&1; then
    echo "mysqldump already installed at $(command -v mysqldump); skipping MySQL client package."
    return
  fi

  echo "mysqldump not found; trying MySQL client packages for backup support..."

  if apt_install mysql-client; then
    return
  fi

  if apt_install default-mysql-client; then
    return
  fi

  cat >&2 <<'EOF'
WARNING: Could not install a MySQL client package.
Chrome provisioning will continue, but php artisan ernte:backup requires
mysqldump. Install a compatible MySQL client manually before relying on backups.
EOF
}

apt_update
apt_install \
  ca-certificates \
  curl \
  fonts-liberation \
  gnupg \
  libgbm1 \
  libnss3 \
  libxkbcommon0

install_atk_bridge
ensure_mysqldump

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
