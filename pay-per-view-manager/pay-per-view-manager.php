<?php
/**
 * Plugin Name: Pay Per View Manager
 * Description: Manage payouts for authors based on views and reading time of their posts.
 * Version: 1.0
 * Author: Artyom Chshyogolev
 */

if (!defined('ABSPATH')) exit;

// Create database table on plugin activation
function ppv_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ppv_payout_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        author_id bigint(20) NOT NULL,
        views int(11) NOT NULL,
        reading_time int(11) NOT NULL,
        payout decimal(10,2) NOT NULL,
        payout_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'ppv_create_tables');

// Enqueue Scripts
function ppv_enqueue_scripts() {
    if (is_single()) {
        wp_enqueue_script('ppv-reading-time', plugin_dir_url(__FILE__) . 'js/reading-time.js', [], '1.0', true);
        
        wp_localize_script('ppv-reading-time', 'ppvAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'postId'  => get_the_ID(),
        ]);
    }
}
add_action('wp_enqueue_scripts', 'ppv_enqueue_scripts');

// Save Reading Time
function ppv_save_reading_time() {
    if (isset($_POST['post_id'], $_POST['reading_time'])) {
        $post_id = intval($_POST['post_id']);
        $reading_time = intval($_POST['reading_time']);

        $existing_time = get_post_meta($post_id, '_ppv_reading_time', true);
        $existing_time = intval($existing_time);

        error_log("Post ID: $post_id, Reading Time: $reading_time, Existing Time: $existing_time");

        update_post_meta($post_id, '_ppv_reading_time', $existing_time + $reading_time);
    } else {
        error_log("Invalid data received in AJAX request: " . print_r($_POST, true));
    }
    wp_die();
}
add_action('wp_ajax_ppv_save_reading_time', 'ppv_save_reading_time');
add_action('wp_ajax_nopriv_ppv_save_reading_time', 'ppv_save_reading_time');

// Count Views
function ppv_count_views($post_id) {
    if (!is_single() || empty($post_id)) return;
    $views = get_post_meta($post_id, '_ppv_views', true);
    update_post_meta($post_id, '_ppv_views', $views ? $views + 1 : 1);
}
add_action('wp_head', function() {
    if (is_single()) ppv_count_views(get_the_ID());
});

// Admin Menu
function ppv_add_admin_menu() {
    add_menu_page('Pay Per View Manager', 'PPV Manager', 'manage_options', 'ppv-manager', 'ppv_dashboard', 'dashicons-chart-bar');
}
add_action('admin_menu', 'ppv_add_admin_menu');

// Dashboard
function ppv_dashboard() {
    global $wpdb;
    
    if (isset($_POST['ppv_submit'])) {
        update_option('ppv_total_budget', floatval($_POST['total_budget']));
        update_option('ppv_pay_per_view', floatval($_POST['pay_per_view']));
        update_option('ppv_calculation_method', sanitize_text_field($_POST['calculation_method']));
    }

    if (isset($_POST['ppv_submit_payout'])) {
        $authors = get_users(['role__in' => ['author', 'editor']]);
        $excluded_admins = [1, 2];
        
        foreach ($authors as $author) {
            if (in_array($author->ID, $excluded_admins)) continue;
            
            $author_views = 0;
            $author_reading_time = 0;
            
            $posts = get_posts(['author' => $author->ID, 'post_type' => 'post', 'posts_per_page' => -1]);
            foreach ($posts as $post) {
                $views = get_post_meta($post->ID, '_ppv_views', true) ?: 0;
                $reading_time = get_post_meta($post->ID, '_ppv_reading_time', true) ?: 0;
                
                if ($views > 0 || $reading_time > 0) {
                    $wpdb->insert(
                        $wpdb->prefix . 'ppv_payout_logs',
                        [
                            'author_id' => $author->ID,
                            'views' => $views,
                            'reading_time' => $reading_time,
                            'payout' => calculate_author_payout($author->ID)
                        ]
                    );
                    
                    delete_post_meta($post->ID, '_ppv_views');
                    delete_post_meta($post->ID, '_ppv_reading_time');
                }
            }
        }
        
        add_settings_error('ppv_messages', 'ppv_message', 'Payouts have been processed and logged successfully!', 'updated');
    }

    $total_budget = get_option('ppv_total_budget', 0);
    $pay_per_view = get_option('ppv_pay_per_view', 0);
    $calculation_method = get_option('ppv_calculation_method', 'budget');
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
    
    echo '<div class="wrap">';
    echo '<h1>Pay Per View Manager</h1>';
    
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=ppv-manager&tab=dashboard" class="nav-tab ' . ($active_tab == 'dashboard' ? 'nav-tab-active' : '') . '">Dashboard</a>';
    echo '<a href="?page=ppv-manager&tab=logs" class="nav-tab ' . ($active_tab == 'logs' ? 'nav-tab-active' : '') . '">Logs</a>';
    echo '<a href="?page=ppv-manager&tab=statistics" class="nav-tab ' . ($active_tab == 'statistics' ? 'nav-tab-active' : '') . '">Statistics</a>';
    echo '</h2>';
    
    settings_errors('ppv_messages');

    if ($active_tab == 'dashboard') {
        echo '<form method="POST">';
        echo '<label>Total Budget (KZT):</label> <input type="number" name="total_budget" value="' . esc_attr($total_budget) . '" /><br><br>';
        echo '<label>Pay Per View (KZT):</label> <input type="number" name="pay_per_view" value="' . esc_attr($pay_per_view) . '" /><br><br>';
        echo '<label>Calculation Method:</label><br>';
        echo '<input type="radio" name="calculation_method" value="budget"' . checked($calculation_method, 'budget', false) . '> From Total Budget<br>';
        echo '<input type="radio" name="calculation_method" value="ppv"' . checked($calculation_method, 'ppv', false) . '> Pay Per View System<br><br>';
        echo '<input type="submit" name="ppv_submit" value="Save" class="button button-primary" />';
        echo '</form><br>';

        echo '<h2>Current Period Payouts</h2>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>Author</th><th>Views</th><th>Reading Time (s)</th><th>Adjusted Payout (KZT)</th></tr></thead><tbody>';

        $authors = get_users(['role__in' => ['author', 'editor']]);
        $excluded_admins = [1, 2];
        $total_views = 0;
        $total_reading_time = 0;

        foreach ($authors as $author) {
            if (in_array($author->ID, $excluded_admins)) continue;

            $author_views = 0;
            $author_reading_time = 0;
            $total_word_count = 0;

            $posts = get_posts(['author' => $author->ID, 'post_type' => 'post', 'posts_per_page' => -1]);
            foreach ($posts as $post) {
                $views = get_post_meta($post->ID, '_ppv_views', true) ?: 0;
                $reading_time = get_post_meta($post->ID, '_ppv_reading_time', true) ?: 0;
                $word_count = str_word_count(strip_tags($post->post_content));

                $author_views += $views;
                $author_reading_time += $reading_time;
                $total_word_count += $word_count;
            }

            $total_views += $author_views;
            $total_reading_time += $author_reading_time;

            if ($author_views > 0 || $author_reading_time > 0) {
                $average_reading_time = $total_word_count > 0 ? ($author_reading_time / $total_word_count) : 0;
                
                if ($calculation_method === 'budget') {
                    if ($total_views > 0 && $total_reading_time > 0) {
                        $adjusted_payout = ($author_views / $total_views) * $total_budget * ($average_reading_time / ($total_reading_time / $total_views));
                    } else {
                        $adjusted_payout = 0;
                    }
                } else {
                    $adjusted_payout = $author_views * $pay_per_view;
                }

                echo '<tr>';
                echo '<td>' . esc_html($author->display_name) . '</td>';
                echo '<td>' . esc_html($author_views) . '</td>';
                echo '<td>' . esc_html($author_reading_time) . '</td>';
                echo '<td>' . esc_html(number_format($adjusted_payout, 2)) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<form method="POST" style="margin-top: 20px;">';
        echo '<input type="submit" name="ppv_submit_payout" value="Process Payouts" class="button button-primary" onclick="return confirm(\'Are you sure you want to process payouts? This will reset all current view and reading time counters.\');" />';
        echo '</form>';

    } elseif ($active_tab == 'logs') {
        $logs = $wpdb->get_results("
            SELECT l.*, u.display_name 
            FROM {$wpdb->prefix}ppv_payout_logs l
            JOIN {$wpdb->users} u ON l.author_id = u.ID
            ORDER BY l.payout_date DESC
        ");
        
        echo '<table class="widefat">';
        echo '<thead><tr><th>Date</th><th>Author</th><th>Views</th><th>Reading Time</th><th>Payout (KZT)</th></tr></thead><tbody>';
        
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->payout_date) . '</td>';
            echo '<td>' . esc_html($log->display_name) . '</td>';
            echo '<td>' . esc_html($log->views) . '</td>';
            echo '<td>' . esc_html($log->reading_time) . '</td>';
            echo '<td>' . esc_html(number_format($log->payout, 2)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';

    } else {
        $stats = $wpdb->get_results("
            SELECT 
                u.display_name,
                SUM(l.views) as total_views,
                SUM(l.reading_time) as total_reading_time,
                SUM(l.payout) as total_payout
            FROM {$wpdb->prefix}ppv_payout_logs l
            JOIN {$wpdb->users} u ON l.author_id = u.ID
            GROUP BY l.author_id
            ORDER BY total_payout DESC
        ");
        
        echo '<h3>All-Time Statistics</h3>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>Author</th><th>Total Views</th><th>Total Reading Time</th><th>Total Payout (KZT)</th></tr></thead><tbody>';
        
        foreach ($stats as $stat) {
            echo '<tr>';
            echo '<td>' . esc_html($stat->display_name) . '</td>';
            echo '<td>' . esc_html($stat->total_views) . '</td>';
            echo '<td>' . esc_html($stat->total_reading_time) . '</td>';
            echo '<td>' . esc_html(number_format($stat->total_payout, 2)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    echo '</div>';
}

function calculate_author_payout($author_id) {
    $calculation_method = get_option('ppv_calculation_method', 'budget');
    $total_budget = get_option('ppv_total_budget', 0);
    $pay_per_view = get_option('ppv_pay_per_view', 0);
    
    $author_views = 0;
    $author_reading_time = 0;
    $total_word_count = 0;
    
    $posts = get_posts(['author' => $author_id, 'post_type' => 'post', 'posts_per_page' => -1]);
    foreach ($posts as $post) {
        $views = get_post_meta($post->ID, '_ppv_views', true) ?: 0;
        $reading_time = get_post_meta($post->ID, '_ppv_reading_time', true) ?: 0;
        $word_count = str_word_count(strip_tags($post->post_content));
        
        $author_views += $views;
        $author_reading_time += $reading_time;
        $total_word_count += $word_count;
    }
    
    if ($calculation_method === 'budget') {
        $total_views = 0;
        $total_reading_time = 0;
        
        $all_authors = get_users(['role__in' => ['author', 'editor']]);
        foreach ($all_authors as $author) {
            $posts = get_posts(['author' => $author->ID, 'post_type' => 'post', 'posts_per_page' => -1]);
            foreach ($posts as $post) {
                $views = get_post_meta($post->ID, '_ppv_views', true) ?: 0;
                $reading_time = get_post_meta($post->ID, '_ppv_reading_time', true) ?: 0;
                
                $total_views += $views;
                $total_reading_time += $reading_time;
            }
        }
        
        if ($total_views > 0 && $total_reading_time > 0) {
            $average_reading_time = $total_word_count > 0 ? ($author_reading_time / $total_word_count) : 0;
            return ($author_views / $total_views) * $total_budget * ($average_reading_time / ($total_reading_time / $total_views));
        }
        return 0;
    } else {
        return $author_views * $pay_per_view;
    }
}