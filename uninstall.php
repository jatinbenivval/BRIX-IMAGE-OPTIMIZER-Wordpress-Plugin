<?php
/**
 * BRIX Image Optimizer Uninstall
 * 
 * Completely wipes out the mirrored WebP folder and stats when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$upload_dir = wp_upload_dir();
$path = $upload_dir['basedir'] . '/brix-optimized';

if (file_exists($path)) {
    brix_recursive_rmdir($path);
}

// Clear options
delete_option('brix_stats');
delete_option('brix_quality');

/**
 * Helper to delete folders recursively
 */
function brix_recursive_rmdir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? brix_recursive_rmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
