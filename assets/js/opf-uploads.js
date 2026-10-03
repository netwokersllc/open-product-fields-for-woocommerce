/** Native file input enhanced with private Ajax uploads; no runtime dependencies. */
( () => {
	let session;
	// Translated strings injected via window.OPF_I18N (Assets::enqueue_frontend);
	// English fallbacks keep the script functional when absent.
	const I18N = window.OPF_I18N || {};
	const i18n = ( key, fallback ) => I18N[ key ] || fallback;
	const i18nFmt = ( key, fallback, value ) => i18n( key, fallback ).replace( /%[sd]|%\d+\$[sd]/, () => String( value ) );
	// Preview selection is display-only: the browser MIME decides whether the
	// locally selected bytes can be shown inline. Server-side validation remains
	// authoritative and never trusts this value. Mirrors WAPF, which drops the
	// preview for TIFF/HEIC because browsers cannot render them.
	const isPreviewableImage = ( type ) => !! type && 0 === String( type ).indexOf( 'image/' ) && 'image/tiff' !== type && 'image/heic' !== type;
	const releasePreview = ( row ) => {
		if ( row.dataset.opfUploadObjectUrl && window.URL && window.URL.revokeObjectURL ) window.URL.revokeObjectURL( row.dataset.opfUploadObjectUrl );
		delete row.dataset.opfUploadObjectUrl;
	};
	const bootstrap = ( url ) => {
		if ( ! session ) session = fetch( url + '/session', { method: 'POST', credentials: 'same-origin' } ).then( ( response ) => {
			if ( ! response.ok ) throw new Error( i18n( 'uploads_unavailable', 'Uploads are unavailable. Refresh the page.' ) );
			return response.json();
		} ).catch( ( error ) => { session = null; throw error; } );
		return session;
	};
	const init = () => document.querySelectorAll( '[data-opf-upload="modern"]' ).forEach( ( wrapper ) => {
		if ( wrapper.dataset.opfUploadReady ) return;
		const input = wrapper.querySelector( 'input[type="file"]' );
		const form = wrapper.closest( 'form' );
		if ( ! input || ! form ) return;
		wrapper.dataset.opfUploadReady = '1';
		form.enctype = 'multipart/form-data';
		const status = wrapper.querySelector( '.opf-upload__status' );
		const files = wrapper.querySelector( '.opf-upload__files' );
		const url = wrapper.dataset.opfUploadUrl;
		const limit = Number( wrapper.dataset.opfUploadLimit );
		const required = input.required;
		let busy = false;
		if ( status && ! status.getAttribute( 'role' ) ) status.setAttribute( 'role', 'status' );
		if ( status && ! status.getAttribute( 'aria-live' ) ) status.setAttribute( 'aria-live', 'polite' );
		if ( files && ! files.getAttribute( 'role' ) ) files.setAttribute( 'role', 'list' );
		input.removeAttribute( 'name' );
		input.required = false;
		// The friendly drop hint explains the control; associate it so assistive
		// technology announces the instruction with the native file input.
		const hint = 'opf-upload-hint-' + ( wrapper.dataset.opfUploadField || 'field' ) + '-' + ( wrapper.dataset.opfUploadGroup || 'group' );
		input.setAttribute( 'aria-describedby', [ input.getAttribute( 'aria-describedby' ), hint ].filter( Boolean ).join( ' ' ) );
		wrapper.addEventListener( 'pagehide', () => files && files.querySelectorAll( '.opf-upload__file' ).forEach( releasePreview ) );
		const validity = () => input.setCustomValidity( busy ? i18n( 'wait_for_uploads', 'Wait for uploads to finish.' ) : ( required && ! files.querySelector( 'input[type="hidden"]' ) ? i18n( 'choose_a_file', 'Choose a file.' ) : '' ) );
		const progress = document.createElement( 'progress' );
		progress.max = 100; progress.value = 0; progress.hidden = true;
		progress.setAttribute( 'aria-label', i18n( 'file_upload_progress', 'File upload progress' ) );
		wrapper.append( progress );
		const help = document.createElement( 'p' );
		help.id = hint;
		help.className = 'opf-upload__hint';
		help.textContent = i18n( 'choose_files_or_drop', 'Choose files or drop them here.' );
		wrapper.insertBefore( help, input );
		const upload = async ( selected ) => {
			if ( busy || input.disabled || wrapper.closest( '[hidden]' ) ) return;
			if ( files.childElementCount + selected.length > limit ) { status.textContent = i18n( 'remove_file_first', 'Remove a file before uploading another.' ); input.value = ''; return; }
			busy = true; validity(); progress.hidden = false;
			try {
				const { nonce } = await bootstrap( url );
				for ( const file of selected ) {
					status.textContent = i18nFmt( 'uploading', 'Uploading %s', file.name );
					progress.value = 0;
					const product = form.querySelector( '[name="variation_id"]' )?.value || form.querySelector( '[name="add-to-cart"]' )?.value || form.querySelector( 'button[name="add-to-cart"]' )?.value;
					const data = new FormData();
					data.append( 'product_id', product || '' ); data.append( 'group_id', wrapper.dataset.opfUploadGroup ); data.append( 'field_id', wrapper.dataset.opfUploadField ); data.append( 'file', file );
					const result = await new Promise( ( resolve, reject ) => {
						const request = new XMLHttpRequest();
						request.open( 'POST', url ); request.setRequestHeader( 'X-OPF-Upload-Nonce', nonce ); request.timeout = 120000;
						request.upload.onprogress = ( event ) => { if ( event.lengthComputable ) progress.value = Math.round( event.loaded / event.total * 100 ); };
						request.onerror = request.ontimeout = () => reject( new Error( i18n( 'upload_failed', 'Upload failed. Try again.' ) ) );
						request.onload = () => {
							let body; try { body = JSON.parse( request.responseText ); } catch { reject( new Error( i18n( 'upload_failed', 'Upload failed. Try again.' ) ) ); return; }
							if ( request.status !== 201 ) reject( new Error( body.message || i18n( 'upload_failed', 'Upload failed. Try again.' ) ) ); else resolve( body );
						};
						request.send( data );
					} );
					const row = document.createElement( 'div' );
					row.className = 'opf-upload__file';
					if ( files.getAttribute( 'role' ) ) row.setAttribute( 'role', 'listitem' );
					const token = document.createElement( 'input' );
					token.type = 'hidden'; token.name = wrapper.dataset.opfUploadName + '[]'; token.value = result.token; token.dataset.opfUploadToken = '1';
					// Image previews use a same-document object URL of the locally
					// selected bytes — never a public or ACL-weakened URL.
					const preview = document.createElement( 'span' ); preview.className = 'opf-upload__preview';
					if ( isPreviewableImage( file.type ) ) {
						const thumb = document.createElement( 'img' );
						thumb.className = 'opf-upload__thumb'; thumb.alt = ''; thumb.decoding = 'async';
						const objectUrl = URL.createObjectURL( file );
						row.dataset.opfUploadObjectUrl = objectUrl; thumb.src = objectUrl;
						preview.appendChild( thumb );
					}
					const name = document.createElement( 'span' ); name.className = 'opf-upload__name'; name.textContent = result.name; name.title = result.name;
					const remove = document.createElement( 'button' );
					remove.type = 'button'; remove.className = 'opf-upload__remove'; remove.textContent = i18n( 'remove', 'Remove' );
					remove.setAttribute( 'aria-label', i18nFmt( 'remove_file', 'Remove %s', result.name ) );
					const cells = [ token ];
					if ( preview.childElementCount ) cells.push( preview );
					cells.push( name, remove );
					remove.addEventListener( 'click', async () => {
						if ( busy ) return;
						busy = true; validity(); remove.disabled = true;
						try {
							const response = await fetch( url + '/' + result.token, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-OPF-Upload-Nonce': nonce } } );
							if ( ! response.ok ) throw new Error( i18n( 'could_not_remove', 'Could not remove this file.' ) );
							releasePreview( row ); row.remove(); status.textContent = i18n( 'file_removed', 'File removed.' );
						} catch ( error ) { status.textContent = error.message; }
						finally { busy = false; remove.disabled = false; validity(); input.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
					} );
					row.append( ...cells ); files.append( row );
				}
				status.textContent = i18n( 'upload_complete', 'Upload complete.' );
			} catch ( error ) { status.textContent = error.message; }
			finally { busy = false; progress.hidden = true; input.value = ''; validity(); input.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
		};
		input.addEventListener( 'change', () => { if ( input.files.length ) upload( Array.from( input.files ) ); } );
		wrapper.addEventListener( 'dragover', ( event ) => { event.preventDefault(); } );
		wrapper.addEventListener( 'drop', ( event ) => { event.preventDefault(); if ( event.dataTransfer?.files ) upload( Array.from( event.dataTransfer.files ) ); } );
		form.addEventListener( 'submit', ( event ) => { if ( busy ) { event.preventDefault(); status.textContent = i18n( 'wait_for_uploads', 'Wait for uploads to finish.' ); } } );
		validity();
	} );
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', init ); else init();
} )();
