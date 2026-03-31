<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGS_Ajax {

    public function __construct() {
        $actions = [
            'ngs_get_products',
            'ngs_get_product',
            'ngs_save_product',
            'ngs_delete_product',
            'ngs_bulk_delete',
            'ngs_get_categories',
            'ngs_save_category',
            'ngs_delete_category',
            'ngs_export_csv',
            'ngs_upload_image',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    // -------------------------------------------------------------------------
    // Security helpers
    // -------------------------------------------------------------------------

    private function check_auth() {
        check_ajax_referer( 'ngs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET: Fetch paginated / filtered product list.
     *
     * Params: search, category, type, page, per_page
     */
    public function ngs_get_products() {
        $this->check_auth();

        $result = NGS_DB::get_products( [
            'search'   => isset( $_REQUEST['search'] )   ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) )   : '',
            'category' => isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '',
            'type'     => isset( $_REQUEST['type'] )     ? sanitize_text_field( wp_unslash( $_REQUEST['type'] ) )     : '',
            'page'     => isset( $_REQUEST['page'] )     ? (int) $_REQUEST['page']     : 1,
            'per_page' => isset( $_REQUEST['per_page'] ) ? (int) $_REQUEST['per_page'] : 20,
        ] );

        wp_send_json_success( $result );
    }

    /**
     * GET: Fetch a single product with its variations.
     *
     * Params: id
     */
    public function ngs_get_product() {
        $this->check_auth();

        $id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;

        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        $product = NGS_DB::get_product( $id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ], 404 );
        }

        wp_send_json_success( $product );
    }

    /**
     * POST: Create or update a product.
     *
     * Params: product (JSON string)
     */
    public function ngs_save_product() {
        $this->check_auth();

        $raw = isset( $_POST['product'] ) ? wp_unslash( $_POST['product'] ) : '';

        if ( empty( $raw ) ) {
            wp_send_json_error( [ 'message' => 'No product data provided.' ] );
        }

        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'Invalid product JSON.' ] );
        }

        if ( empty( $data['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Product name is required.' ] );
        }

        $product_id = NGS_DB::save_product( $data );

        if ( false === $product_id ) {
            wp_send_json_error( [ 'message' => 'Failed to save product.' ] );
        }

        wp_send_json_success( [
            'id'      => $product_id,
            'message' => 'Product saved successfully.',
        ] );
    }

    /**
     * POST: Delete a single product.
     *
     * Params: id
     */
    public function ngs_delete_product() {
        $this->check_auth();

        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        $result = NGS_DB::delete_product( $id );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => 'Failed to delete product.' ] );
        }

        wp_send_json_success( [ 'message' => 'Product deleted.' ] );
    }

    /**
     * POST: Delete multiple products.
     *
     * Params: ids (array or JSON array)
     */
    public function ngs_bulk_delete() {
        $this->check_auth();

        $raw_ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';

        if ( is_string( $raw_ids ) ) {
            $ids = json_decode( $raw_ids, true );
        } else {
            $ids = (array) $raw_ids;
        }

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            wp_send_json_error( [ 'message' => 'No IDs provided.' ] );
        }

        $deleted = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && NGS_DB::delete_product( $id ) ) {
                $deleted++;
            }
        }

        wp_send_json_success( [
            'deleted' => $deleted,
            'message' => sprintf( '%d product(s) deleted.', $deleted ),
        ] );
    }

    /**
     * GET: Return all categories structured as parent → children.
     */
    public function ngs_get_categories() {
        $this->check_auth();

        $categories = NGS_DB::get_categories();

        wp_send_json_success( $categories );
    }

    /**
     * POST: Create or update a category.
     *
     * Params: name, parent_id (optional), id (optional, for update)
     */
    public function ngs_save_category() {
        $this->check_auth();

        $data = [
            'name'      => isset( $_POST['name'] )      ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'parent_id' => isset( $_POST['parent_id'] ) && $_POST['parent_id'] !== '' ? (int) $_POST['parent_id'] : null,
        ];

        if ( isset( $_POST['id'] ) && (int) $_POST['id'] > 0 ) {
            $data['id'] = (int) $_POST['id'];
        }

        if ( empty( $data['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Category name is required.' ] );
        }

        $cat_id = NGS_DB::save_category( $data );

        if ( false === $cat_id ) {
            wp_send_json_error( [ 'message' => 'Failed to save category.' ] );
        }

        wp_send_json_success( [
            'id'      => $cat_id,
            'message' => 'Category saved.',
        ] );
    }

    /**
     * POST: Delete a category and its children.
     *
     * Params: id
     * Returns affected product count as warning.
     */
    public function ngs_delete_category() {
        $this->check_auth();

        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid category ID.' ] );
        }

        $affected = NGS_DB::delete_category( $id );

        wp_send_json_success( [
            'affected_products' => $affected,
            'message'           => $affected > 0
                ? sprintf( 'Category deleted. %d product(s) still reference this category.', $affected )
                : 'Category deleted.',
        ] );
    }

    /**
     * GET: Export products as CSV.
     *
     * Params: ids (optional, JSON array for selective export)
     */
    public function ngs_export_csv() {
        $this->check_auth();

        $ids = [];

        if ( ! empty( $_REQUEST['ids'] ) ) {
            $raw = wp_unslash( $_REQUEST['ids'] );
            if ( is_string( $raw ) ) {
                $decoded = json_decode( $raw, true );
                $ids     = is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
            } else {
                $ids = array_map( 'intval', (array) $raw );
            }
        }

        NGS_Export::export( $ids );
    }

    /**
     * POST: Upload an image via WP media library.
     *
     * Expects a standard multipart file upload in $_FILES['image'].
     */
    public function ngs_upload_image() {
        $this->check_auth();

        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = [ 'test_form' => false ];

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file   = wp_unslash( $_FILES['image'] );
        $upload = wp_handle_upload( $file, $overrides );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => $upload['error'] ] );
        }

        // Insert into media library
        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $upload['type'],
                'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
                'post_status'    => 'inherit',
            ],
            $upload['file']
        );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        wp_send_json_success( [
            'url'           => $upload['url'],
            'attachment_id' => $attachment_id,
        ] );
    }
}
