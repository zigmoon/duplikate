/**
 * Duplikate — the editor entry.
 *
 * Adds one row to the post status panel of the block editor, so the same
 * action that sits under the title of the list is reachable while writing.
 * Everything is read from the localized object: the URL already carries the
 * per-post nonce built in PHP, and the strings come translated from PHP too.
 *
 * Written without a build step: no JSX, no bundler, no dependency of our own.
 */
( function ( wp ) {
	if ( ! wp || ! wp.element || ! wp.plugins ) {
		return;
	}

	var data = window.duplikateEditor;

	if ( ! data || ! data.url ) {
		return;
	}

	var slot = ( wp.editor && wp.editor.PluginPostStatusInfo ) ||
		( wp.editPost && wp.editPost.PluginPostStatusInfo );

	if ( ! slot ) {
		return;
	}

	var el = wp.element.createElement;

	function Duplicate() {
		return el(
			slot,
			{ className: 'duplikate-editor-action' },
			el(
				'a',
				{
					href: data.url,
					className: 'components-button is-link is-compact',
				},
				data.label
			),
			el( 'span', { className: 'duplikate-editor-hint' }, data.hint )
		);
	}

	try {
		wp.plugins.registerPlugin( 'duplikate', { render: Duplicate } );
	} catch ( error ) {
		// Another plugin already owns the name, or the editor changed shape:
		// the list action still works, so stay silent.
	}
} )( window.wp );
