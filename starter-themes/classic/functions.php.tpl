<?php
/** {{THEME_NAME}} functions. */
function {{FUNCTION_PREFIX}}_setup(){add_theme_support('title-tag');add_theme_support('post-thumbnails');add_theme_support('responsive-embeds');add_theme_support('html5',array('search-form','comment-form','comment-list','gallery','caption'));register_nav_menus(array('primary'=>__('Primary Menu','{{THEME_SLUG}}')));}
add_action('after_setup_theme','{{FUNCTION_PREFIX}}_setup');
function {{FUNCTION_PREFIX}}_scripts(){wp_enqueue_style('{{THEME_SLUG}}-style',get_stylesheet_uri(),array(),wp_get_theme()->get('Version'));$dist=get_stylesheet_directory().'/dist/styles.css';if(file_exists($dist))wp_enqueue_style('{{THEME_SLUG}}-compiled',get_theme_file_uri('dist/styles.css'),array(),filemtime($dist));}
add_action('wp_enqueue_scripts','{{FUNCTION_PREFIX}}_scripts');
function {{FUNCTION_PREFIX}}_editor_tokens(){add_editor_style('editor.css');}
add_action('after_setup_theme','{{FUNCTION_PREFIX}}_editor_tokens',20);
