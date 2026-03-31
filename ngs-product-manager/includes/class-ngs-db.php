<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGS_DB {

    /**
     * Create plugin database tables using dbDelta.
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        $sql[] = "CREATE TABLE {$wpdb->prefix}ngs_products (
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
        ) ENGINE=InnoDB $charset_collate;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}ngs_variations (
          id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id     BIGINT(20) UNSIGNED NOT NULL,
          variation_value VARCHAR(500) NOT NULL,
          sku            VARCHAR(255) DEFAULT '',
          purchase_price DECIMAL(15,2) DEFAULT 0,
          selling_price  DECIMAL(15,2) DEFAULT 0,
          opening_stock  INT          DEFAULT 0,
          sort_order     INT          DEFAULT 0,
          PRIMARY KEY (id),
          KEY product_id (product_id)
        ) ENGINE=InnoDB $charset_collate;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}ngs_categories (
          id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          name         VARCHAR(255) NOT NULL,
          parent_id    BIGINT(20) UNSIGNED DEFAULT NULL,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY name_parent (name, parent_id)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    /**
     * Query products with optional search, category, type, pagination.
     *
     * @param array $args {
     *   string $search     Full-text search on name/sku.
     *   string $category   Filter by category name.
     *   string $type       Filter by product_type ('single'|'variable').
     *   int    $page       Current page (1-based).
     *   int    $per_page   Results per page.
     * }
     * @return array { products: array, total: int, pages: int }
     */
    public static function get_products( array $args = [] ) {
        global $wpdb;

        $search   = isset( $args['search'] )   ? sanitize_text_field( $args['search'] )   : '';
        $category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
        $type     = isset( $args['type'] )     ? sanitize_text_field( $args['type'] )     : '';
        $page     = isset( $args['page'] )     ? max( 1, (int) $args['page'] )            : 1;
        $per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] )        : 20;
        $offset   = ( $page - 1 ) * $per_page;

        $where  = [];
        $values = [];

        if ( $search !== '' ) {
            $like      = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]   = '( p.name LIKE %s OR p.sku LIKE %s )';
            $values[]  = $like;
            $values[]  = $like;
        }
        if ( $category !== '' ) {
            $where[]  = 'p.category = %s';
            $values[] = $category;
        }
        if ( in_array( $type, [ 'single', 'variable' ], true ) ) {
            $where[]  = 'p.product_type = %s';
            $values[] = $type;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Count query
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}ngs_products p $where_sql";
        $total     = (int) ( $values
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Data query
        $values_paged   = array_merge( $values, [ $per_page, $offset ] );
        $data_sql = "SELECT p.*, ( SELECT COUNT(*) FROM {$wpdb->prefix}ngs_variations v WHERE v.product_id = p.id ) AS variation_count
                     FROM {$wpdb->prefix}ngs_products p $where_sql
                     ORDER BY p.created_at DESC
                     LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $products = $wpdb->get_results( $wpdb->prepare( $data_sql, $values_paged ), ARRAY_A );

        return [
            'products' => $products ?: [],
            'total'    => $total,
            'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
        ];
    }

    /**
     * Get a single product by ID, with its variations.
     *
     * @param int $id Product ID.
     * @return array|null Product row + 'variations' key, or null.
     */
    public static function get_product( int $id ) {
        global $wpdb;

        $product = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ngs_products WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $product ) {
            return null;
        }

        $product['variations'] = self::get_variations( $id );

        return $product;
    }

    /**
     * Insert or update a product (and its variations for variable products).
     *
     * @param array $data Product data. If 'id' is present and > 0, performs UPDATE.
     * @return int|false Inserted/updated product ID or false on failure.
     */
    public static function save_product( array $data ) {
        global $wpdb;

        $variations = isset( $data['variations'] ) ? (array) $data['variations'] : [];
        unset( $data['variations'] );

        $fields = [
            'name'             => sanitize_text_field( $data['name'] ?? '' ),
            'brand'            => sanitize_text_field( $data['brand'] ?? '' ),
            'unit'             => sanitize_text_field( $data['unit'] ?? 'Pc(s)' ),
            'category'         => sanitize_text_field( $data['category'] ?? '' ),
            'sub_category'     => sanitize_text_field( $data['sub_category'] ?? '' ),
            'sku'              => sanitize_text_field( $data['sku'] ?? '' ),
            'barcode_type'     => sanitize_text_field( $data['barcode_type'] ?? 'C128' ),
            'manage_stock'     => (int) ( $data['manage_stock'] ?? 1 ),
            'alert_qty'        => isset( $data['alert_qty'] ) && $data['alert_qty'] !== '' ? (int) $data['alert_qty'] : null,
            'expires_in'       => isset( $data['expires_in'] ) && $data['expires_in'] !== '' ? (int) $data['expires_in'] : null,
            'expiry_unit'      => sanitize_text_field( $data['expiry_unit'] ?? '' ),
            'applicable_tax'   => sanitize_text_field( $data['applicable_tax'] ?? '' ),
            'tax_type'         => sanitize_text_field( $data['tax_type'] ?? 'inclusive' ),
            'product_type'     => sanitize_text_field( $data['product_type'] ?? 'single' ),
            'variation_name'   => sanitize_text_field( $data['variation_name'] ?? '' ),
            'purchase_price'   => (float) ( $data['purchase_price'] ?? 0 ),
            'selling_price'    => (float) ( $data['selling_price'] ?? 0 ),
            'opening_stock'    => (int) ( $data['opening_stock'] ?? 0 ),
            'image_url'        => esc_url_raw( $data['image_url'] ?? '' ),
            'description'      => wp_kses_post( $data['description'] ?? '' ),
            'weight'           => sanitize_text_field( $data['weight'] ?? '' ),
            'not_for_selling'  => (int) ( $data['not_for_selling'] ?? 0 ),
            'product_locations'=> sanitize_text_field( $data['product_locations'] ?? '' ),
        ];

        $formats = [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s',
            '%f', '%f', '%d',
            '%s', '%s', '%s', '%d', '%s',
        ];

        $product_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $product_id > 0 ) {
            $result = $wpdb->update(
                $wpdb->prefix . 'ngs_products',
                $fields,
                [ 'id' => $product_id ],
                $formats,
                [ '%d' ]
            );
            if ( false === $result ) {
                return false;
            }
        } else {
            $result = $wpdb->insert(
                $wpdb->prefix . 'ngs_products',
                $fields,
                $formats
            );
            if ( false === $result ) {
                return false;
            }
            $product_id = (int) $wpdb->insert_id;
        }

        // Handle variations for variable products
        if ( $fields['product_type'] === 'variable' ) {
            // Delete existing variations, re-insert
            $wpdb->delete(
                $wpdb->prefix . 'ngs_variations',
                [ 'product_id' => $product_id ],
                [ '%d' ]
            );

            foreach ( $variations as $sort => $var ) {
                $wpdb->insert(
                    $wpdb->prefix . 'ngs_variations',
                    [
                        'product_id'      => $product_id,
                        'variation_value' => sanitize_text_field( $var['variation_value'] ?? '' ),
                        'sku'             => sanitize_text_field( $var['sku'] ?? '' ),
                        'purchase_price'  => (float) ( $var['purchase_price'] ?? 0 ),
                        'selling_price'   => (float) ( $var['selling_price'] ?? 0 ),
                        'opening_stock'   => (int) ( $var['opening_stock'] ?? 0 ),
                        'sort_order'      => (int) $sort,
                    ],
                    [ '%d', '%s', '%s', '%f', '%f', '%d', '%d' ]
                );
            }
        } else {
            // Clean up any orphaned variations if product type changed to single
            $wpdb->delete(
                $wpdb->prefix . 'ngs_variations',
                [ 'product_id' => $product_id ],
                [ '%d' ]
            );
        }

        return $product_id;
    }

    /**
     * Delete a product and its variations.
     *
     * @param int $id Product ID.
     * @return bool
     */
    public static function delete_product( int $id ) {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'ngs_variations', [ 'product_id' => $id ], [ '%d' ] );
        $result = $wpdb->delete( $wpdb->prefix . 'ngs_products', [ 'id' => $id ], [ '%d' ] );

        return false !== $result;
    }

    /**
     * Get all variations for a product.
     *
     * @param int $product_id
     * @return array
     */
    public static function get_variations( int $product_id ) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ngs_variations WHERE product_id = %d ORDER BY sort_order ASC, id ASC",
                $product_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get all categories structured as parent → children.
     *
     * @return array [ { id, name, children: [ { id, name } ] } ]
     */
    public static function get_categories() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, name, parent_id FROM {$wpdb->prefix}ngs_categories ORDER BY name ASC",
            ARRAY_A
        ) ?: [];

        $parents  = [];
        $children = [];

        foreach ( $rows as $row ) {
            if ( $row['parent_id'] === null || $row['parent_id'] == 0 ) {
                $parents[ $row['id'] ] = [
                    'id'       => (int) $row['id'],
                    'name'     => $row['name'],
                    'children' => [],
                ];
            } else {
                $children[] = $row;
            }
        }

        foreach ( $children as $child ) {
            $pid = (int) $child['parent_id'];
            if ( isset( $parents[ $pid ] ) ) {
                $parents[ $pid ]['children'][] = [
                    'id'   => (int) $child['id'],
                    'name' => $child['name'],
                ];
            }
        }

        return array_values( $parents );
    }

    /**
     * Insert or update a category.
     *
     * @param array $data { name: string, parent_id: int|null, id: int (for update) }
     * @return int|false Category ID or false.
     */
    public static function save_category( array $data ) {
        global $wpdb;

        $fields = [
            'name'      => sanitize_text_field( $data['name'] ?? '' ),
            'parent_id' => isset( $data['parent_id'] ) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null,
        ];

        $formats = [ '%s', null === $fields['parent_id'] ? '%s' : '%d' ];

        // Ensure NULL is passed correctly
        if ( null === $fields['parent_id'] ) {
            $fields['parent_id'] = null;
            $formats             = [ '%s', null ];
        }

        $category_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $category_id > 0 ) {
            $result = $wpdb->update(
                $wpdb->prefix . 'ngs_categories',
                $fields,
                [ 'id' => $category_id ],
                [ '%s', '%d' ],
                [ '%d' ]
            );
            return false !== $result ? $category_id : false;
        } else {
            // Check for duplicate
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ngs_categories WHERE name = %s AND (parent_id = %s OR (parent_id IS NULL AND %s IS NULL))",
                    $fields['name'],
                    $fields['parent_id'],
                    $fields['parent_id']
                )
            );
            if ( $existing ) {
                return (int) $existing;
            }

            $result = $wpdb->insert(
                $wpdb->prefix . 'ngs_categories',
                $fields,
                [ '%s', '%d' ]
            );
            return false !== $result ? (int) $wpdb->insert_id : false;
        }
    }

    /**
     * Delete a category.
     *
     * @param int $id Category ID.
     * @return int Number of products using this category (as warning context).
     */
    public static function delete_category( int $id ) {
        global $wpdb;

        // Get category name before deletion for product count
        $cat = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ngs_categories WHERE id = %d", $id ),
            ARRAY_A
        );

        $affected = 0;

        if ( $cat ) {
            $affected = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ngs_products WHERE category = %s",
                    $cat['name']
                )
            );

            // Delete sub-categories as well
            $wpdb->delete( $wpdb->prefix . 'ngs_categories', [ 'parent_id' => $id ], [ '%d' ] );
            $wpdb->delete( $wpdb->prefix . 'ngs_categories', [ 'id' => $id ], [ '%d' ] );
        }

        return $affected;
    }
}
