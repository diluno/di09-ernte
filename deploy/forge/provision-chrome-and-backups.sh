#!/usr/bin/env bash
set -euo pipefail

sudo apt-get update
sudo apt-get install -y --no-install-recommends \
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

sudo apt-get update
sudo apt-get install -y --no-install-recommends google-chrome-stable

echo
echo "Set this in Forge environment:"
echo "BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome-stable"
