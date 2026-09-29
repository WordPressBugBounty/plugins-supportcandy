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
				- The "Ticket Data" below is split into two labeled sections: BACKGROUND and
				  CUSTOMER MESSAGE(S) STILL AWAITING A REPLY. This split is already computed for
				  you from the actual conversation order - it is not something you need to infer.
				- The BACKGROUND section (if present) is everything up to and including the most
				  recent agent reply. It is already answered. Use it only as context
				  (product/account details, prior troubleshooting steps) - do NOT re-answer,
				  restate, or summarize anything in it.
				- The CUSTOMER MESSAGE(S) STILL AWAITING A REPLY section is the ONLY part you must
				  respond to. Identify every distinct question or request within that section.
				- If a customer question in that section was, despite the split, already fully
				  answered earlier in BACKGROUND, ignore it instead of repeating the old answer.
				- Rewrite each question clearly if needed.

				IMPORTANT:
				- Do NOT merge questions.
				- Treat each question independently.
				- You MUST identify and process ALL questions from the pending section - do not
				  drop any of them.
				- DO NOT output the questions themselves - only the answer.
				- Do NOT summarize or restate the conversation as a whole.

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

			// Capped to the most recent threads (same technique as
			// WPSC_PS_AI_Functions::wpsc_get_clean_ticket_history(): fetch DESC then
			// reverse) to bound the prompt size sent to the AI provider - an
			// unbounded fetch here risked exceeding the model's context window on
			// long-running tickets with many replies.
			$filters = array(
				'items_per_page' => 10,
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
				'order'          => 'DESC',
			);

			$threads = WPSC_Thread::find( $filters );
			if ( ! $threads['total_items'] ) {
				return '';
			}

			$results = array_reverse( $threads['results'] );

			// Resolve each thread's role/line up front, and note the position of the
			// most recent Agent turn. Everything up to and including that turn is
			// already-answered background; everything after it is what the customer
			// is still waiting on. Doing this split here - mechanically, from the
			// actual thread order - means the LLM no longer has to infer "which
			// questions are unanswered" from a flat wall of text (a task it was
			// getting wrong); it only has to answer whatever is in the pending
			// section it's handed.
			$lines = array();
			$last_agent_index = -1;
			foreach ( $results as $index => $thread ) {
				$thread_user = get_user_by( 'email', $thread->customer->email );
				$role = $thread_user && $thread_user->has_cap( 'wpsc_agent' ) ? 'Agent' : 'Customer';
				$lines[] = sprintf(
					"%s's %s: %s\n",
					$role,
					$thread->type,
					trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $thread->body ) ) )
				);
				if ( 'Agent' === $role ) {
					$last_agent_index = $index;
				}
			}

			$background = implode( '', array_slice( $lines, 0, $last_agent_index + 1 ) );
			$pending    = implode( '', array_slice( $lines, $last_agent_index + 1 ) );

			// No customer message after the last agent reply (e.g. called in a context
			// where the agent replied last) - fall back to the flat history so there is
			// still something for the prompt to work with.
			if ( '' === trim( $pending ) ) {
				return $background;
			}

			$data = '';
			if ( '' !== trim( $background ) ) {
				$data .= "=== BACKGROUND (already discussed - do NOT re-answer) ===\n" . $background . "\n";
			}
			$data .= "=== CUSTOMER MESSAGE(S) STILL AWAITING A REPLY (respond ONLY to these) ===\n" . $pending;

			return $data;
		}
	}
endif;
