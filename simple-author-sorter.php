<?php
/*
Plugin Name: Simple Author Sorter
Description: Sort authors manually using priority numbers
Version: 1.0
Author: Artyom Chshyogolev (Skyler)
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Author_Sorter {
    private $option_name = 'author_sort_order';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('posts_orderby', array($this, 'modify_posts_order'), 10, 2);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Author Sorting',
            'Author Sorting',
            'manage_options',
            'author-sorting',
            array($this, 'admin_page'),
            'dashicons-sort',
            30
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save changes if form was submitted
        if (isset($_POST['submit']) && check_admin_referer('save_author_order')) {
            $order = array();
            if (isset($_POST['author_order']) && is_array($_POST['author_order'])) {
                foreach ($_POST['author_order'] as $author_id => $priority) {
                    $order[$author_id] = intval($priority);
                }
            }
            update_option($this->option_name, $order);
            echo '<div class="notice notice-success"><p>Author order updated successfully!</p></div>';
        }

        // Get current order
        $saved_order = get_option($this->option_name, array());
        
        // Get all authors
        $authors = get_users(array('who' => 'authors'));
        
        // Sort authors by their saved priority
        usort($authors, function($a, $b) use ($saved_order) {
            $priority_a = isset($saved_order[$a->ID]) ? $saved_order[$a->ID] : 999999;
            $priority_b = isset($saved_order[$b->ID]) ? $saved_order[$b->ID] : 999999;
            return $priority_a - $priority_b;
        });

        ?>
        <div class="wrap">
            <h1>Author Sorting</h1>
            <p>Enter numbers to set author priority (lower numbers appear first). Click Save Changes to apply.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('save_author_order'); ?>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Author</th>
                            <th>Email</th>
                            <th>Posts Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($authors as $author): 
                            $posts_count = count_user_posts($author->ID);
                            $priority = isset($saved_order[$author->ID]) ? $saved_order[$author->ID] : 999999;
                        ?>
                            <tr>
                                <td>
                                    <input type="number" 
                                           name="author_order[<?php echo $author->ID; ?>]" 
                                           value="<?php echo esc_attr($priority); ?>"
                                           min="0"
                                           style="width: 80px;">
                                </td>
                                <td><?php echo esc_html($author->display_name); ?></td>
                                <td><?php echo esc_html($author->user_email); ?></td>
                                <td><?php echo $posts_count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="Save Changes">
                </p>
            </form>
        </div>
        <?php
    }

    public function modify_posts_order($orderby, $query) {
        if (is_admin() || !$query->is_main_query() || (!is_archive() && !is_home())) {
            return $orderby;
        }

        global $wpdb;
        $saved_order = get_option($this->option_name, array());
        
        if (empty($saved_order)) {
            return $orderby;
        }

        $case_statement = "CASE $wpdb->posts.post_author ";
        foreach ($saved_order as $author_id => $priority) {
            $case_statement .= "WHEN $author_id THEN $priority ";
        }
        $case_statement .= "ELSE 999999 END";

        return "$case_statement ASC, " . ($orderby ?: "$wpdb->posts.post_date DESC");
    }
}

// Initialize the plugin
new Simple_Author_Sorter();