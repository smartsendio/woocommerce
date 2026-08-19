#!/usr/bin/env bash
#
# Regenerate the committed demo-store sample data in sample-data/ from the
# WooCommerce core sample products.
#
# Source: the sample_products.csv bundled with the WooCommerce plugin in the
# local dev install (version-matched), falling back to the WooCommerce GitHub
# repository (trunk). The product images referenced by the CSV are downloaded
# once into sample-data/images/ so that seeding a store afterwards is fully
# offline and deterministic.
#
# The CSV is enriched for our Danish demo store while staying diffable against
# its source:
#   - Weight (lbs) / Length|Width|Height (in) -> kg / cm (values converted)
#   - Prices multiplied into realistic whole DKK amounts (source is USD-ish)
#   - Image URLs rewritten to bare filenames (matched against the media
#     library, which bin/setup-local-dev.sh pre-populates from sample-data/images/)
#
# Requirements: bash, curl, python3. Only needed when refreshing the committed
# sample data - bin/setup-local-dev.sh consumes the committed output.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$REPO_ROOT/sample-data"
IMAGES_DIR="$OUT_DIR/images"
WP_DEV_PATH="${WP_DEV_PATH:-$REPO_ROOT/local-dev/wordpress}"

LOCAL_CSV="$WP_DEV_PATH/wp-content/plugins/woocommerce/sample-data/sample_products.csv"
REMOTE_CSV="https://raw.githubusercontent.com/woocommerce/woocommerce/trunk/plugins/woocommerce/sample-data/sample_products.csv"

log() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

mkdir -p "$IMAGES_DIR"

SRC_CSV="$(mktemp)"
trap 'rm -f "$SRC_CSV"' EXIT
if [[ -f "$LOCAL_CSV" ]]; then
    log "Using sample_products.csv from the local WooCommerce install"
    cp "$LOCAL_CSV" "$SRC_CSV"
else
    log "Local install not found - downloading sample_products.csv from GitHub (trunk)"
    curl -fsSL "$REMOTE_CSV" -o "$SRC_CSV"
fi

log "Transforming CSV (metric units, DKK prices, local image filenames)"
python3 - "$SRC_CSV" "$OUT_DIR/products.csv" "$OUT_DIR/image-urls.txt" <<'PY'
import csv, sys
from urllib.parse import urlparse
from os.path import basename

src, dst, urls_out = sys.argv[1], sys.argv[2], sys.argv[3]

LBS_TO_KG = 0.453592
IN_TO_CM = 2.54
USD_TO_DKK = 7  # rough, rounded to whole kroner for realistic-looking prices

HEADER_RENAMES = {
    "Weight (lbs)": "Weight (kg)",
    "Length (in)": "Length (cm)",
    "Width (in)": "Width (cm)",
    "Height (in)": "Height (cm)",
}

def convert(value, factor, decimals):
    value = value.strip()
    if not value:
        return value
    out = round(float(value) * factor, decimals)
    return f"{out:g}"

with open(src, encoding="utf-8-sig", newline="") as f:
    reader = csv.DictReader(f)
    fieldnames = [HEADER_RENAMES.get(name, name) for name in reader.fieldnames]
    rows = []
    image_urls = []
    for row in reader:
        row = {HEADER_RENAMES.get(k, k): v for k, v in row.items()}
        row["Weight (kg)"] = convert(row["Weight (kg)"], LBS_TO_KG, 2)
        for dim in ("Length (cm)", "Width (cm)", "Height (cm)"):
            row[dim] = convert(row[dim], IN_TO_CM, 1)
        for price in ("Regular price", "Sale price"):
            row[price] = convert(row[price], USD_TO_DKK, 0)
        images = [u.strip() for u in row["Images"].split(",") if u.strip()]
        image_urls.extend(images)
        row["Images"] = ", ".join(basename(urlparse(u).path) for u in images)
        rows.append(row)

with open(dst, "w", encoding="utf-8", newline="") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(rows)

# Deduplicated download list for the shell part of this script.
with open(urls_out, "w", encoding="utf-8") as f:
    f.write("\n".join(dict.fromkeys(image_urls)) + "\n")

print(f"    {len(rows)} products -> {dst}")
PY

log "Downloading product images"
while IFS= read -r url; do
    file="$IMAGES_DIR/$(basename "$url")"
    if [[ -f "$file" ]]; then
        echo "    exists: $(basename "$url")"
    else
        echo "    fetch:  $(basename "$url")"
        curl -fsSL "$url" -o "$file"
    fi
done < "$OUT_DIR/image-urls.txt"
rm -f "$OUT_DIR/image-urls.txt"

log "Done. Review and commit the changes in sample-data/"
