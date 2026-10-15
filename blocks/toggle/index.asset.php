<?php
/**
 * Script dependencies for the Dark Mode Toggle block's editor script. The
 * script is plain ES5 against WordPress's globals, so there is no build step
 * to generate this file.
 *
 * @package GP_Dark_Mode
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
	'version'      => defined( 'OGM_GPDM_VERSION' ) ? OGM_GPDM_VERSION : false,
);
