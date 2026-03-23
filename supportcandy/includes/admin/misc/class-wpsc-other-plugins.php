<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Other_Plugins' ) ) :

	final class WPSC_Other_Plugins {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {}

		/**
		 * List of all supportcandy add-ons
		 *
		 * @return void
		 */
		public static function layout() {
			?>
			<style>
				:root {
					--primary-color: #2271b1;
					--text-main: #111827;
					--text-muted: #4b5563;
					--bg-light: #f9fafb;
				}

				body {
					background-color: var(--bg-light);
					margin: 0;
					font-family: 'Inter', -apple-system, sans-serif;
				}

				.plugin-showcase {
					max-width: 1200px;
					margin: 0 auto;
					padding: 20px 20px;
				}

				/* Header & Footer Styling */
				.showcase-header, .showcase-footer {
					text-align: center;
					margin-bottom: 50px;
				}

				.showcase-footer {
					margin-top: 50px;
					border-top: 1px solid #e5e7eb;
					padding-top: 10px;
				}

				.showcase-header h2, .showcase-footer h3 {
					font-size: 2rem;
					color: var(--text-main);
					margin-bottom: 15px;
					line-height: 2rem;
				}

				.showcase-header p, .showcase-footer p {
					color: var(--text-muted);
					max-width: 700px;
					margin: 0 auto;
					line-height: 1.6;
				}

				/* Grid Logic: Forces 3 columns */
				.grid-container {
					display: grid;
					grid-template-columns: repeat(3, 1fr);
					gap: 40px;
				}

				/* Card Styling */
				.modern-card {
					background: #ffffff;
					border-radius: 12px;
					box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
					overflow: hidden;
					display: flex;
					flex-direction: column;
					transition: transform 0.3s ease;
				}

				.image-wrapper {
					height: 180px;
					background: #eee;
				}

				.card-img {
					width: 100%;
					height: 100%;
					object-fit: cover;
				}

				.card-body {
					padding: 24px;
					display: flex;
					flex-direction: column;
					flex-grow: 1;
				}

				.card-body h3 {
					margin: 0 0 12px 0;
					line-height: 1.25rem;
				}

				.card-body p {
					font-size: 0.95rem;
					color: var(--text-muted);
					line-height: 1.5;
					margin-bottom: 20px;
					/* Keeps text between 3-5 lines */
					display: -webkit-box;
					-webkit-line-clamp: 4;
					-webkit-box-orient: vertical;
					overflow: hidden;
				}

				.card-link {
					margin-top: auto;
					background: var(--primary-color);
					color: white;
					text-decoration: none;
					text-align: center;
					padding: 12px;
					border-radius: 6px;
					font-weight: 600;
					font-size: 0.9rem;
					border: 1px solid var(--primary-color);
				}

				.card-link:hover {
					background: var(--bg-light);
				}

				/* Responsive Adjustments */
				@media (max-width: 992px) {
					.grid-container {
						grid-template-columns: repeat(2, 1fr); /* 2 per row on tablets */
					}
				}

				@media (max-width: 650px) {
					.grid-container {
						grid-template-columns: 1fr; /* 1 per row on mobile */
					}
				}
			</style>
			<section class="plugin-showcase">
				<header class="showcase-header">
					<h2>Our other plugins</h2>
					<p>From store enhancements to customer support, our plugins are built to help you sell better, communicate faster, and manage your business more efficiently.</p>
				</header>

				<div class="grid-container">
					<div class="modern-card">
						<div class="image-wrapper">
							<img src="<?php echo esc_url( WPSC_PLUGIN_URL . 'asset/images/multi-currency-banner.webp' ); ?>" alt="Multi-Currency Plugin" class="card-img">
						</div>
						<div class="card-body">
							<h3>Multi Currency Switcher for WooCommerce</h3>
							<p>Offer your customers a seamless multi-currency shopping experience. This plugin automatically updates exchange rates and detects customer location for easy global selling.</p>
							<a href="https://psmplugins.com/multi-currency-for-woocommerce" target="__blank" class="card-link">View Plugin</a>
						</div>
					</div>

					<div class="modern-card">
						<div class="image-wrapper">
							<img src="<?php echo esc_url( WPSC_PLUGIN_URL . 'asset/images/request-a-quote-banner.webp' ); ?>" alt="Request a Quote Plugin" class="card-img">
						</div>
						<div class="card-body">
							<h3>Request a Quote for WooCommerce</h3>
							<p>Turn your store into a negotiation hub. Allow customers to build custom inquiry lists for bulk orders and convert quotes to orders with a single click.</p>
							<a href="https://psmplugins.com/request-a-quote-for-woocommerce/" target="__blank" class="card-link">View Plugin</a>
						</div>
					</div>

					<div class="modern-card">
						<div class="image-wrapper">
							<img src="<?php echo esc_url( WPSC_PLUGIN_URL . 'asset/images/supportcandy-banner.webp' ); ?>" alt="SupportCandy Plugin" class="card-img">
						</div>
						<div class="card-body">
							<h3>SupportCandy – Helpdesk & Customer Support Ticket System</h3>
							<p>Streamline your customer service with a professional helpdesk. Organize, track, and resolve tickets efficiently directly from your website dashboard.</p>
							<a href="https://psmplugins.com/supportcandy/" target="__blank" class="card-link">View Plugin</a>
						</div>
					</div>
				</div>
			</section>
			<?php
		}
	}
endif;
