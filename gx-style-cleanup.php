<?php
/**
 * Plugin Name: GX Style Cleanup
 * Description: One-time tool — extracts inline styles from product descriptions into CSS classes. Run from Tools → GX Style Cleanup.
 * Version: 1.0.0
 * Author: ZASK
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_management_page( 'GX Style Cleanup', 'GX Style Cleanup', 'manage_options', 'gx-style-cleanup', 'gx_cleanup_page' );
});

function gx_extract_styles( $html ) {
    static $style_map = array();
    static $counter = 0;

    $html = preg_replace_callback(
        '/<([a-z][a-z0-9]*)((?:\s+[^>]*?)?)style="([^"]*)"((?:\s+[^>]*?)?)>/i',
        function( $m ) use ( &$style_map, &$counter ) {
            $tag = $m[1];
            $before = $m[2];
            $style = trim( $m[3] );
            $after = $m[4];

            if ( empty( $style ) ) return $m[0];

            // Normalize
            $style_norm = rtrim( trim( preg_replace( '/\s+/', ' ', $style ) ), ';' ) . ';';
            $hash = substr( md5( $style_norm ), 0, 6 );

            if ( ! isset( $style_map[ $style_norm ] ) ) {
                $counter++;
                $style_map[ $style_norm ] = "gx-s{$counter}-{$hash}";
            }

            $class_name = $style_map[ $style_norm ];

            // Check for existing class
            if ( preg_match( '/class="([^"]*)"/', $before . $after, $cm ) ) {
                $new_classes = $cm[1] . ' ' . $class_name;
                $combined = $before . $after;
                $combined = str_replace( 'class="' . $cm[1] . '"', 'class="' . $new_classes . '"', $combined );
                return "<{$tag}{$combined}>";
            } else {
                return "<{$tag}{$before} class=\"{$class_name}\"{$after}>";
            }
        },
        $html
    );

    return array( 'html' => $html, 'map' => $style_map );
}

function gx_generate_css( $style_map ) {
    $css = "/**\n * GX Research — Product Description Styles\n * Auto-extracted from inline styles\n */\n\n";
    foreach ( $style_map as $style => $class ) {
        $css .= ".{$class} {\n";
        foreach ( explode( ';', $style ) as $prop ) {
            $prop = trim( $prop );
            if ( $prop ) $css .= "  {$prop};\n";
        }
        $css .= "}\n\n";
    }
    return $css;
}

function gx_cleanup_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $action = isset( $_POST['gx_action'] ) ? $_POST['gx_action'] : '';

    // Get all products
    $products = get_posts( array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'any',
    ));

    echo '<div class="wrap"><h1>GX Style Cleanup</h1>';
    echo '<p>' . count( $products ) . ' products found.</p>';

    // ── ANALYZE ──
    if ( $action === 'analyze' || ! $action ) {
        check_admin_referer( 'gx_cleanup' );
        // Only check referer on POST
    }

    if ( ! $action ) {
        $total = 0;
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Product</th><th>Content Styles</th><th>Excerpt Styles</th><th>Total</th></tr></thead><tbody>';
        foreach ( $products as $p ) {
            $c = preg_match_all( '/style="[^"]*"/', $p->post_content );
            $e = preg_match_all( '/style="[^"]*"/', $p->post_excerpt );
            $t = $c + $e;
            $total += $t;
            if ( $t > 0 ) {
                echo "<tr><td>{$p->ID}</td><td>{$p->post_title}</td><td>{$c}</td><td>{$e}</td><td><strong>{$t}</strong></td></tr>";
            }
        }
        echo '</tbody></table>';
        echo "<p><strong>Total inline styles: {$total}</strong></p>";

        echo '<form method="post">';
        wp_nonce_field( 'gx_cleanup' );
        echo '<input type="hidden" name="gx_action" value="preview">';
        echo '<p><button type="submit" class="button button-primary button-hero">Preview Changes</button></p>';
        echo '</form>';
        echo '</div>';
        return;
    }

    // ── PREVIEW ──
    if ( $action === 'preview' ) {
        check_admin_referer( 'gx_cleanup' );

        $style_map = array();
        $changes = array();

        foreach ( $products as $p ) {
            $r1 = gx_extract_styles( $p->post_content );
            $new_content = $r1['html'];
            $style_map = $r1['map'];

            $r2 = gx_extract_styles( $p->post_excerpt );
            $new_excerpt = $r2['html'];
            $style_map = $r2['map'];

            if ( $new_content !== $p->post_content || $new_excerpt !== $p->post_excerpt ) {
                $changes[] = array( 'id' => $p->ID, 'title' => $p->post_title );
            }
        }

        $css = gx_generate_css( $style_map );

        echo '<h2>Preview</h2>';
        echo '<p><strong>' . count( $changes ) . '</strong> products will be modified.</p>';
        echo '<p><strong>' . count( $style_map ) . '</strong> unique CSS classes will be generated.</p>';
        echo '<p>CSS file size: <strong>' . number_format( strlen( $css ) ) . '</strong> bytes</p>';

        echo '<h3>Generated CSS (first 80 lines)</h3>';
        echo '<textarea readonly rows="20" style="width:100%;font-family:monospace;font-size:12px;">';
        $lines = explode( "\n", $css );
        echo esc_textarea( implode( "\n", array_slice( $lines, 0, 80 ) ) );
        if ( count( $lines ) > 80 ) echo "\n... (" . count( $lines ) . " total lines)";
        echo '</textarea>';

        echo '<h3>Products to update</h3><ul>';
        foreach ( $changes as $c ) {
            echo "<li>[{$c['id']}] {$c['title']}</li>";
        }
        echo '</ul>';

        echo '<form method="post">';
        wp_nonce_field( 'gx_cleanup' );
        echo '<input type="hidden" name="gx_action" value="apply">';
        echo '<p><button type="submit" class="button button-primary button-hero" onclick="return confirm(\'Apply changes to ' . count( $changes ) . ' products? A backup will be saved.\')">Apply Changes</button></p>';
        echo '</form>';
        echo '</div>';
        return;
    }

    // ── APPLY ──
    if ( $action === 'apply' ) {
        check_admin_referer( 'gx_cleanup' );

        // Backup
        $backup = array();
        foreach ( $products as $p ) {
            $backup[] = array(
                'id' => $p->ID,
                'title' => $p->post_title,
                'content' => $p->post_content,
                'excerpt' => $p->post_excerpt,
            );
        }
        update_option( 'gx_style_backup', wp_json_encode( $backup ), false );

        // Process
        $style_map = array();
        $updated = 0;
        $errors = 0;

        echo '<h2>Applying Changes</h2><ul>';

        foreach ( $products as $p ) {
            $r1 = gx_extract_styles( $p->post_content );
            $new_content = $r1['html'];
            $style_map = $r1['map'];

            $r2 = gx_extract_styles( $p->post_excerpt );
            $new_excerpt = $r2['html'];
            $style_map = $r2['map'];

            if ( $new_content !== $p->post_content || $new_excerpt !== $p->post_excerpt ) {
                $result = wp_update_post( array(
                    'ID' => $p->ID,
                    'post_content' => $new_content,
                    'post_excerpt' => $new_excerpt,
                ), true );

                if ( is_wp_error( $result ) ) {
                    $errors++;
                    echo "<li style='color:red;'>✗ [{$p->ID}] {$p->post_title}: {$result->get_error_message()}</li>";
                } else {
                    $updated++;
                    echo "<li style='color:green;'>✓ [{$p->ID}] {$p->post_title}</li>";
                }
            }
        }

        echo '</ul>';

        // Generate and save CSS
        $css = gx_generate_css( $style_map );

        // Save to child theme if exists, otherwise save as option
        $child_theme_dir = get_stylesheet_directory();
        $css_file = $child_theme_dir . '/gx-product-styles.css';
        $css_saved_to = '';

        if ( is_writable( $child_theme_dir ) ) {
            file_put_contents( $css_file, $css );
            $css_saved_to = $css_file;
        } else {
            // Save as option and enqueue
            update_option( 'gx_product_css', $css, false );
            $css_saved_to = 'database (wp_options: gx_product_css)';
        }

        echo "<h3>Results</h3>";
        echo "<p><strong>{$updated}</strong> products updated, <strong>{$errors}</strong> errors.</p>";
        echo "<p>CSS saved to: <strong>{$css_saved_to}</strong></p>";
        echo "<p>Backup saved to: <strong>wp_options (gx_style_backup)</strong></p>";

        echo '<h3>Generated CSS</h3>';
        echo '<textarea readonly rows="20" style="width:100%;font-family:monospace;font-size:12px;">' . esc_textarea( $css ) . '</textarea>';

        echo '<h3>Next Steps</h3>';
        echo '<ol>';
        if ( strpos( $css_saved_to, 'database' ) !== false ) {
            echo '<li>Copy the CSS above into Appearance → Customize → Additional CSS</li>';
        } else {
            echo '<li>CSS file created at: ' . esc_html( $css_saved_to ) . '</li>';
            echo '<li>Add this to your child theme functions.php:<br><code>wp_enqueue_style("gx-product-styles", get_stylesheet_directory_uri() . "/gx-product-styles.css", array(), "1.0.0");</code></li>';
        }
        echo '<li>Check a product page to verify styles look correct</li>';
        echo '<li>Deactivate and delete this plugin — it\'s a one-time tool</li>';
        echo '</ol>';

        // Rollback form
        echo '<hr><h3>Rollback</h3>';
        echo '<form method="post">';
        wp_nonce_field( 'gx_cleanup' );
        echo '<input type="hidden" name="gx_action" value="rollback">';
        echo '<p><button type="submit" class="button" onclick="return confirm(\'Restore all original product descriptions?\')">Rollback All Changes</button></p>';
        echo '</form>';

        echo '</div>';
        return;
    }

    // ── ROLLBACK ──
    if ( $action === 'rollback' ) {
        check_admin_referer( 'gx_cleanup' );

        $backup_json = get_option( 'gx_style_backup', '' );
        if ( ! $backup_json ) {
            echo '<div class="notice notice-error"><p>No backup found.</p></div></div>';
            return;
        }

        $backup = json_decode( $backup_json, true );
        $restored = 0;

        echo '<h2>Rolling Back</h2><ul>';
        foreach ( $backup as $b ) {
            wp_update_post( array(
                'ID' => $b['id'],
                'post_content' => $b['content'],
                'post_excerpt' => $b['excerpt'],
            ));
            $restored++;
            echo "<li>✓ [{$b['id']}] {$b['title']}</li>";
        }
        echo '</ul>';
        echo "<p><strong>{$restored}</strong> products restored to original.</p>";
        echo '</div>';
        return;
    }

    echo '</div>';
}

// Enqueue the generated CSS on frontend (if saved to file)
add_action( 'wp_enqueue_scripts', function() {
    $css_file = get_stylesheet_directory() . '/gx-product-styles.css';
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style( 'gx-product-styles', get_stylesheet_directory_uri() . '/gx-product-styles.css', array(), '1.0.0' );
    } else {
        // Check if CSS is in DB
        $css = get_option( 'gx_product_css', '' );
        if ( $css ) {
            wp_register_style( 'gx-product-styles-inline', false );
            wp_enqueue_style( 'gx-product-styles-inline' );
            wp_add_inline_style( 'gx-product-styles-inline', $css );
        }
    }
});
