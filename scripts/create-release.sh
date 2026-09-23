#!/usr/bin/env bash
set -euo pipefail

APP_ID="user_groups_hzs"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
RELEASE_DIR="${APP_DIR}/release"

echo "==> Building frontend assets for production..."
cd "${APP_DIR}"
npm run build

echo "==> Cleaning up obsolete chunk files..."
python3 -c "
import glob, os
keep = {
    'user_groups_hzs-main.css',
    'user_groups_hzs-admin-settings.css',
    'admin-settings-CxseCR_S.chunk.css',
    'style-ClC9qrHl.chunk.css',
    'main-DJmGZoe9.chunk.css'
}
for f in glob.glob('css/*.chunk.css'):
    basename = os.path.basename(f)
    if basename not in keep:
        try:
            os.remove(f)
        except OSError:
            pass
"

echo "==> Preparing release directory..."
mkdir -p "${RELEASE_DIR}"
rm -f "${RELEASE_DIR}/${APP_ID}.tar.gz"*

echo "==> Packaging ${APP_ID}.tar.gz..."
tar --exclude-vcs \
    --exclude='./node_modules' \
    --exclude='./src' \
    --exclude='./tests' \
    --exclude='./scratch' \
    --exclude='./release' \
    --exclude='./scripts' \
    --exclude='./vendor-bin' \
    --exclude='./vendor' \
    --exclude='./.git*' \
    --exclude='./.github*' \
    --exclude='./.eslintrc*' \
    --exclude='./.nvmrc' \
    --exclude='./.php-cs-fixer*' \
    --exclude='./*.log' \
    --exclude='./*.map' \
    --exclude='./rector.php' \
    --exclude='./tsconfig.json' \
    --exclude='./vite.config.ts' \
    --exclude='./package*.json' \
    --exclude='./composer*.json' \
    --exclude='./composer*.lock' \
    --exclude='./stylelint*' \
    --exclude='./psalm.xml' \
    --transform "s,^\.,${APP_ID}," \
    -czf "${RELEASE_DIR}/${APP_ID}.tar.gz" .

echo "==> Generating checksums..."
cd "${RELEASE_DIR}"
sha256sum "${APP_ID}.tar.gz" > "${APP_ID}.tar.gz.sha256"
md5sum "${APP_ID}.tar.gz" > "${APP_ID}.tar.gz.md5"

echo "==> Release archive created successfully:"
ls -lh "${RELEASE_DIR}/${APP_ID}.tar.gz"
cat "${RELEASE_DIR}/${APP_ID}.tar.gz.sha256"

