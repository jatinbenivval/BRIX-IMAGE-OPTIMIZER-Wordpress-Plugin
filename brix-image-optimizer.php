<?php
/**
 * Plugin Name: BRIX Image Optimizer
 * Description: Production-ready hybrid WebP optimizer. Runtime on-demand generation + bulk tools.
 * Version: 1.0.0
 * Author: <a href="https://jatinbeniwal.in" target="_blank">Jatin Beniwal</a> (<a href="https://brixly.com" target="_blank">BRIXLY.com</a>)
 * Author URI: https://jatinbeniwal.in
 * Company: BRIXLY.com
 */

if (!defined('ABSPATH')) exit;

class BRIX_Image_Optimizer {

    private $optimized_dir = 'brix-optimized';
    private $quality = 82;

    public function __construct() {
        // Core Hooks
        add_action('init', [$this, 'init_dirs']);
        
        // Runtime Replacement
        add_filter('the_content', [$this, 'filter_content'], 100);
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_attachment_attributes'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 100);

        // Admin UI
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // Media Modal & AJAX
        add_filter('attachment_fields_to_edit', [$this, 'add_media_button'], 10, 2);
        add_action('wp_ajax_brix_bulk_process', [$this, 'ajax_bulk_process']);
        add_action('wp_ajax_brix_clear_cache', [$this, 'ajax_clear_cache']);
        
        // Media Library Columns
        add_filter('manage_media_columns', [$this, 'add_media_columns']);
        add_action('manage_media_custom_column', [$this, 'render_media_columns'], 10, 2);

        // Plugin Action Links
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

        // Auto-optimize on upload
        add_action('add_attachment', [$this, 'auto_optimize_on_upload']);
    }

    public function add_action_links($links) {
        $settings_link = '<a href="options-general.php?page=brix-optimizer" style="font-weight: bold; color: #46b450;">' . __('Optimize') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function init_dirs() {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/' . $this->optimized_dir;
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
    }

    /**
     * DASHBOARD LOGIC
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget('brix_stats_widget', 'BRIXly Optimization Stats', [$this, 'dashboard_widget_content']);
    }

    public function dashboard_widget_content() {
        $stats = get_option('brix_stats', ['total_optimized' => 0, 'bytes_saved' => 0]);
        echo '<div style="text-align: center; padding: 10px;">';
        echo '<h2 style="font-size: 2.5em; margin: 0;">' . $stats['total_optimized'] . '</h2>';
        echo '<p style="color: #666;">Images Optimized</p>';
        echo '<hr>';
        echo '<h2 style="font-size: 2em; margin: 0; color: #46b450;">' . size_format($stats['bytes_saved']) . '</h2>';
        echo '<p style="color: #666;">Disk Space Saved</p>';
        echo '</div>';
    }

    /**
     * RUNTIME FILTERS
     */
    public function filter_content($content) {
        if (empty($content)) return $content;
        $pattern = '/(?:https?:)?\/\/[^"\'\s>]+\.(?:jpg|jpeg|png)(?:\?[^"\'\s>]*)?/i';
        return preg_replace_callback($pattern, function($matches) {
            return $this->get_optimized_url($matches[0]);
        }, $content);
    }

    public function filter_attachment_attributes($attr, $attachment) {
        if (isset($attr['src'])) $attr['src'] = $this->get_optimized_url($attr['src']);
        if (isset($attr['srcset'])) {
            $parts = explode(',', $attr['srcset']);
            foreach ($parts as &$part) {
                $p = trim($part);
                if (preg_match('/^([^ ]+)(.*)$/', $p, $m)) {
                    $optimized = $this->get_optimized_url($m[1]);
                    $part = $optimized . $m[2];
                }
            }
            $attr['srcset'] = implode(', ', $parts);
        }
        return $attr;
    }

    public function filter_srcset($sources) {
        foreach ($sources as &$source) {
            $source['url'] = $this->get_optimized_url($source['url']);
        }
        return $sources;
    }

    /**
     * OPTIMIZATION ENGINE
     */
    private function get_optimized_url($url) {
        $clean_url = strtok($url, '?');
        $upload_dir = wp_upload_dir();
        
        $base_url = str_replace(['http:', 'https:'], '', $upload_dir['baseurl']);
        $norm_url = str_replace(['http:', 'https:'], '', $clean_url);

        if (strpos($norm_url, $base_url) === false) return $url;

        $relative_path = str_replace($base_url, '', $norm_url);
        $original_path = $upload_dir['basedir'] . $relative_path;
        
        if (!file_exists($original_path)) return $url;

        $optimized_rel_path = '/' . $this->optimized_dir . preg_replace('/\.(?:jpg|jpeg|png)$/i', '.webp', $relative_path);
        $optimized_path = $upload_dir['basedir'] . $optimized_rel_path;
        $optimized_url = $upload_dir['baseurl'] . $optimized_rel_path;

        if (file_exists($optimized_path)) return $optimized_url;

        if ($this->generate_webp($original_path, $optimized_path)) {
            return $optimized_url;
        }

        return $url;
    }

    private function generate_webp($source, $dest) {
        if (!function_exists('imagewebp')) return false;
        
        $dest_dir = dirname($dest);
        if (!file_exists($dest_dir)) wp_mkdir_p($dest_dir);

        $info = @getimagesize($source);
        if (!$info) return false;

        switch ($info['mime']) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($source); break;
            case 'image/png': $img = @imagecreatefrompng($source); break;
            default: return false;
        }

        if (!$img) return false;

        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $success = imagewebp($img, $dest, $this->quality);
        imagedestroy($img);

        if ($success) {
            $this->update_stats(filesize($source) - filesize($dest));
        }

        return $success;
    }

    private function update_stats($saved_bytes) {
        $stats = get_option('brix_stats', ['total_optimized' => 0, 'bytes_saved' => 0]);
        $stats['total_optimized']++;
        $stats['bytes_saved'] += max(0, $saved_bytes);
        update_option('brix_stats', $stats);
    }

    /**
     * ADMIN INTERFACE
     */
    public function add_menu() {
        add_options_page('BRIX Optimizer', 'BRIX Optimizer', 'manage_options', 'brix-optimizer', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('brix_opt_settings', 'brix_quality');
    }

    /**
     * MEDIA LIBRARY COLUMNS
     */
    public function add_media_columns($columns) {
        $columns['brix_webp'] = 'WebP Status';
        return $columns;
    }

    public function render_media_columns($column_name, $id) {
        if ($column_name !== 'brix_webp') return;
        
        $file = get_attached_file($id);
        if (!$file) return;

        $optimized_rel_path = '/' . $this->optimized_dir . preg_replace('/\.(?:jpg|jpeg|png)$/i', '.webp', str_replace(wp_upload_dir()['basedir'], '', $file));
        $optimized_path = wp_upload_dir()['basedir'] . $optimized_rel_path;

        if (file_exists($optimized_path)) {
            echo '<span style="color: #46b450; font-weight: bold; border: 1px solid #46b450; padding: 2px 6px; border-radius: 4px; font-size: 10px;">WEBP READY</span>';
        } else {
            echo '<span style="color: #ccc; font-size: 10px;">—</span>';
        }
    }

    public function settings_page() {
        ?>
        <div class="wrap" style="max-width: 900px;">
            <div style="background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="background: #46b450; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; font-size: 20px;">B</div>
                        <h1 style="margin: 0;">BRIX Image Optimizer <span style="font-weight: 300; font-size: 0.5em; vertical-align: middle; background: #46b450; color: #fff; padding: 2px 8px; border-radius: 10px;">v1.0.0</span></h1>
                    </div>
                    <button id="brix-clear-cache" class="button button-link" style="color: #d63638; text-decoration: none;">Clear All Optimized Images</button>
                </div>
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">
                    <div>
                        <h3>Optimization Controls</h3>
                        <p>BRIX Optimizer uses a <strong>Hybrid Logic</strong>: images are automatically optimized when they are uploaded or when someone visits your site. You can also force a bulk optimization below.</p>
                        
                        <div class="card" style="padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fcfcfc;">
                            <button id="brix-bulk-start" class="button button-primary button-large" style="background: #46b450; border-color: #46b450; height: 45px; padding: 0 30px;">Start Bulk Optimization</button>
                            <span id="brix-progress" style="margin-left: 15px; font-weight: bold; color: #46b450;"></span>
                        </div>

                        <div id="brix-guide" style="margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px;">
                            <h3>🚀 Getting Started Guide</h3>
                            <ul style="list-style: decimal; padding-left: 20px; line-height: 1.8;">
                                <li><strong>Activation:</strong> The plugin starts working immediately. No setup required!</li>
                                <li><strong>Runtime:</strong> When a user visits your site, BRIX checks if a WebP version exists. If not, it creates it instantly.</li>
                                <li><strong>Bulk Tool:</strong> Use the button above to pre-generate WebP for your entire library.</li>
                                <li><strong>Safety:</strong> We never touch your original JPG/PNG files. We store optimized versions in a separate <code>/brix-optimized/</code> folder.</li>
                                <li><strong>Deactivation:</strong> If you deactivate the plugin, we stop serving WebP images, but your <code>/brix-optimized/</code> folder stays safe on your server.</li>
                                <li><strong>Uninstall:</strong> If you delete the plugin completely, we will automatically wipe the <code>/brix-optimized/</code> folder to save your disk space.</li>
                            </ul>
                        </div>
                    </div>

                    <div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h3 style="margin-top: 0;">Live Statistics</h3>
                        <?php $this->dashboard_widget_content(); ?>
                        <p style="font-size: 11px; color: #94a3b8; margin-top: 20px; text-align: center;">Developed by <strong>Jatin Beniwal</strong> for BRIXLY.com</p>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#brix-bulk-start').click(function() {
                let btn = $(this);
                if (!confirm('This will scan your entire media library and generate WebP versions. Continue?')) return;
                
                btn.prop('disabled', true).text('Scanning Library...');
                
                function processBatch() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'brix_bulk_process',
                            security: '<?php echo wp_create_nonce("brix_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.remaining > 0) {
                                $('#brix-progress').text('Remaining: ' + response.data.remaining + ' images...');
                                processBatch();
                            } else {
                                $('#brix-progress').text('Optimization Complete!');
                                btn.prop('disabled', false).text('Start Bulk Optimization');
                            }
                        }
                    });
                }
                processBatch();
            });

            $('#brix-clear-cache').click(function() {
                if (!confirm('WARNING: This will permanently delete ALL optimized WebP images from the mirror folder. Originals will NOT be touched. Continue?')) return;
                
                let btn = $(this);
                btn.text('Deleting folder...').css('color', '#999');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'brix_clear_cache',
                        security: '<?php echo wp_create_nonce("brix_nonce"); ?>'
                    },
                    success: function() {
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX & BULK
     */
    public function ajax_clear_cache() {
        check_ajax_referer('brix_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/' . $this->optimized_dir;
        
        if (file_exists($path)) {
            $this->recursive_rmdir($path);
            update_option('brix_stats', ['total_optimized' => 0, 'bytes_saved' => 0]);
        }
        
        wp_send_json_success();
    }

    private function recursive_rmdir($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursive_rmdir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function ajax_bulk_process() {
        check_ajax_referer('brix_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status' => 'inherit',
            'posts_per_page' => 5,
            'meta_query' => [
                [
                    'key' => '_brix_optimized',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        $query = new WP_Query($args);
        $processed = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $this->get_optimized_url(wp_get_attachment_url($id));
                update_post_meta($id, '_brix_optimized', time());
                $processed++;
            }
        }

        wp_send_json_success(['remaining' => $query->found_posts - $processed]);
    }

    public function auto_optimize_on_upload($post_id) {
        if (wp_attachment_is_image($post_id)) {
            $this->get_optimized_url(wp_get_attachment_url($post_id));
            update_post_meta($post_id, '_brix_optimized', time());
        }
    }

    public function add_media_button($form_fields, $post) {
        if (!wp_attachment_is_image($post->ID)) return $form_fields;
        
        $form_fields['brix_status'] = [
            'label' => 'BRIX Optimizer',
            'input' => 'html',
            'html'  => '<span style="color: #46b450; font-weight: bold;">✓ Optimized by BRIX</span>'
        ];
        return $form_fields;
    }
}

new BRIX_Image_Optimizer();
