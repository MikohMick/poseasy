<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NGS_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Register admin top-level menu and submenus.
     */
    public function register_menus() {
        add_menu_page(
            __( 'NGS Products', 'ngs-product-manager' ),
            __( 'NGS Products', 'ngs-product-manager' ),
            'manage_options',
            'ngs-product-manager',
            [ $this, 'render_main_page' ],
            'dashicons-tag',
            30
        );

        add_submenu_page(
            'ngs-product-manager',
            __( 'All Products', 'ngs-product-manager' ),
            __( 'All Products', 'ngs-product-manager' ),
            'manage_options',
            'ngs-product-manager',
            [ $this, 'render_main_page' ]
        );

        add_submenu_page(
            'ngs-product-manager',
            __( 'Add Product', 'ngs-product-manager' ),
            __( 'Add Product', 'ngs-product-manager' ),
            'manage_options',
            'ngs-add-product',
            [ $this, 'render_add_page' ]
        );

        add_submenu_page(
            'ngs-product-manager',
            __( 'Categories', 'ngs-product-manager' ),
            __( 'Categories', 'ngs-product-manager' ),
            'manage_options',
            'ngs-categories',
            [ $this, 'render_categories_page' ]
        );

        add_submenu_page(
            'ngs-product-manager',
            __( 'Export / Barcodes', 'ngs-product-manager' ),
            __( 'Export / Barcodes', 'ngs-product-manager' ),
            'manage_options',
            'ngs-export',
            [ $this, 'render_export_page' ]
        );
    }

    /**
     * Enqueue CSS and JS on NGS admin pages only.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( string $hook ) {
        $ngs_pages = [
            'toplevel_page_ngs-product-manager',
            'ngs-products_page_ngs-add-product',
            'ngs-products_page_ngs-categories',
            'ngs-products_page_ngs-export',
        ];

        if ( ! in_array( $hook, $ngs_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ngs-admin',
            NGS_PLUGIN_URL . 'admin/css/ngs-admin.css',
            [],
            NGS_VERSION
        );

        wp_enqueue_script(
            'vue3',
            'https://unpkg.com/vue@3/dist/vue.global.prod.js',
            [],
            '3.3.4',
            true
        );

        wp_enqueue_script(
            'jsbarcode',
            NGS_PLUGIN_URL . 'assets/jsbarcode.min.js',
            [],
            '3.11.5',
            true
        );

        wp_enqueue_script(
            'ngs-admin',
            NGS_PLUGIN_URL . 'admin/js/ngs-admin.js',
            [ 'jquery', 'vue3', 'jsbarcode' ],
            NGS_VERSION,
            true
        );

        wp_localize_script( 'ngs-admin', 'NGS', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'ngs_nonce' ),
            'plugin_url' => NGS_PLUGIN_URL,
            'page'       => isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'ngs-product-manager',
        ] );
    }

    /**
     * Render the main products list page.
     */
    public function render_main_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'ngs-product-manager' ) );
        }
        ?>
        <div class="wrap">
            <div id="ngs-app" data-view="product-list"></div>
        </div>
        <?php
    }

    /**
     * Render the add/edit product page.
     */
    public function render_add_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'ngs-product-manager' ) );
        }
        ?>
        <div class="wrap">
            <div id="ngs-app" data-view="product-form"></div>
        </div>
        <?php
    }

    /**
     * Render the categories management page.
     */
    public function render_categories_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'ngs-product-manager' ) );
        }
        ?>
        <div class="wrap">
            <div id="ngs-app" data-view="category-manager"></div>
        </div>
        <?php
    }

    /**
     * Render the export / barcodes page.
     */
    public function render_export_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'ngs-product-manager' ) );
        }
        ?>
        <div class="wrap">
            <div id="ngs-app" data-view="export"></div>
        </div>
        <?php
    }
}
