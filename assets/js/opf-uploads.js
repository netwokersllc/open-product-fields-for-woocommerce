/** Native file input enhanced with private Ajax uploads; no runtime dependencies. */
( () => {
	let session;
	const bootstrap = ( url ) => {
		if ( ! session ) session = fetch( url + '/session', { method: 'POST', credentials: 'same-origin' } ).then( ( response ) => {
			if ( ! response.ok ) throw new Error( 'Uploads are unavailable. Refresh the page.' );
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
		input.removeAttribute( 'name' );
		input.required = false;
		const validity = () => input.setCustomValidity( busy ? 'Wait for uploads to finish.' : ( required && ! files.querySelector( 'input[type="hidden"]' ) ? 'Choose a file.' : '' ) );
		const progress = document.createElement( 'progress' );
		progress.max = 100; progress.value = 0; progress.hidden = true;
		progress.setAttribute( 'aria-label', 'File upload progress' );
		wrapper.append( progress );
		const help = document.createElement( 'p' );
		help.textContent = 'Choose files or drop them here.';
		wrapper.insertBefore( help, input );
		const upload = async ( selected ) => {
			if ( busy || input.disabled || wrapper.closest( '[hidden]' ) ) return;
			if ( files.childElementCount + selected.length > limit ) { status.textContent = 'Remove a file before uploading another.'; input.value = ''; return; }
			busy = true; validity(); progress.hidden = false;
			try {
				const { nonce } = await bootstrap( url );
				for ( const file of selected ) {
					status.textContent = 'Uploading ' + file.name;
					progress.value = 0;
					const product = form.querySelector( '[name="variation_id"]' )?.value || form.querySelector( '[name="add-to-cart"]' )?.value || form.querySelector( 'button[name="add-to-cart"]' )?.value;
					const data = new FormData();
					data.append( 'product_id', product || '' ); data.append( 'group_id', wrapper.dataset.opfUploadGroup ); data.append( 'field_id', wrapper.dataset.opfUploadField ); data.append( 'file', file );
					const result = await new Promise( ( resolve, reject ) => {
						const request = new XMLHttpRequest();
						request.open( 'POST', url ); request.setRequestHeader( 'X-OPF-Upload-Nonce', nonce ); request.timeout = 120000;
						request.upload.onprogress = ( event ) => { if ( event.lengthComputable ) progress.value = Math.round( event.loaded / event.total * 100 ); };
						request.onerror = request.ontimeout = () => reject( new Error( 'Upload failed. Try again.' ) );
						request.onload = () => {
							let body; try { body = JSON.parse( request.responseText ); } catch { reject( new Error( 'Upload failed. Try again.' ) ); return; }
							if ( request.status !== 201 ) reject( new Error( body.message || 'Upload failed. Try again.' ) ); else resolve( body );
						};
						request.send( data );
					} );
					const row = document.createElement( 'div' );
					const token = document.createElement( 'input' );
					token.type = 'hidden'; token.name = wrapper.dataset.opfUploadName + '[]'; token.value = result.token; token.dataset.opfUploadToken = '1';
					const name = document.createElement( 'span' ); name.textContent = result.name;
					const remove = document.createElement( 'button' ); remove.type = 'button'; remove.textContent = 'Remove'; remove.setAttribute( 'aria-label', 'Remove ' + result.name );
					remove.addEventListener( 'click', async () => {
						if ( busy ) return;
						busy = true; validity(); remove.disabled = true;
						try {
							const response = await fetch( url + '/' + result.token, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-OPF-Upload-Nonce': nonce } } );
							if ( ! response.ok ) throw new Error( 'Could not remove this file.' );
							row.remove(); status.textContent = 'File removed.';
						} catch ( error ) { status.textContent = error.message; }
						finally { busy = false; remove.disabled = false; validity(); input.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
					} );
					row.append( token, name, remove ); files.append( row );
				}
				status.textContent = 'Upload complete.';
			} catch ( error ) { status.textContent = error.message; }
			finally { busy = false; progress.hidden = true; input.value = ''; validity(); input.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
		};
		input.addEventListener( 'change', () => { if ( input.files.length ) upload( Array.from( input.files ) ); } );
		wrapper.addEventListener( 'dragover', ( event ) => { event.preventDefault(); } );
		wrapper.addEventListener( 'drop', ( event ) => { event.preventDefault(); if ( event.dataTransfer?.files ) upload( Array.from( event.dataTransfer.files ) ); } );
		form.addEventListener( 'submit', ( event ) => { if ( busy ) { event.preventDefault(); status.textContent = 'Wait for uploads to finish.'; } } );
		validity();
	} );
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', init ); else init();
} )();
