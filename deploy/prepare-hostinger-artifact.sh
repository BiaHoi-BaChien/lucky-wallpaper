#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "Usage: $0 <artifact-directory>" >&2
    exit 64
fi

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact_parent="$(dirname "$1")"
artifact_name="$(basename "$1")"

case "$artifact_name" in
    lucky-wallpaper-*)
        ;;
    *)
        echo "Artifact directory name must start with lucky-wallpaper-: $artifact_name" >&2
        exit 64
        ;;
esac

mkdir -p "$artifact_parent"
artifact_parent="$(cd "$artifact_parent" && pwd)"

if [ "$artifact_parent" = "/" ]; then
    echo "Artifact directory cannot be created directly below the filesystem root." >&2
    exit 64
fi

artifact_directory="$artifact_parent/$artifact_name"

if [ -L "$artifact_directory" ]; then
    echo "Artifact directory cannot be a symbolic link: $artifact_directory" >&2
    exit 64
fi

case "$artifact_directory/" in
    "$repository_root/"*)
        echo "Artifact directory must be outside the repository: $artifact_directory" >&2
        exit 64
        ;;
esac

if [ ! -f "$repository_root/vendor/autoload.php" ]; then
    echo "Missing vendor/autoload.php. Run the production Composer install first." >&2
    exit 1
fi

if [ ! -f "$repository_root/public/build/manifest.json" ]; then
    echo "Missing public/build/manifest.json. Build the production assets first." >&2
    exit 1
fi

mkdir -p "$artifact_directory"

rsync -a --delete --delete-excluded \
    --exclude='/.env*' \
    --exclude='/.git/' \
    --exclude='/.github/' \
    --exclude='/.phpunit.result.cache' \
    --exclude='/*.db' \
    --exclude='/*.sqlite' \
    --exclude='/*.sqlite3' \
    --exclude='/lucky_wallpaper' \
    --exclude='/bootstrap/cache/*.php' \
    --exclude='/deploy/' \
    --exclude='/docs/' \
    --exclude='/node_modules/' \
    --exclude='/public/' \
    --exclude='/storage/' \
    --exclude='/tests/' \
    "$repository_root/" "$artifact_directory/"

rsync -a \
    --exclude='/.htaccess' \
    --exclude='/hot' \
    "$repository_root/public/" "$artifact_directory/"

install -m 0644 "$repository_root/.htaccess" "$artifact_directory/.htaccess"
install -m 0644 "$repository_root/deploy/hostinger/index.php" "$artifact_directory/index.php"

echo "Prepared Hostinger artifact at $artifact_directory"
