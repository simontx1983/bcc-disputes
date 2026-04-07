<?php
/**
 * BCC Disputes – Gutenberg Block Registration
 *
 * Registers all dynamic blocks and the shared editor script.
 *
 * @package BCC_Disputes
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'bcc_disputes_register_blocks');

function bcc_disputes_register_blocks() {
    // Register editor script shared by all blocks.
    wp_register_script(
        'bcc-disputes-blocks-editor',
        BCC_DISPUTES_URL . 'assets/js/blocks-editor.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
        BCC_DISPUTES_VERSION,
        true
    );

    // Register each block from its block.json.
    $blocks = [
        'dispute-form',
        'dispute-queue',
        'report-button',
    ];

    foreach ($blocks as $block) {
        register_block_type(BCC_DISPUTES_PATH . 'blocks/' . $block);
    }
}

/**
 * Register a custom block category for BCC Disputes blocks.
 */
add_filter('block_categories_all', 'bcc_disputes_block_category', 10, 2);

function bcc_disputes_block_category($categories, $context) {
    return array_merge(
        [
            [
                'slug'  => 'bcc-disputes',
                'title' => 'BCC Disputes',
                'icon'  => 'clipboard',
            ],
        ],
        $categories
    );
}
