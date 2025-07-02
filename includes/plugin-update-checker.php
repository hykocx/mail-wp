<?php
    // Prevent direct access
    if (!defined('ABSPATH')) {
        exit;
    }
    
    require 'plugin-update-checker/plugin-update-checker.php';
    use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
    
    $myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/hykocx/mail-wp/',
        HYMAILWP_PLUGIN_FILE,
        'mailwp'
    );
    
    //Set the branch that contains the stable release.
    $myUpdateChecker->setBranch('master');