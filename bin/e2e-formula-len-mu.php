<?php
/** No mail leaves the owned LEN proof clone. */
if ( realpath( ABSPATH ) === '/tmp/opf-image-formula-len-wp' ) {
	add_filter( 'pre_wp_mail', '__return_true' );
}
