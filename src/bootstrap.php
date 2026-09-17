<?php

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command( 'email-debug', 'WpEmailDebug\\Command' );
