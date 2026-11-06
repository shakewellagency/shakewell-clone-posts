( function ( wp ) {
	var d = window.shakewellClonePosts;
	var Info = ( wp.editor && wp.editor.PluginPostStatusInfo ) || wp.editPost.PluginPostStatusInfo;
	var el = wp.element.createElement;

	wp.plugins.registerPlugin( 'shakewell-clone-posts', {
		render: function () {
			return el(
				Info,
				null,
				el(
					'div',
					null,
					d.copyUrl ? el( 'p', null, el( 'a', { href: d.copyUrl }, d.copyLabel ) ) : null,
					d.ofUrl ? el( 'p', null, d.ofLabel + ' ', el( 'a', { href: d.ofUrl }, d.ofTitle ) ) : null
				)
			);
		},
	} );
} )( window.wp );
