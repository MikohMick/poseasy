# Nakuru Gift Shop — WordPress Product Manager Plugin Spec

## Overview

A WordPress plugin that adds a dedicated mobile-optimized product management dashboard inside the WordPress admin. The shop owner can add, edit, search, delete and export products in the exact CSV format expected by the POS system. Includes per-product and bulk barcode generation with the Nakuru Gift Shop label format.

---

## Plugin Details

- **Plugin Name**: Nakuru Gift Shop Product Manager
- **Plugin Slug**: `ngs-product-manager`
- **Text Domain**: `ngs-product-manager`
- **Min WordPress Version**: 5.8
- **Min PHP Version**: 7.4
- **License**: GPL-2.0+

---

## File Structure

```
ngs-product-manager/
├── ngs-product-manager.php          # Main plugin file (headers + bootstrap)
├── includes/
│   ├── class-ngs-db.php             # Database handler (custom tables)
│   ├── class-ngs-admin.php          # Admin menu + pages registration
│   ├── class-ngs-ajax.php           # All AJAX handlers
│   └── class-ngs-export.php         # CSV export logic
├── admin/
│   ├── css/
│   │   └── ngs-admin.css            # Mobile-first admin styles
│   └── js/
│       └── ngs-admin.js             # Vue 3 or vanilla JS app (CDN)
├── assets/
│   └── jsbarcode.min.js             # JsBarcode library (bundled)
└── readme.txt
```

---

## Database Schema

Use WordPress custom tables (created on plugin activation via `dbDelta`).

### Table: `{prefix}ngs_products`

```sql
CREATE TABLE {prefix}ngs_products (
  id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(500) NOT NULL,
  brand         VARCHAR(255) DEFAULT '',
  unit          VARCHAR(100) DEFAULT 'Pc(s)',
  category      VARCHAR(255) DEFAULT '',
  sub_category  VARCHAR(255) DEFAULT '',
  sku           VARCHAR(255) DEFAULT '',
  barcode_type  VARCHAR(50)  DEFAULT 'C128',
  manage_stock  TINYINT(1)   DEFAULT 1,
  alert_qty     INT          DEFAULT NULL,
  expires_in    INT          DEFAULT NULL,
  expiry_unit   VARCHAR(10)  DEFAULT '',
  applicable_tax VARCHAR(255) DEFAULT '',
  tax_type      VARCHAR(20)  DEFAULT 'inclusive',
  product_type  VARCHAR(20)  DEFAULT 'single',
  variation_name VARCHAR(255) DEFAULT '',
  purchase_price DECIMAL(15,2) DEFAULT 0,
  selling_price  DECIMAL(15,2) DEFAULT 0,
  opening_stock  INT          DEFAULT 0,
  image_url      TEXT         DEFAULT '',
  description    TEXT         DEFAULT '',
  weight         VARCHAR(100) DEFAULT '',
  not_for_selling TINYINT(1)  DEFAULT 0,
  product_locations VARCHAR(500) DEFAULT '',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY sku (sku),
  KEY category (category),
  KEY product_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `{prefix}ngs_variations`

```sql
CREATE TABLE {prefix}ngs_variations (
  id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     BIGINT(20) UNSIGNED NOT NULL,
  variation_value VARCHAR(500) NOT NULL,
  sku            VARCHAR(255) DEFAULT '',
  purchase_price DECIMAL(15,2) DEFAULT 0,
  selling_price  DECIMAL(15,2) DEFAULT 0,
  opening_stock  INT          DEFAULT 0,
  sort_order     INT          DEFAULT 0,
  PRIMARY KEY (id),
  KEY product_id (product_id),
  FOREIGN KEY (product_id) REFERENCES {prefix}ngs_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `{prefix}ngs_categories`

```sql
CREATE TABLE {prefix}ngs_categories (
  id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(255) NOT NULL,
  parent_id    BIGINT(20) UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY name_parent (name, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## WordPress Admin Integration

### Menu Registration

```php
// Main menu item in WP Admin sidebar
add_menu_page(
    'NGS Products',
    'NGS Products',
    'manage_options',
    'ngs-product-manager',
    'ngs_render_main_page',
    'dashicons-tag',
    30
);

// Submenus
add_submenu_page('ngs-product-manager', 'All Products',  'All Products',  'manage_options', 'ngs-product-manager',      'ngs_render_main_page');
add_submenu_page('ngs-product-manager', 'Add Product',   'Add Product',   'manage_options', 'ngs-add-product',           'ngs_render_add_page');
add_submenu_page('ngs-product-manager', 'Categories',    'Categories',    'manage_options', 'ngs-categories',            'ngs_render_categories_page');
add_submenu_page('ngs-product-manager', 'Export',        'Export / Barcodes', 'manage_options', 'ngs-export',           'ngs_render_export_page');
```

### Asset Enqueueing (admin only)

```php
wp_enqueue_style('ngs-admin', NGS_PLUGIN_URL . 'admin/css/ngs-admin.css');
wp_enqueue_script('jsbarcode', NGS_PLUGIN_URL . 'assets/jsbarcode.min.js', [], '3.11.5', true);
wp_enqueue_script('ngs-admin', NGS_PLUGIN_URL . 'admin/js/ngs-admin.js', ['jquery', 'jsbarcode'], NGS_VERSION, true);

// Pass data to JS
wp_localize_script('ngs-admin', 'NGS', [
    'ajax_url'   => admin_url('admin-ajax.php'),
    'nonce'      => wp_create_nonce('ngs_nonce'),
    'plugin_url' => NGS_PLUGIN_URL,
]);
```

---

## AJAX Endpoints

All endpoints verify nonce (`check_ajax_referer('ngs_nonce')`) and capability (`current_user_can('manage_options')`).

| Action | Method | Description |
|--------|--------|-------------|
| `ngs_get_products` | GET | List products with search/filter/pagination |
| `ngs_get_product` | GET | Single product by ID (with variations) |
| `ngs_save_product` | POST | Create or update product + variations |
| `ngs_delete_product` | POST | Delete product (and cascades variations) |
| `ngs_bulk_delete` | POST | Delete multiple products |
| `ngs_get_categories` | GET | All categories with sub-categories |
| `ngs_save_category` | POST | Create or update category/sub-category |
| `ngs_delete_category` | POST | Delete category |
| `ngs_export_csv` | GET | Generate and stream CSV download |
| `ngs_upload_image` | POST | Handle image upload via WP media |

### Example: `ngs_get_products` Response

```json
{
  "success": true,
  "data": {
    "products": [
      {
        "id": 1,
        "name": "Stanley Cups",
        "sku": "GS0246",
        "category": "Ladies Gifts",
        "product_type": "variable",
        "selling_price": 2500,
        "variations": [
          { "id": 1, "variation_value": "Black", "sku": "GS0246-01", "selling_price": 2500, "opening_stock": 3 },
          { "id": 2, "variation_value": "Cream",  "sku": "GS0246-02", "selling_price": 2500, "opening_stock": 2 }
        ]
      }
    ],
    "total": 350,
    "pages": 18
  }
}
```

---

## Pages & UI (Single-Page App inside WP Admin)

Build the frontend as a **Vue 3 (CDN)** or **vanilla JS** SPA rendered inside a single WP admin page wrapper `<div id="ngs-app"></div>`. All navigation happens via JS state — no page reloads.

### View 1: Product List

- Search input (debounced 300ms → AJAX)
- Filter by Category dropdown
- Filter by Type (All / Single / Variable)
- Product cards (mobile-optimized):
  - Product name + SKU badge
  - Category → Sub-category
  - Price (KSh) + Stock count
  - Variable badge showing variation count
  - Action buttons: ✏️ Edit | 🗑️ Delete | 🔖 Barcode
- Checkbox per card for bulk selection
- Bulk action bar (appears when items selected): Delete Selected | Export Selected | Print Barcodes
- Pagination (20 per page)
- **Add Product** floating action button (bottom-right, fixed position)
- **Export All** button in page header

---

### View 2: Add / Edit Product Form

Scrollable mobile form with collapsible sections:

#### Basic Info
- Product Name * 
- Brand (datalist autocomplete from existing brands)
- Unit * (select: Pc(s) / Kg / g / L / ml / Box / Set / Pair / Dozen / custom)
- Category * (select + "＋ New Category" inline)
- Sub Category (select filtered by category + "＋ New Sub-category" inline)
- SKU (text, placeholder: "Leave blank to auto-generate on POS")
- Image (WP Media Library picker button + URL input fallback + preview)
- Product Description (textarea)

#### Pricing & Type
- Product Type (pill toggle: Single ↔ Variable)
- Selling Price Tax Type (pill toggle: Inclusive ↔ Exclusive)

**If Single:**
- Purchase Price (Including Tax) *
- Selling Price
- Profit Margin % (auto-calculated live)

**If Variable:**
- Variation Name * (text: "Color", "Size", etc.)
- Variation table (dynamic rows):

  | Value | SKU | Purchase Price | Selling Price | Stock |
  |-------|-----|----------------|---------------|-------|
  | Red   | GS0001-01 | 2500 | 3000 | 5 |

  - ➕ Add Variation button
  - 🗑️ Remove row
  - Drag handle to reorder (Sortable.js or HTML5 drag)

#### Stock Management
- Manage Stock (toggle)
- If single + manage stock on:
  - Opening Stock
  - Alert Quantity

#### Advanced (collapsed on mobile by default)
- Barcode Type (default: C128)
- Applicable Tax (text)
- Expires In + unit (days/months)
- Weight
- Not For Selling (toggle)
- Product Locations
- Custom Field 1–4

#### Sticky Footer
- [Save Product] primary button
- [Cancel] link

#### Validation Rules (client + server)
- Name: required, non-empty
- Unit: required
- Selling Price Tax Type: required (`inclusive` or `exclusive`)
- If variable: Variation Name required, at least 1 variation with a value
- All variation pipe fields must have same count (validated before save AND before export)
- No empty `||` in pipe-separated fields (trim + filter empty)

---

### View 3: Category Manager

- List: Category name → expandable sub-categories
- Inline add: type + press Enter or click ➕
- Edit in place (click to edit)
- Delete (warns if products use it, shows count)
- No page reload — all AJAX

---

### View 4: Single Product Barcode

Modal or slide-up panel:

```
┌─────────────────────────────┐
│      Nakuru Gift Shop       │  ← bold
│    Stanley Cups             │
│    Black                    │  ← variation (if variable)
│    KSh 2,500                │
│  ┌───────────────────────┐  │
│  │  ▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌  │  │  ← JsBarcode SVG
│  │  ▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌▌  │  │
│  └───────────────────────┘  │
│        GS0246-01            │  ← monospace SKU
└─────────────────────────────┘
     [Print]  [← Prev] [Next →]
```

- For variable products: cycle through variations with Prev/Next
- Print button: `window.print()` — CSS `@media print` hides everything except the label

---

### View 5: Bulk Barcode Print Page

- Shows grid of all selected product barcode labels
- Same label format as single barcode view
- A4 layout: 3 columns × 8 rows = 24 per page
- [Print All] button
- [Back] button

---

## CSV Export (Critical — must be byte-perfect)

### Column Order (exact, no deviation)

```php
private static $columns = [
    'Product Name (Required)',
    'Brand (Optional)',
    'Unit (Required)',
    'Category (Optional)',
    'Sub category (Optional)',
    'SKU (Optional)',
    'Barcode Type (Optional, Default: C128)',
    'Manage Stock? (Required)',
    'Alert quantity (Optional)',
    'Expires in (Optional)',
    'Expiry Period Unit (Optional)',
    'Applicable Tax (Optional)',
    'Selling Price Tax Type (Required)',
    'Product Type (Required)',
    'Variation Name (Required if product type is variable)',
    'Variation Values (Required if product type is variable)',
    'Variation SKUs (Optional)',
    'Purchase Price (Including Tax) (Required if Purchase Price Excluding Tax is not given)',
    'Purchase Price (Excluding Tax) (Required if Purchase Price Including Tax is not given)',
    'Profit Margin % (Optional)',
    'Selling Price (Optional)',
    'Opening Stock (Optional)',
    'Opening stock location (Optional) If blank first business location will be used',
    'Expiry Date (Optional)',
    'Enable Product description, IMEI or Serial Number (Optional, Default: 0)',
    'Weight (Optional)',
    'Rack (Optional)',
    'Row (Optional)',
    'Position (Optional)',
    'Image (Optional)',
    'Product Description (Optional)',
    'Custom Field1 (Optional)',
    'Custom Field2 (Optional)',
    'Custom Field3 (Optional)',
    'Custom Field4 (Optional)',
    'Not for selling (Optional)',
    'Product locations (Optional)',
];
```

### Export PHP Logic

```php
public static function export(array $product_ids = []) {
    $products = self::get_products_for_export($product_ids);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ngs-products-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fputcsv($output, self::$columns);

    foreach ($products as $p) {
        if ($p['product_type'] === 'variable') {
            $vars   = self::get_variations($p['id']);
            $vals   = implode('|', array_map('trim', array_column($vars, 'variation_value')));
            $skus   = implode('|', array_map('trim', array_column($vars, 'sku')));
            $pp     = implode('|', array_column($vars, 'purchase_price'));
            $sp     = implode('|', array_column($vars, 'selling_price'));
            $stocks = implode('|', array_column($vars, 'opening_stock'));

            fputcsv($output, [
                $p['name'], $p['brand'], $p['unit'],
                $p['category'], $p['sub_category'],
                $p['sku'], $p['barcode_type'] ?: 'C128',
                $p['manage_stock'], $p['alert_qty'] ?? '',
                $p['expires_in'] ?? '', $p['expiry_unit'] ?? '',
                $p['applicable_tax'] ?? '',
                $p['tax_type'], 'variable',
                $p['variation_name'], $vals, $skus,
                $pp, '', '', $sp, $stocks,
                $p['product_locations'] ?? '', '',
                0, $p['weight'] ?? '', '', '', '',
                $p['image_url'] ?? '', $p['description'] ?? '',
                '', '', '', '',
                $p['not_for_selling'] ?? 0, '',
            ]);
        } else {
            fputcsv($output, [
                $p['name'], $p['brand'], $p['unit'],
                $p['category'], $p['sub_category'],
                $p['sku'], $p['barcode_type'] ?: 'C128',
                $p['manage_stock'], $p['alert_qty'] ?? '',
                $p['expires_in'] ?? '', $p['expiry_unit'] ?? '',
                $p['applicable_tax'] ?? '',
                $p['tax_type'], 'single',
                '', '', '',
                $p['purchase_price'], '', '', $p['selling_price'],
                $p['opening_stock'],
                $p['product_locations'] ?? '', '',
                0, $p['weight'] ?? '', '', '', '',
                $p['image_url'] ?? '', $p['description'] ?? '',
                '', '', '', '',
                $p['not_for_selling'] ?? 0, '',
            ]);
        }
    }

    fclose($output);
    exit;
}
```

### Export Rules
- Trim all pipe-separated values (no trailing/leading spaces)
- Never emit `null` — always `''` for empty optionals
- Validate before export: variation value count == price count == stock count
- If SKU is blank, leave blank (POS auto-generates — this prevents "SKU already exists" errors)
- Re-export same product → same SKU → POS updates instead of duplicating

---

## Print Styles

```css
@media print {
  #wpcontent, #adminmenuwrap, #wpadminbar,
  #wpfooter, .ngs-no-print { display: none !important; }

  #ngs-print-area {
    position: fixed;
    top: 0; left: 0;
    width: 210mm;
    margin: 0;
    padding: 0;
  }

  .ngs-label {
    width: 63mm;
    height: 35mm;
    border: 0.5px solid #bbb;
    padding: 2mm;
    page-break-inside: avoid;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    font-family: 'Arial', sans-serif;
  }

  .ngs-label-shop   { font-weight: bold; font-size: 8pt; }
  .ngs-label-name   { font-size: 7pt; text-align: center; }
  .ngs-label-price  { font-weight: bold; font-size: 9pt; }
  .ngs-label-sku    { font-family: monospace; font-size: 7pt; }
}
```

---

## Mobile Admin CSS Notes

Override WP admin styles for mobile:

```css
/* Make the admin page full-width on mobile */
@media (max-width: 782px) {
  #ngs-app {
    padding: 8px;
    margin: 0 -10px;
  }
  .ngs-card {
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  }
  .ngs-fab {
    position: fixed;
    bottom: 24px;
    right: 16px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #2271b1;
    color: white;
    font-size: 24px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.3);
    z-index: 9999;
    cursor: pointer;
  }
  input, select, textarea {
    font-size: 16px !important; /* Prevent iOS zoom */
    min-height: 44px;
  }
}
```

---

## Security Checklist

- [ ] All AJAX handlers verify nonce: `check_ajax_referer('ngs_nonce', 'nonce')`
- [ ] All AJAX handlers check capability: `current_user_can('manage_options')`
- [ ] All DB queries use `$wpdb->prepare()` — no raw SQL with user input
- [ ] All output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`
- [ ] CSV export streams via PHP — no temp files on disk
- [ ] Image uploads use WP media library (inherits WP security)
- [ ] Plugin activation/deactivation hooks properly registered

---

## Plugin Activation / Deactivation

```php
register_activation_hook(__FILE__, 'ngs_activate');
function ngs_activate() {
    NGS_DB::create_tables();      // Run dbDelta
    add_option('ngs_version', NGS_VERSION);
}

register_deactivation_hook(__FILE__, 'ngs_deactivate');
function ngs_deactivate() {
    // Do NOT drop tables on deactivate (data preservation)
}

// Uninstall: handle via uninstall.php (drop tables only on uninstall)
```

---

## Claude Code Instructions

1. Build the full plugin from scratch in the `ngs-product-manager/` folder structure above
2. Main plugin file must have proper WordPress plugin headers
3. Use `$wpdb` for all database operations — no raw PDO/MySQLi
4. Frontend: Vue 3 via CDN (`https://unpkg.com/vue@3/dist/vue.global.prod.js`) inside `ngs-admin.js` — renders into `<div id="ngs-app">`
5. JsBarcode: bundle the minified file in `assets/jsbarcode.min.js`
6. All AJAX calls use `jQuery.ajax` or `fetch` with the localized `NGS.ajax_url` and `NGS.nonce`
7. CSV export must match column spec **exactly** — this is the most critical requirement
8. Mobile-first CSS — test at 375px width
9. The UI should feel like a **premium mobile app** — clean cards, smooth transitions, proper touch targets (min 44px)
10. Include a `readme.txt` with installation instructions
11. Zip the plugin folder so it can be uploaded directly via WP Admin → Plugins → Add New → Upload
