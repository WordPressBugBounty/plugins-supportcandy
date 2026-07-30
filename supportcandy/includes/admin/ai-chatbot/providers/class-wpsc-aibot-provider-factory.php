<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_AIBOT_Provider_Factory' ) ) :

	class WPSC_AIBOT_Provider_Factory {

		/**
		 * Get the AI provider instance.
		 *
		 * @param string $provider The AI provider to get.
		 *
		 * @return WPSC_AIBOT_Provider_Interface The AI provider instance.
		 * @throws Exception If the provider is invalid.
		 */
		public static function get_current_provider( $provider ) {

			$providers = apply_filters(
				'wpsc_aibot_providers',
				array(
					WPSC_PS_AIT_Provider::OPENAI        => WPSC_PS_AIBOT_OpenAI::class,
					WPSC_PS_AIT_Provider::GOOGLE_GEMINI => WPSC_PS_AIBOT_Gemini::class,
				)
			);

			if ( ! isset( $providers[ $provider ] ) ) {
				throw new Exception( 'Invalid AI provider' );
			}

			return new $providers[ $provider ]();
		}
	}
endif;
