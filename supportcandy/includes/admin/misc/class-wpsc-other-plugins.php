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
				.wpsc-other-plugins-container {
					display: flex;
					flex-direction: column;
					align-items: center;
					justify-content: center;
					gap:20px;
					padding-<?php echo is_rtl() ? 'left' : 'right'; ?>: 20px;
				}
				.wpsc-other-plugins-header-container {
					display: flex;
					flex-direction: column;
					align-items: center;
				}
				.header-title-main{
					color:#2C3E50;
					font-size: 39px;
					font-style: normal;
					font-weight: 700;
					line-height: normal;
					margin-bottom: 0;
					text-align: center;
				}
				.header-subtitle-main{
					font-size: 18px;
					font-style: normal;
					font-weight: 400;
					line-height: normal;
					text-align: center;
				}
				.wpsc-single-product{
					display: flex;
					flex-direction: column;
					align-items: flex-start;
					gap:10px;
					max-width: 1080px;
					font-size: 18px;
				}
				.wpsc-single-product img{
					width: 100%;
					height: auto;
					border-radius: 10px;
				}
				.wpsc-single-product p{
					font-size: 18px;
					margin: 0;
				}
				.wpsc-single-product a {
					text-decoration: none;
				}
				.wpsc-other-plugins-footer-container {
					display: flex;
					flex-direction: column;
					align-items: center;
				}
				.footer-title-main{
					color:#2C3E50;
					font-size: 30px;
					font-style: normal;
					font-weight: 500;
					line-height: normal;
					margin-bottom: 0;
					text-align: center;
				}
				.footer-subtitle-main{
					font-size: 18px;
					font-style: normal;
					font-weight: 400;
					line-height: normal;
					text-align: center;
				}
			</style>
			<div class="wpsc-other-plugins-container">
					<div class="wpsc-other-plugins-header-container">
						<h1 class="header-title-main">Our WooCommerce Extensions</h1>
						<p class="header-subtitle-main">Looking to start an online store? We're the team behind SupportCandy, and we've developed powerful WooCommerce extensions to help you build and grow a successful business.</p>
					</div>
					<div class="wpsc-single-product">
						<img src="<?php echo esc_url( WPSC_PLUGIN_URL . '/asset/images/multi-currency-banner.png' ); ?>" alt="">
						<p>Offer your customers a seamless multi-currency shopping experience. 
							This plugin automatically updates exchange rates, detects your customer's currency by their location, and provides robust switching options so you can sell around the world with ease.</p>
						<p><a href="https://psmplugins.com/multi-currency-for-woocommerce?utm_source=plugin&utm_medium=multi_currency&utm_campaign=supportcandy_other_plugins" target="_blank">Visit product page >>></a></p>
					</div>
					<div class="wpsc-other-plugins-footer-container">
						<h2 class="footer-title-main">More plugins are on the way !</h2>
						<p class="footer-subtitle-main">We're actively developing new extensions to help you get even more out of WooCommerce. Stay tuned!</p>
					</div>
			</div>
			<?php
		}
	}
endif;
