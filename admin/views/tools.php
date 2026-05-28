<?php
/**
 * Combined Tools View
 *
 * Displays Settings, Diagnostics, and Compliance in a tabbed interface.
 *
 * @package FormFlow
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include breadcrumbs partial
require_once ISF_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';

// Base URL for tabs
$base_url = admin_url('admin.php?page=isf-tools');
?>

<div class="wrap isf-admin-wrap">
    <?php isf_breadcrumbs(['Dashboard' => 'isf-dashboard'], __('Tools & Settings', 'formflow')); ?>

    <h1><?php esc_html_e('Tools & Settings', 'formflow'); ?></h1>

    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg('tab', 'license', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'license') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-network"></span>
            <?php esc_html_e('License', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'settings') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span>
            <?php esc_html_e('Settings', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'diagnostics', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'diagnostics') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-heart"></span>
            <?php esc_html_e('Diagnostics', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'compliance', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'compliance') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-shield"></span>
            <?php esc_html_e('Compliance', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'accessibility', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'accessibility') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-universal-access-alt"></span>
            <?php esc_html_e('Accessibility', 'formflow'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'new-editor', $base_url)); ?>"
           class="nav-tab <?php echo ($tab === 'new-editor') ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-edit"></span>
            <?php esc_html_e('New Editor (Beta)', 'formflow'); ?>
        </a>
    </nav>

    <div class="isf-tab-content">
        <?php if ($tab === 'license') : ?>
            <!-- License Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-license.php'; ?>

        <?php elseif ($tab === 'settings') : ?>
            <!-- Settings Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-settings.php'; ?>

        <?php elseif ($tab === 'diagnostics') : ?>
            <!-- Diagnostics Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-diagnostics.php'; ?>

        <?php elseif ($tab === 'compliance') : ?>
            <!-- Compliance Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-compliance.php'; ?>

        <?php elseif ($tab === 'accessibility') : ?>
            <!-- Accessibility Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-accessibility.php'; ?>

        <?php elseif ($tab === 'new-editor') : ?>
            <!-- New Editor (Beta) Tab -->
            <?php include ISF_PLUGIN_DIR . 'admin/views/tabs/tools-new-editor.php'; ?>

        <?php endif; ?>
    </div>
</div>
