<?php
/**
 * Photolab admin page template.
 *
 * @package Photolab
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- ── Admin Notices Container ──────────────────────────────────────── -->
<div id="photolab-notices"></div>

<div class="wrap max-w-7xl mx-auto bg-white rounded-3xl shadow-sm border border-gray-200 p-8 pb-6">

	<!-- ── Upload Photo ─────────────────────────────────────────────────── -->
	<section class="mb-8">
		<div class="flex w-full justify-between items-center px-6 mb-2">
			<img
				src="<?php echo esc_url( PHOTOLAB_PLUGIN_URL . 'assets/logo.svg' ); ?>"
				alt="Photolab"
				class="h-8 max-w-[150px]"
			>
			<div class="w-[70%]" id="statusLoading" hidden>
				<div class="flex justify-end">
					<span class="text-xs text-gray-600 mb-2" id="uploadPhotosStatus">0 / 0</span>
				</div>
				<div class="w-full bg-gray-200 rounded-full h-2">
					<div
						class="bg-gray-900 h-2 rounded-full transition-all duration-300"
						id="uploadProgressBar"
						role="progressbar"
						aria-valuemin="0"
						aria-valuemax="100"
						aria-valuenow="0"
						style="width:0%"
					></div>
				</div>
			</div>
		</div>

		<div class="bg-white rounded-2xl border border-gray-200 p-6">
			<form id="uploadPhotoForm" method="post" enctype="multipart/form-data">

				<!-- Row 1: Name, Expiration, Watermark -->
				<div class="grid grid-cols-[1fr_350px_auto] gap-x-4 items-end mb-5">
					<div>
						<label class="block text-[13px] font-normal text-gray-900 mb-2">
							<?php esc_html_e( 'Name', 'todot-photolab' ); ?>
						</label>
						<input
							type="text"
							id="albumName"
							name="albumName"
							placeholder="Album name"
							class="w-full h-[38px] px-3 text-[13px] border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400"
							required
						>
					</div>
					<div class="w-full">
						<label class="block text-[13px] font-normal text-gray-900 mb-2">
							<?php esc_html_e( 'Album expiration', 'todot-photolab' ); ?>
						</label>
						<input
							type="date"
							id="expiration"
							name="expiration"
							class="w-full h-[38px] px-3 text-[13px] border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400"
						>
					</div>
					<div class="flex justify-end">
						<button
							type="button"
							id="watermarkModalButton"
							class="bg-[#0a1929] text-white h-[38px] px-7 rounded-lg text-[13px] font-medium hover:bg-[#152535] transition flex items-center gap-2 whitespace-nowrap"
						>
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<circle cx="12" cy="12" r="10" stroke-width="2"/>
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/>
							</svg>
							<?php esc_html_e( 'Watermark', 'todot-photolab' ); ?>
						</button>
					</div>
				</div>

				<!-- Row 2: Price, Files, Upload -->
				<div class="grid grid-cols-[110px_1fr_auto] gap-x-4 items-end">
					<div>
						<label class="block text-[13px] font-normal text-gray-900 mb-2">
							<?php esc_html_e( 'Price', 'todot-photolab' ); ?>
						</label>
						<input
							type="number"
							id="price"
							name="price"
							placeholder="e.g. 9"
							min="0"
							step="0.01"
							class="w-full h-[38px] px-3 text-[13px] border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400"
							required
						>
					</div>
					<div>
						<label class="block text-[13px] font-normal text-gray-900 mb-2">
							<?php esc_html_e( 'Select photos', 'todot-photolab' ); ?>
						</label>
						<div class="flex items-center gap-2 h-[38px] border border-gray-300 rounded-md">
							<label
								for="files"
								class="h-[30px] ms-1 px-2 bg-[#d1d5db] text-gray-800 rounded-md hover:bg-[#c4c8ce] transition text-[13px] font-normal cursor-pointer flex items-center"
							>browse...</label>
							<input
								type="file"
								id="files"
								name="files[]"
								accept="image/*"
								multiple
								class="hidden"
							>
							<span class="text-[13px] text-gray-600" data-pl="fileSelectText">No file selected.</span>
						</div>
					</div>
					<div class="flex justify-end">
						<button
							type="submit"
							id="uploadPhotosButton"
							class="bg-[#d1d5db] text-gray-800 h-[38px] px-7 rounded-lg text-[13px] font-medium hover:bg-[#c4c8ce] transition flex items-center gap-2 whitespace-nowrap"
						>
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
							</svg>
							<?php esc_html_e( 'Upload', 'todot-photolab' ); ?>
						</button>
					</div>
				</div>

				<!-- Alert -->
				<div
					class="mt-4 p-3 rounded-lg border"
					id="uploadPhotosAlert"
					role="alert"
					hidden
				></div>
			</form>
		</div>
	</section>

	<!-- ── Galleries Status ─────────────────────────────────────────────── -->
	<section>
		<div class="bg-white rounded-2xl border border-gray-200 p-6">
			<h2 class="text-xl font-semibold text-gray-900 mb-6">
				<?php esc_html_e( 'Galleries Status', 'todot-photolab' ); ?>
			</h2>

			<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
				<div class="max-h-[400px] overflow-y-auto" id="albumsTable">
					<table class="w-full text-sm text-left border-collapse">
						<thead class="text-[13px] font-medium text-gray-600 bg-gray-50 sticky top-0 border-b border-gray-200">
							<tr>
								<th class="px-6 py-3 font-medium"><?php esc_html_e( 'Album', 'todot-photolab' ); ?></th>
								<th class="px-6 py-3 font-medium"><?php esc_html_e( 'N. Photos', 'todot-photolab' ); ?></th>
								<th class="px-6 py-3 font-medium"><?php esc_html_e( 'Expiration', 'todot-photolab' ); ?></th>
								<th class="px-6 py-3 font-medium"><?php esc_html_e( 'Status', 'todot-photolab' ); ?></th>
								<th class="px-6 py-3 font-medium"><?php esc_html_e( 'Action', 'todot-photolab' ); ?></th>
							</tr>
						</thead>
						<tbody id="albumsTbody"></tbody>
					</table>
				</div>
			</div>

			<div class="mt-6">
				<button
					type="button"
					id="loadMoreAlbumsButton"
					class="px-6 h-10 bg-[#d1d5db] text-gray-800 rounded-xl text-[14px] font-medium hover:bg-[#c4c8ce] transition flex items-center gap-2"
					style="display:none"
				>
					<?php esc_html_e( 'Load More Galleries...', 'todot-photolab' ); ?>
				</button>
			</div>
		</div>
	</section>

	<!-- ── Watermark Modal ──────────────────────────────────────────────── -->
	<div
		id="watermarkModal"
		class="fixed inset-0 bg-black bg-opacity-50 z-50"
		style="display:none; align-items:center; justify-content:center;"
		role="dialog"
		aria-modal="true"
		aria-labelledby="watermarkModalTitle"
	>
		<div class="bg-white rounded-2xl shadow-xl max-w-lg w-full mx-4">

			<div class="flex items-center justify-between p-6 border-b border-gray-200">
				<h3 id="watermarkModalTitle" class="text-xl font-semibold text-gray-900">
					<?php esc_html_e( 'Watermark settings', 'todot-photolab' ); ?>
				</h3>
				<button
					type="button"
					class="text-gray-400 hover:text-gray-600 transition"
					data-pl="closeWatermarkModal"
				>
					<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
					</svg>
				</button>
			</div>

			<div class="p-6">
				<!-- State A: watermark exists → show preview -->
				<div id="showWatermark" hidden>
					<p class="text-[13px] text-gray-500 mb-3">
						<?php esc_html_e( 'Current watermark:', 'todot-photolab' ); ?>
					</p>
					<div class="rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex items-center justify-center" style="min-height:120px;">
						<img
							id="watermarkPreview"
							alt="<?php esc_attr_e( 'Watermark preview', 'todot-photolab' ); ?>"
							style="max-width:100%; max-height:200px; background:repeating-conic-gradient(#ccc 0% 25%, #fff 0% 50%) 0 0/20px 20px;"
							class="rounded-lg"
							src=""
						>
					</div>
				</div>

				<!-- State B: no watermark → upload form -->
				<div id="uploadWatermark">
					<label for="watermarkFile" class="block text-[13px] font-medium text-gray-900 mb-2">
						<?php esc_html_e( 'Select a PNG file', 'todot-photolab' ); ?>
					</label>
					<input
						class="w-full px-3 py-2 text-[13px] border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-400"
						id="watermarkFile"
						name="watermark"
						type="file"
						accept="image/png"
					>
					<p class="text-[11px] text-gray-400 mt-1">
						<?php esc_html_e( 'PNG only. Will be composited on every uploaded photo.', 'todot-photolab' ); ?>
					</p>
				</div>

				<!-- Watermark position (always visible) -->
				<div class="mt-4">
					<p class="text-[13px] font-medium text-gray-900 mb-2">
						<?php esc_html_e( 'Position', 'todot-photolab' ); ?>
					</p>
					<div class="flex gap-4">
						<label class="flex items-center gap-2 text-[13px] text-gray-700 cursor-pointer">
							<input type="radio" name="watermarkPosition" id="watermarkPositionFullwidth" value="fullwidth" class="accent-gray-800">
							<?php esc_html_e( 'Full width', 'todot-photolab' ); ?>
						</label>
						<label class="flex items-center gap-2 text-[13px] text-gray-700 cursor-pointer">
							<input type="radio" name="watermarkPosition" id="watermarkPositionBottomRight" value="bottom_right" class="accent-gray-800" checked>
							<?php esc_html_e( 'Bottom right', 'todot-photolab' ); ?>
						</label>
					</div>
				</div>

				<div
					class="mt-3 p-2 rounded text-[13px]"
					id="uploadWatermarkAlert"
					role="alert"
					hidden
				></div>
			</div>

			<div class="flex items-center justify-end gap-2 p-6 border-t border-gray-200">
				<button
					type="button"
					class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition"
					data-pl="closeWatermarkModal"
				>
					<?php esc_html_e( 'Close', 'todot-photolab' ); ?>
				</button>
				<button
					type="button"
					id="saveWatermarkButton"
					class="px-4 py-2 text-sm font-medium text-white bg-[#0a1929] rounded-lg hover:bg-[#152535] transition"
				>
					<?php esc_html_e( 'Save', 'todot-photolab' ); ?>
				</button>
				<button
					type="button"
					id="deleteWatermarkButton"
					class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition"
					hidden
				>
					<?php esc_html_e( 'Delete', 'todot-photolab' ); ?>
				</button>
			</div>

		</div>
	</div>

</div>

<!-- ── Footer ──────────────────────────────────────────────────────── -->
<div class="max-w-7xl mx-auto mt-6 flex items-center justify-between px-2">
	<p class="text-[13px] text-gray-400">
		<?php esc_html_e( 'Made in Tenerife', 'todot-photolab' ); ?>
	</p>
	<div class="flex items-center gap-4">
		<a
			href="https://github.com/todotge/photolab-for-woocommerce"
			target="_blank"
			rel="noopener noreferrer"
			class="text-gray-400 hover:text-gray-600 transition"
			aria-label="Photolab on GitHub"
		>
			<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
				<path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
			</svg>
		</a>
		<a
			href="https://github.com/todotge/photolab-for-woocommerce/wiki"
			target="_blank"
			rel="noopener noreferrer"
			class="text-[13px] text-gray-400 hover:text-gray-600 transition"
		>
			<?php esc_html_e( 'Documentation', 'todot-photolab' ); ?>
		</a>
	</div>
</div>
