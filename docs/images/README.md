# Screenshots

Three images, referenced from the project README. All are taken from the Docker
demo catalogue in `docker-compose.yml`, never from a live install, so nothing in
them is confidential.

| File | Shows | How to reproduce |
|---|---|---|
| `picker-tag-filters.png` | The in-document picker: category breadcrumb, the *Refine by* sidebar grouped into Thread Type / Thread Size / Material, one tag applied, two items ticked with their chips in the footer | Open a draft proposal → **Browse catalog** → *Fittings* → click the **Brass** tag → tick two rows |
| `catalogue-page.png` | The standalone catalogue page: folder grid, the *Refine by* band above the results, product cards with price and stock | **Products \| Services → Products → Catalog**, then open *Fittings* |
| `product-list-strip.png` | The category strip above Dolibarr's own product list, which filters by a parent and its whole subtree | Enable **Category filter on product list** in setup, then open **Products \| Services → Products** |

Capture the browser viewport at roughly **1500×800** so the picker modal is shown
at its full width without the page chrome dominating. PNG, and please keep each
file under about 400 KB.

The demo catalogue deliberately contains items withdrawn from sale (`FIT-3`,
`FIT-5`) to exercise the *Show items not for sale* switch. Leave that switch off
for these shots unless the point is the switch itself.
