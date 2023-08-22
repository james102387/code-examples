<?php
/*
Plugin Name: Simple Chat Plugin
Description: A basic chat plugin for WordPress.
Version: 1.0
Author: James Potts
*/

// Enqueue styles and scripts
function simple_chat_enqueue_assets() {
    wp_enqueue_style('simple-chat-style', plugin_dir_url(__FILE__) . 'css/style.css');
    wp_enqueue_script('simple-chat-script', plugin_dir_url(__FILE__) . 'js/script.js', array('jquery'), '1.0', true);
}
add_action('wp_enqueue_scripts', 'simple_chat_enqueue_assets');

// Include the chat template
function simple_chat_display() {
    ob_start();
    include(plugin_dir_path(__FILE__) . 'inc/chat-template.php');
    return ob_get_clean();
}
add_shortcode('simple_chat', 'simple_chat_display');
?>
