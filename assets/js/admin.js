/**
 * Photolab admin — vanilla JS UI controller.
 *
 * No framework dependencies. Config passed via window.photolabConfig
 * (wp_localize_script). Handles upload pipeline, watermark modal, albums table.
 */

( function () {
	'use strict';

	const cfg       = window.photolabConfig || {};
	const restUrl   = cfg.restUrl  || '';
	const nonce     = cfg.nonce    || '';
	const CHUNK_SIZE = parseInt( cfg.chunkSize, 10 ) || 5;

	// ── REST helper ───────────────────────────────────────────────────────────

	async function apiFetch( endpoint, options ) {
		options = options || {};
		const url     = restUrl + endpoint;
		const headers = Object.assign( { 'X-WP-Nonce': nonce }, options.headers || {} );

		if ( ! ( options.body instanceof FormData ) ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await fetch( url, Object.assign( {}, options, { headers: headers } ) );

		if ( ! response.ok ) {
			const err = await response.json().catch( function () {
				return { message: response.statusText };
			} );
			const error  = new Error( err.message || 'HTTP ' + response.status );
			error.status = response.status;
			throw error;
		}

		return response.json();
	}

	// ── State ─────────────────────────────────────────────────────────────────

	const HEARTBEAT_INTERVAL_MS = 30000;

	const state = {
		upload: {
			isUploading:        false,
			processed:          0,
			total:              0,
			heartbeatInterval:  null,
			currentJobId:       null,
			aborted:            false,
			watermarkInterval:  null,
			watermarkAttempts:  0,
			watermarkFailures:  0,
		},
		watermark: {
			active:   cfg.watermarkActive   || false,
			url:      cfg.watermarkUrl      || '',
			position: cfg.watermarkPosition || 'bottom_right',
		},
		albums: {
			items:      [],
			total:      0,
			totalPages: 0,
			page:       1,
			isLoading:  false,
		},
	};

	// ── DOM refs ──────────────────────────────────────────────────────────────

	const elUploadForm        = document.getElementById( 'uploadPhotoForm' );
	const elAlbumName         = document.getElementById( 'albumName' );
	const elExpiration        = document.getElementById( 'expiration' );
	const elPrice             = document.getElementById( 'price' );
	const elFiles             = document.getElementById( 'files' );
	const elFileSelectText    = document.querySelector( '[data-pl="fileSelectText"]' );
	const elStatusLoading     = document.getElementById( 'statusLoading' );
	const elProgressBar       = document.getElementById( 'uploadProgressBar' );
	const elStatusText        = document.getElementById( 'uploadPhotosStatus' );
	const elUploadAlert       = document.getElementById( 'uploadPhotosAlert' );
	const elUploadBtn         = document.getElementById( 'uploadPhotosButton' );
	const elAlbumsTbody       = document.getElementById( 'albumsTbody' );
	const elLoadMoreBtn       = document.getElementById( 'loadMoreAlbumsButton' );
	const elWatermarkModalBtn = document.getElementById( 'watermarkModalButton' );
	const elWatermarkModal    = document.getElementById( 'watermarkModal' );
	const elCloseModalBtns    = document.querySelectorAll( '[data-pl="closeWatermarkModal"]' );
	const elShowWatermark     = document.getElementById( 'showWatermark' );
	const elUploadWatermark   = document.getElementById( 'uploadWatermark' );
	const elWatermarkPreview  = document.getElementById( 'watermarkPreview' );
	const elWatermarkFile     = document.getElementById( 'watermarkFile' );
	const elSaveWatermarkBtn  = document.getElementById( 'saveWatermarkButton' );
	const elDeleteWatermarkBtn= document.getElementById( 'deleteWatermarkButton' );
	const elWatermarkAlert    = document.getElementById( 'uploadWatermarkAlert' );

	// ── Upload ────────────────────────────────────────────────────────────────

	if ( elFiles ) {
		elFiles.addEventListener( 'change', function () {
			const count = elFiles.files.length;
			if ( elFileSelectText ) {
				elFileSelectText.textContent = count > 0 ? count + ' file(s) selected' : 'No file selected.';
			}
		} );
	}

	if ( elUploadForm ) {
		elUploadForm.addEventListener( 'submit', async function ( e ) {
			e.preventDefault();

			const albumName  = elAlbumName  ? elAlbumName.value.trim()  : '';
			const expiration = elExpiration ? elExpiration.value         : '';
			const price      = elPrice      ? parseFloat( elPrice.value ) || 0 : 0;
			const allFiles   = elFiles      ? Array.from( elFiles.files ) : [];

			if ( ! albumName ) {
				setUploadAlert( 'Please enter the album name.', false );
				return;
			}
			if ( allFiles.length === 0 ) {
				setUploadAlert( 'Please select at least one photo.', false );
				return;
			}

			setUploadingState( true, allFiles.length );

			let jobId, termId;
			state.upload.aborted = false;

			try {
				const startBody = new FormData();
				startBody.append( 'album_name', albumName );
				startBody.append( 'price', price );
				if ( expiration ) startBody.append( 'expiration_date', expiration );

				const startData = await apiFetch( '/upload/start', { method: 'POST', body: startBody } );
				jobId  = startData.job_id;
				termId = startData.term_id;
				state.upload.currentJobId = jobId;
				startHeartbeat( jobId );
			} catch ( err ) {
				setUploadingState( false, 0 );
				setUploadAlert( 'Error starting upload: ' + err.message, false );
				return;
			}

			let totalProcessed = 0;
			const allErrors    = [];

			for ( let i = 0; i < allFiles.length; i += CHUNK_SIZE ) {
				if ( state.upload.aborted ) {
					allErrors.push( 'Upload aborted by server (heartbeat lost).' );
					break;
				}

				const chunk = allFiles.slice( i, i + CHUNK_SIZE );

				try {
					const chunkBody = new FormData();
					chunkBody.append( 'job_id', jobId );
					chunkBody.append( 'term_id', termId );
					chunk.forEach( function ( file ) { chunkBody.append( 'files[]', file ); } );

					const chunkData = await apiFetch( '/upload/chunk', { method: 'POST', body: chunkBody } );
					totalProcessed += chunkData.processed;
					updateProgress( totalProcessed, allFiles.length );

					if ( chunkData.errors && chunkData.errors.length ) {
						allErrors.push.apply( allErrors, chunkData.errors );
					}
				} catch ( err ) {
					allErrors.push( 'Chunk ' + ( Math.floor( i / CHUNK_SIZE ) + 1 ) + ' error: ' + err.message );
				}
			}

			let completeOk = false;
			try {
				const completeBody = new FormData();
				completeBody.append( 'job_id', jobId );
				await apiFetch( '/upload/complete', { method: 'POST', body: completeBody } );
				completeOk = true;
			} catch ( err ) {
				console.error( 'Photolab /upload/complete error:', err.message );
			}

			stopHeartbeat();
			setUploadingState( false, 0 );
			if ( elFiles ) elFiles.value = '';
			if ( elFileSelectText ) elFileSelectText.textContent = 'No file selected.';

			if ( allErrors.length === 0 ) {
				setUploadAlert( 'Upload completed: ' + totalProcessed + ' photo(s) saved.', true );
			} else {
				setUploadAlert(
					'Upload completed with ' + allErrors.length + ' error(s). ' +
					allErrors.slice( 0, 3 ).join( ' | ' ),
					false
				);
			}

			if ( completeOk && totalProcessed > 0 ) {
				pollWatermarkProgress( jobId );
			}

			state.albums.page       = 1;
			state.albums.totalPages = 1;
			state.albums.items      = [];
			await loadMoreAlbums();
		} );
	}

	function setUploadingState( uploading, total ) {
		state.upload.isUploading = uploading;
		state.upload.total       = total;

		if ( elStatusLoading ) elStatusLoading.hidden = ! uploading;
		if ( elUploadBtn )     elUploadBtn.disabled   = uploading;
		if ( elAlbumName )     elAlbumName.disabled   = uploading;
		if ( elExpiration )    elExpiration.disabled  = uploading;
		if ( elPrice )         elPrice.disabled       = uploading;
		if ( elFiles )         elFiles.disabled       = uploading;

		if ( ! uploading ) {
			updateProgress( 0, 0 );
		}
	}

	function updateProgress( processed, total ) {
		const pct = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
		if ( elProgressBar ) {
			elProgressBar.style.width          = pct + '%';
			elProgressBar.setAttribute( 'aria-valuenow', processed );
		}
		if ( elStatusText ) {
			elStatusText.textContent = processed + ' / ' + ( total || 0 );
		}
	}

	function startHeartbeat( jobId ) {
		stopHeartbeat();
		state.upload.heartbeatInterval = setInterval( async function () {
			try {
				const body = new FormData();
				body.append( 'job_id', jobId );
				const data = await apiFetch( '/upload/heartbeat', { method: 'POST', body: body } );
				if ( data && data.aborted ) {
					state.upload.aborted = true;
					stopHeartbeat();
					setUploadAlert( 'Upload interrupted — refresh to retry.', false );
				}
			} catch ( err ) {
				console.error( 'Photolab heartbeat error:', err.message );
			}
		}, HEARTBEAT_INTERVAL_MS );
	}

	function stopHeartbeat() {
		if ( state.upload.heartbeatInterval ) {
			clearInterval( state.upload.heartbeatInterval );
			state.upload.heartbeatInterval = null;
		}
	}

	// ── Watermark polling ─────────────────────────────────────────────────────

	const WATERMARK_POLL_INTERVAL_MS = 2000;
	const WATERMARK_POLL_MAX_TICKS   = 300; // 300 × 2s = 10 min safety timeout.
	const WATERMARK_POLL_MAX_FAILURES = 5; // consecutive network/API failures.

	function stopWatermarkPolling() {
		if ( state.upload.watermarkInterval ) {
			clearInterval( state.upload.watermarkInterval );
			state.upload.watermarkInterval = null;
		}
		state.upload.watermarkAttempts = 0;
		state.upload.watermarkFailures = 0;
	}

	function setWatermarkStatusText( text ) {
		if ( elStatusText ) {
			elStatusText.textContent = text;
		}
		if ( elStatusLoading ) {
			elStatusLoading.hidden = false;
		}
	}

	function pollWatermarkProgress( albumId ) {
		stopWatermarkPolling();
		setWatermarkStatusText( 'Watermarking in progress...' );

		state.upload.watermarkInterval = setInterval( async function () {
			state.upload.watermarkAttempts += 1;

			if ( state.upload.watermarkAttempts > WATERMARK_POLL_MAX_TICKS ) {
				stopWatermarkPolling();
				setUploadAlert(
					'Watermarking is taking longer than expected. Reload the page to refresh status.',
					false
				);
				return;
			}

			try {
				const data = await apiFetch(
					'/photos/watermark-status?album_id=' + encodeURIComponent( albumId ),
					{ method: 'GET' }
				);

				const total     = parseInt( data.total,     10 ) || 0;
				const completed = parseInt( data.completed, 10 ) || 0;
				const pending   = parseInt( data.pending,   10 ) || 0;
				const failed    = parseInt( data.failed,    10 ) || 0;

				state.upload.watermarkFailures = 0;
				setWatermarkStatusText( 'Watermarking: ' + completed + ' / ' + total + ' photos' );

				if ( pending === 0 ) {
					stopWatermarkPolling();
					if ( failed > 0 ) {
						setWatermarkStatusText( '✓ Watermarking complete (' + failed + ' failed).' );
					} else {
						setWatermarkStatusText( '✓ Watermarking complete!' );
					}
				}
			} catch ( err ) {
				console.error( 'Photolab watermark-status error:', err.message );
				state.upload.watermarkFailures += 1;

				if ( state.upload.watermarkFailures >= WATERMARK_POLL_MAX_FAILURES ) {
					stopWatermarkPolling();
					setUploadAlert(
						'Lost connection while checking watermark status. Reload the page to refresh status.',
						false
					);
				}
			}
		}, WATERMARK_POLL_INTERVAL_MS );
	}

	function setUploadAlert( message, success ) {
		if ( ! elUploadAlert ) return;
		elUploadAlert.textContent = message;
		elUploadAlert.hidden      = ! message;

		elUploadAlert.classList.toggle( 'text-green-600',  success );
		elUploadAlert.classList.toggle( 'bg-green-50',     success );
		elUploadAlert.classList.toggle( 'border-green-200', success );
		elUploadAlert.classList.toggle( 'text-red-600',   ! success );
		elUploadAlert.classList.toggle( 'bg-red-50',      ! success );
		elUploadAlert.classList.toggle( 'border-red-200', ! success );
	}

	// ── Watermark modal ───────────────────────────────────────────────────────

	function syncWatermarkUI() {
		const active = state.watermark.active;

		if ( elShowWatermark )      elShowWatermark.hidden      = ! active;
		if ( elUploadWatermark )    elUploadWatermark.hidden    =   active;
		if ( elDeleteWatermarkBtn ) elDeleteWatermarkBtn.hidden = ! active;

		// Save always visible: uploads file when no watermark, updates position when active.
		if ( elSaveWatermarkBtn ) elSaveWatermarkBtn.hidden = false;

		if ( active && elWatermarkPreview ) {
			elWatermarkPreview.src = state.watermark.url + '?t=' + Date.now();
		}
	}

	function openWatermarkModal() {
		if ( elWatermarkModal ) elWatermarkModal.style.display = 'flex';
		if ( elWatermarkAlert ) { elWatermarkAlert.textContent = ''; elWatermarkAlert.hidden = true; }

		apiFetch( '/settings', { method: 'GET' } )
			.then( function ( data ) {
				state.watermark.active   = !! data.watermark_active;
				state.watermark.url      = data.watermark_url || '';
				state.watermark.position = data.watermark_position || 'bottom_right';

				const radio = document.querySelector( 'input[name="watermarkPosition"][value="' + state.watermark.position + '"]' );
				if ( radio ) radio.checked = true;

				syncWatermarkUI();
			} )
			.catch( function ( err ) {
				console.error( 'Photolab /settings error:', err.message );
				syncWatermarkUI();
			} );
	}

	function closeWatermarkModal() {
		if ( elWatermarkModal ) elWatermarkModal.style.display = 'none';
	}

	if ( elWatermarkModalBtn ) {
		elWatermarkModalBtn.addEventListener( 'click', openWatermarkModal );
	}

	elCloseModalBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', closeWatermarkModal );
	} );

	if ( elWatermarkModal ) {
		elWatermarkModal.addEventListener( 'click', function ( e ) {
			if ( e.target === elWatermarkModal ) closeWatermarkModal();
		} );
	}

	if ( elSaveWatermarkBtn ) {
		elSaveWatermarkBtn.addEventListener( 'click', async function ( e ) {
			e.preventDefault();

			const selectedPosition = document.querySelector( 'input[name="watermarkPosition"]:checked' );
			const position         = selectedPosition ? selectedPosition.value : 'bottom_right';

			// Watermark already active → update position only, no file needed.
			if ( state.watermark.active ) {
				try {
					const body = new FormData();
					body.append( 'position', position );
					await apiFetch( '/watermark/position', { method: 'POST', body: body } );
					state.watermark.position = position;
					setWatermarkAlert( 'Position saved.', true );
				} catch ( err ) {
					setWatermarkAlert( 'Error saving position: ' + err.message, false );
				}
				return;
			}

			// No watermark yet → upload file + position.
			if ( ! elWatermarkFile || ! elWatermarkFile.files.length ) {
				setWatermarkAlert( 'Please select a PNG file.', false );
				return;
			}

			const body = new FormData();
			body.append( 'watermark', elWatermarkFile.files[ 0 ] );
			body.append( 'position', position );

			try {
				const data               = await apiFetch( '/watermark', { method: 'POST', body: body } );
				state.watermark.active   = true;
				state.watermark.url      = data.watermark_url || '';
				state.watermark.position = position;
				syncWatermarkUI();
				setWatermarkAlert( 'Watermark saved.', true );
			} catch ( err ) {
				setWatermarkAlert( 'Error saving watermark: ' + err.message, false );
			}
		} );
	}

	if ( elDeleteWatermarkBtn ) {
		elDeleteWatermarkBtn.addEventListener( 'click', async function () {
			try {
				await apiFetch( '/watermark', { method: 'DELETE' } );
				state.watermark.active = false;
				state.watermark.url    = '';
				syncWatermarkUI();
				setWatermarkAlert( 'Watermark deleted.', true );
			} catch ( err ) {
				setWatermarkAlert( 'Error deleting watermark: ' + err.message, false );
			}
		} );
	}

	function setWatermarkAlert( message, success ) {
		if ( ! elWatermarkAlert ) return;
		elWatermarkAlert.textContent = message;
		elWatermarkAlert.hidden      = ! message;
		elWatermarkAlert.className   = 'mt-3 p-2 rounded text-[13px] ' +
			( success ? 'text-green-600' : 'text-red-600' );
	}

	// ── Albums table ──────────────────────────────────────────────────────────

	async function loadMoreAlbums() {
		if ( state.albums.isLoading ) return;
		state.albums.isLoading = true;
		if ( elLoadMoreBtn ) elLoadMoreBtn.disabled = true;

		try {
			const data = await apiFetch(
				'/albums?page=' + state.albums.page + '&per_page=20',
				{ method: 'GET' }
			);

			const incoming        = data.albums || [];
			state.albums.items    = state.albums.items.concat( incoming );
			state.albums.total    = data.total       || 0;
			state.albums.totalPages = data.total_pages || 0;
			state.albums.page    += 1;

			renderAlbumsTable( state.albums.items );
			updateLoadMoreButton();
		} catch ( err ) {
			console.error( 'Photolab /albums error:', err.message );
		} finally {
			state.albums.isLoading = false;
			if ( elLoadMoreBtn ) elLoadMoreBtn.disabled = false;
		}
	}

	if ( elLoadMoreBtn ) {
		elLoadMoreBtn.addEventListener( 'click', loadMoreAlbums );
	}

	async function deleteAlbum( albumId, btn ) {
		if ( ! confirm( 'Delete this album and all its products?' ) ) return;

		const row = document.querySelector( 'tr[data-album-id="' + albumId + '"]' );

		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = 'Deleting...';
		}

		try {
			await apiFetch( '/albums/' + albumId, { method: 'DELETE' } );

			state.albums.items = state.albums.items.filter( function ( a ) {
				return a.id !== albumId;
			} );

			if ( row ) {
				row.style.transition = 'opacity 300ms';
				row.style.opacity    = '0';
				setTimeout( function () {
					renderAlbumsTable( state.albums.items );
					updateLoadMoreButton();
				}, 300 );
			} else {
				renderAlbumsTable( state.albums.items );
				updateLoadMoreButton();
			}
		} catch ( err ) {
			if ( btn ) {
				btn.disabled    = false;
				btn.textContent = 'Delete';
			}
			alert( 'Error deleting album: ' + err.message );
		}
	}

	function renderAlbumsTable( items ) {
		if ( ! elAlbumsTbody ) return;
		elAlbumsTbody.innerHTML = '';

		if ( ! items.length ) {
			elAlbumsTbody.innerHTML =
				'<tr><td colspan="5" class="px-6 py-4 text-center text-gray-500 text-[13px]">No albums found.</td></tr>';
			return;
		}

		items.forEach( function ( album ) {
			const expiration = album.expiration_date
				? album.expiration_date.substring( 0, 10 )
				: 'None';

			let statusClass;
			let statusLabel = album.status;
			let statusTitle = '';

			switch ( album.status ) {
				case 'idle':
					statusClass = 'bg-green-50 text-green-700';
					break;
				case 'aborted':
					statusClass = 'bg-red-50 text-red-700';
					statusLabel = 'Aborted';
					statusTitle = 'Upload interrupted — ' + parseInt( album.photo_count || 0, 10 ) + ' photo(s) uploaded';
					break;
				case 'watermarking':
					statusClass = 'bg-yellow-50 text-yellow-700';
					statusLabel = 'Processing';
					statusTitle = 'Watermarking in progress';
					break;
				case 'uploading':
					statusClass = 'bg-yellow-50 text-yellow-700';
					statusLabel = 'Uploading';
					break;
				case 'deleting':
					statusClass = 'bg-gray-100 text-gray-700';
					break;
				default:
					statusClass = 'bg-yellow-50 text-yellow-700';
			}

			const showReset    = album.status === 'aborted' || album.status === 'watermarking';
			const lockedDelete = album.status === 'uploading' || album.status === 'watermarking' || album.status === 'deleting';

			const titleAttr = statusTitle ? ' title="' + escHtml( statusTitle ) + '"' : '';
			const resetBtn  = showReset
				? '<button class="text-[13px] text-amber-600 hover:text-amber-700 font-medium hover:underline mr-3" data-pl-action="reset" data-album-id="' + parseInt( album.id, 10 ) + '">Reset</button>'
				: '';

			const deleteBtnClass = lockedDelete
				? 'text-[13px] text-gray-400 cursor-not-allowed font-medium'
				: 'text-[13px] text-red-600 hover:text-red-700 font-medium hover:underline';
			const deleteAttrs    = lockedDelete ? ' disabled title="' + escHtml( 'Delete is disabled while album is ' + album.status ) + '"' : '';

			const row         = document.createElement( 'tr' );
			row.className     = 'hover:bg-gray-50 transition border-b border-gray-100';
			row.dataset.albumId = album.id;
			row.innerHTML =
				'<td class="px-6 py-3.5"><strong class="text-[13px] text-gray-900 font-semibold">' + escHtml( album.album_name ) + '</strong></td>' +
				'<td class="px-6 py-3.5"><span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-md bg-blue-50 text-blue-700">' + parseInt( album.photo_count || 0, 10 ) + ' photos</span></td>' +
				'<td class="px-6 py-3.5 text-[13px] text-gray-600">' + escHtml( expiration ) + '</td>' +
				'<td class="px-6 py-3.5"><span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-md ' + statusClass + '"' + titleAttr + '>' + escHtml( statusLabel ) + '</span></td>' +
				'<td class="px-6 py-3.5">' + resetBtn +
					'<button class="' + deleteBtnClass + '" data-pl-action="delete" data-album-id="' + parseInt( album.id, 10 ) + '"' + deleteAttrs + '>Delete</button></td>';

			const buttons = row.querySelectorAll( 'button[data-pl-action]' );
			buttons.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					const id = parseInt( this.dataset.albumId, 10 );
					if ( this.dataset.plAction === 'reset' ) {
						resetAlbum( id, this );
					} else {
						deleteAlbum( id, this );
					}
				} );
			} );

			elAlbumsTbody.appendChild( row );
		} );
	}

	async function resetAlbum( albumId, btn ) {
		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = 'Resetting...';
		}

		try {
			await apiFetch( '/albums/' + albumId + '/reset', { method: 'POST' } );

			state.albums.page       = 1;
			state.albums.totalPages = 1;
			state.albums.items      = [];
			await loadMoreAlbums();

			setUploadAlert( 'Album reset successfully. You can now upload again.', true );
			setTimeout( function () {
				if ( elUploadAlert && elUploadAlert.textContent === 'Album reset successfully. You can now upload again.' ) {
					elUploadAlert.hidden      = true;
					elUploadAlert.textContent = '';
				}
			}, 4000 );
		} catch ( err ) {
			if ( btn ) {
				btn.disabled    = false;
				btn.textContent = 'Reset';
			}
			alert( 'Error resetting album: ' + err.message );
		}
	}

	function updateLoadMoreButton() {
		if ( ! elLoadMoreBtn ) return;
		const show = state.albums.totalPages > 1 && state.albums.page <= state.albums.totalPages;
		elLoadMoreBtn.style.display = show ? 'flex' : 'none';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── Init ──────────────────────────────────────────────────────────────────

	if ( elStatusLoading )  elStatusLoading.hidden          = true;
	if ( elUploadAlert )    elUploadAlert.hidden            = true;
	if ( elWatermarkModal ) elWatermarkModal.style.display  = 'none';
	if ( elWatermarkAlert ) elWatermarkAlert.hidden         = true;

	if ( false === cfg.userCanUpload ) {
		if ( elUploadBtn ) elUploadBtn.disabled = true;
		setUploadAlert(
			'Maximum 3 concurrent uploads reached. Wait for one to complete before starting a new one.',
			false
		);
	}

	syncWatermarkUI();
	updateLoadMoreButton();
	loadMoreAlbums();

	window.addEventListener( 'beforeunload', function () {
		if ( state.upload.heartbeatInterval ) clearInterval( state.upload.heartbeatInterval );
		if ( state.upload.watermarkInterval ) clearInterval( state.upload.watermarkInterval );
	} );

	// ── Move admin notices into #photolab-notices ───────────────────────────

	var noticesContainer = document.getElementById( 'photolab-notices' );
	if ( noticesContainer ) {
		var notices = document.querySelectorAll( '.notice' );
		for ( var i = 0; i < notices.length; i++ ) {
			noticesContainer.appendChild( notices[ i ] );
		}
	}
}() );
