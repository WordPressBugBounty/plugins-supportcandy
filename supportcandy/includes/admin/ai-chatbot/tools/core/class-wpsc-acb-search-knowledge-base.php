<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Search_Knowledge_Base' ) ) :

	final class WPSC_ACB_Search_Knowledge_Base {

		/**
		 * Initialize the tool.
		 */
		public static function init() {

			add_filter( 'wpsc_acb_tool_registry', array( __CLASS__, 'register_tool' ) );
		}

		/**
		 * Register the tool in the registry.
		 *
		 * @param array $registry Current tool registry.
		 * @return array
		 */
		public static function register_tool( $registry ) {

			$registry['search_knowledge_base'] = array(
				'name'        => 'search_knowledge_base',
				'description' => 'Search the knowledge base for information relevant to the user question. Use this tool for informational questions, troubleshooting, setup steps, policy/process questions, and product capability queries when an action tool does not fit. Provide a focused natural-language query and optional category filters. Do not use this tool for ticket-confirmation decisions or spam moderation decisions (use detect_spam for spam).',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Focused search query derived from the user request.', 'wpsc-ps' ),
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'handler'     => 'execute_tool_search_knowledge_base',
				'class'       => __CLASS__,
			);
			return $registry;
		}

		/**
		 * Execute search knowledge base tool.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_id Session ID.
		 * @return array
		 */
		public static function execute_tool_search_knowledge_base( $args, $session_id ) {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$query = isset( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
			$provider = WPSC_AIBOT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$prompt = self::build_search_prompt();
			return $provider->search_knowledge_base( $ai_settings, $prompt, $query );
		}

		/**
		 * Build a prompt for retrieval-only knowledge search.
		 *
		 * @return string
		 */
		private static function build_search_prompt() {

			$lines = array(
				'You are a support knowledge search assistant.',

				'STRICT RESPONSE RULES:',
				'- Use ONLY information from file_search results.',
				'- Only use a result if it directly addresses the specific question asked, not merely the same general product/category.',
				'- If multiple results are only loosely or generically related, prefer the single most specific one over blending them.',
				'- If no result is a direct topical match, respond EXACTLY with: [NO_KB_FOUND]',
				'- Output must be valid HTML only.',
				'- DO NOT use Markdown in any form.',
				'- DO NOT use **, __, #, backticks, or any Markdown symbols.',
				'- DO NOT format text with asterisks.',
				'- Convert any emphasis into plain text or HTML <strong> tags only if necessary.',
				'- Use only these HTML tags: <p>, <ul>, <ol>, <li>, <br>, <strong>',

				'SOURCE HANDLING RULE:',
				'- Never reveal how or where the information was retrieved.',
				'- Never include citations or references.',
				'- Never mention knowledge base, files, or search.',

				'STYLE:',
				'- Keep the response concise and practical.',
				'- Use simple and clear language.',
				'- Maintain conversational continuity.',

				'OUTPUT:',
				'- Return ONLY HTML.',
				'- Do NOT include explanations, notes, or formatting outside HTML.',
			);

			return implode( "\n", $lines );
		}
	}

endif;
WPSC_ACB_Search_Knowledge_Base::init();
