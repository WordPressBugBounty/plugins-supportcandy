<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_AD_Controller' ) ) :

	final class WPSC_PS_AI_AD_Controller {

		/**
		 * Build AI query context from ticket data, including customer name, last reply, and ticket history,
		 * while removing date strings and HTML for cleaner input to the AI model.
		 *
		 * @param array       $ai_settings - The AI settings array, which may influence context construction.
		 * @param WPSC_Ticket $ticket - The ticket object.
		 * @return string - The constructed AI query.
		 */
		public static function wpsc_build_ticket_context_for_ai_training( $ai_settings, $ticket ) {

			$ticket_data = self::wpsc_extract_relevant_ticket_data_for_rag( $ticket );
			$ticket_data = WPSC_PS_AI_Functions::wpsc_mask_sensitive_content( $ticket_data );

			$base_prompt = sprintf(
				'TASK:
				- The ticket conversation below is ordered ASC (earliest first).
				- Identify ALL user questions or intents from the conversation.
				- If a question is already answered later in the conversation, ignore it.
				- Extract ONLY unanswered questions.
				- Rewrite each question clearly if needed.

				IMPORTANT:
				- Do NOT merge questions.
				- Treat each question independently.
				- You MUST identify and process ALL questions.
				- DO NOT output the questions themselves - only the answer.

				OUTPUT FORMAT:
				- If only one question exists → answer it directly.
				- If multiple questions exist → answer each separately in numbered format.

				Ticket Data:
				"""
				%s
				"""',
				$ticket_data
			);

			$custom_prompt = isset( $ai_settings['auto-draft-custom-prompt'] ) ? trim( $ai_settings['auto-draft-custom-prompt'] ) : '';
			if ( ! empty( $custom_prompt ) ) {
				$base_prompt .= "\n\nAdditional instructions from user:\n" . $custom_prompt;
			}
			return $base_prompt;
		}

		/**
		 * Extract relevant data from the ticket for RAG processing, including customer name, last reply, and cleaned ticket history.
		 *
		 * @param WPSC_Ticket $ticket - The ticket object.
		 * @return string - A formatted string of threads with 'type', 'author', 'body', and 'date'.
		 */
		public static function wpsc_extract_relevant_ticket_data_for_rag( $ticket ) {

			$filters = array(
				'items_per_page' => 0,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'slug'    => 'ticket',
						'compare' => '=',
						'val'     => $ticket->id,
					),
					array(
						'slug'    => 'type',
						'compare' => 'IN',
						'val'     => array( 'report', 'reply' ),
					),
					array(
						'slug'    => 'is_active',
						'compare' => '=',
						'val'     => 1,
					),
				),
				'orderby'        => 'id',
				'order'          => 'ASC',
			);

			$threads = WPSC_Thread::find( $filters );
			if ( ! $threads['total_items'] ) {
				return '';
			}

			$data = '';
			foreach ( $threads['results'] as $thread ) {
				$thread_user = get_user_by( 'email', $thread->customer->email );
				$role = $thread_user && $thread_user->has_cap( 'wpsc_agent' ) ? 'Agent' : 'Customer';
				$data .= sprintf(
					"%s's %s: %s\n",
					$role,
					$thread->type,
					trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $thread->body ) ) )
				);
			}
			return $data;
		}
	}
endif;
