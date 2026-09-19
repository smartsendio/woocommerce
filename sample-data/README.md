# Demo store sample data

Vendored copy of the WooCommerce core sample products, used by
`bin/setup-local-dev.sh` to seed the local dev store so it looks like a real
webshop (product grid with images, categories, variations) in manual testing
and documentation screenshots.

- `products.csv` — derived from the `sample_products.csv` bundled with the
  WooCommerce plugin, enriched for our Danish demo store: weights/dimensions
  converted to kg/cm, prices converted to realistic whole-DKK amounts, and
  image URLs rewritten to bare filenames matched against the media library.
- `images/` — the product images referenced by the CSV, downloaded from
  WooCommerce's sample-data CDN. Committed so store seeding is offline and
  deterministic.

**Do not edit `products.csv` or `images/` by hand.** To refresh from source
(e.g. after a WooCommerce update ships new sample data), run:

```bash
bin/update-sample-data.sh
```

and commit the result. The script prefers the CSV bundled with the WooCommerce
plugin in the local dev install (version-matched) and falls back to the
WooCommerce GitHub repository; all enrichment rules live in that script.
