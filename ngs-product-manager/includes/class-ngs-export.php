<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGS_Export {

    /**
     * CSV column headers — MUST match the POS import template exactly (37 columns).
     *
     * @var string[]
     */
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

    /**
     * Export products as a CSV file and exit.
     *
     * @param int[] $product_ids Optional list of product IDs to export.
     *                           If empty, all products are exported.
     */
    public static function export( array $product_ids = [] ) {
        $products = self::get_products_for_export( $product_ids );

        // Send CSV headers
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="ngs-products-' . gmdate( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, self::$columns );

        foreach ( $products as $p ) {
            if ( $p['product_type'] === 'variable' ) {
                $vars   = self::get_variations( (int) $p['id'] );
                $vals   = implode( '|', array_map( 'trim', array_column( $vars, 'variation_value' ) ) );
                $skus   = implode( '|', array_map( 'trim', array_column( $vars, 'sku' ) ) );
                $pp     = implode( '|', array_column( $vars, 'purchase_price' ) );
                $sp     = implode( '|', array_column( $vars, 'selling_price' ) );
                $stocks = implode( '|', array_column( $vars, 'opening_stock' ) );

                fputcsv( $output, [
                    $p['name'],
                    $p['brand'],
                    $p['unit'],
                    $p['category'],
                    $p['sub_category'],
                    $p['sku'],
                    $p['barcode_type'] ?: 'C128',
                    $p['manage_stock'],
                    $p['alert_qty'] ?? '',
                    $p['expires_in'] ?? '',
                    $p['expiry_unit'] ?? '',
                    $p['applicable_tax'] ?? '',
                    $p['tax_type'],
                    'variable',
                    $p['variation_name'],
                    $vals,
                    $skus,
                    $pp,
                    '',
                    '',
                    $sp,
                    $stocks,
                    $p['product_locations'] ?? '',
                    '',
                    0,
                    $p['weight'] ?? '',
                    '',
                    '',
                    '',
                    $p['image_url'] ?? '',
                    $p['description'] ?? '',
                    '',
                    '',
                    '',
                    '',
                    $p['not_for_selling'] ?? 0,
                    '',
                ] );
            } else {
                fputcsv( $output, [
                    $p['name'],
                    $p['brand'],
                    $p['unit'],
                    $p['category'],
                    $p['sub_category'],
                    $p['sku'],
                    $p['barcode_type'] ?: 'C128',
                    $p['manage_stock'],
                    $p['alert_qty'] ?? '',
                    $p['expires_in'] ?? '',
                    $p['expiry_unit'] ?? '',
                    $p['applicable_tax'] ?? '',
                    $p['tax_type'],
                    'single',
                    '',
                    '',
                    '',
                    $p['purchase_price'],
                    '',
                    '',
                    $p['selling_price'],
                    $p['opening_stock'],
                    $p['product_locations'] ?? '',
                    '',
                    0,
                    $p['weight'] ?? '',
                    '',
                    '',
                    '',
                    $p['image_url'] ?? '',
                    $p['description'] ?? '',
                    '',
                    '',
                    '',
                    '',
                    $p['not_for_selling'] ?? 0,
                    '',
                ] );
            }
        }

        fclose( $output );
        exit;
    }

    /**
     * Fetch products for export. If $ids is non-empty, only those products are fetched.
     *
     * @param int[] $ids
     * @return array[]
     */
    private static function get_products_for_export( array $ids = [] ) {
        global $wpdb;

        if ( ! empty( $ids ) ) {
            $ids        = array_map( 'intval', $ids );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ngs_products WHERE id IN ($placeholders) ORDER BY name ASC",
                $ids
            );
        } else {
            $sql = "SELECT * FROM {$wpdb->prefix}ngs_products ORDER BY name ASC";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Fetch variations for a given product ID (sorted).
     *
     * @param int $product_id
     * @return array[]
     */
    private static function get_variations( int $product_id ) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ngs_variations WHERE product_id = %d ORDER BY sort_order ASC, id ASC",
                $product_id
            ),
            ARRAY_A
        ) ?: [];
    }
}
