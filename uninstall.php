<?php
// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// remove plugin options
delete_option('ps_live_shipping_rates_url');
delete_option('ps_live_shipping_rates_secret');
