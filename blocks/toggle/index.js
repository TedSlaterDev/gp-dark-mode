/**
 * Dark Mode Toggle block — editor side.
 *
 * Server-rendered (save returns null): the front end gets the same button as
 * the menu-bar toggle, from OGM_GP_Dark_Mode::render_toggle_block(). The
 * editor shows a static, non-interactive picture of the switch; it doesn't
 * toggle the editor canvas.
 */
( function ( blocks, element, blockEditor ) {
	'use strict';

	var el = element.createElement;

	function sun() {
		return el(
			'svg',
			{ viewBox: '0 0 24 24', width: 14, height: 14, fill: 'none', stroke: 'currentColor', strokeWidth: 2.5, strokeLinecap: 'round', 'aria-hidden': 'true', focusable: 'false' },
			el( 'circle', { cx: 12, cy: 12, r: 4.5, fill: 'currentColor', stroke: 'none' } ),
			el( 'path', { d: 'M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8' } )
		);
	}

	function moon( size ) {
		return el(
			'svg',
			{ viewBox: '0 0 24 24', width: size, height: size, fill: 'currentColor', 'aria-hidden': 'true', focusable: 'false' },
			el( 'path', { d: 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z' } )
		);
	}

	blocks.registerBlockType( 'gp-dark-mode/toggle', {
		icon: moon( 24 ),

		edit: function () {
			var blockProps = blockEditor.useBlockProps();
			return el(
				'div',
				blockProps,
				el(
					'span',
					{ className: 'dm-track', 'aria-hidden': 'true' },
					el( 'span', { className: 'dm-icon dm-sun' }, sun() ),
					el( 'span', { className: 'dm-icon dm-moon' }, moon( 14 ) ),
					el( 'span', { className: 'dm-knob' } )
				)
			);
		},

		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );
