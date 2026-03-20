<?php
/**
 * Plugin Name: Simple Reviews
 * Description: A simple WordPress plugin that registers a custom post type for product reviews and provides REST API support.
 * Version: 1.1.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simple_Reviews {

	public function __construct() {
		add_action( 'init', [ $this, 'register_product_review_cpt' ] );

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		add_shortcode( 'product_reviews', [ $this, 'display_product_reviews' ] );

		add_action( 'save_post_product_review', [ $this, 'save_review_sentiment' ], 10, 2 );
	}

	public function register_product_review_cpt() {
		register_post_type( 'product_review', [
			'labels'       => [
				'name'          => 'Product Reviews',
				'singular_name' => 'Product Review',
			],
			'public'        => true,
			'supports'      => [ 'title', 'editor', 'custom-fields' ],
			'show_in_rest'  => true,
		] );
	}

	public function register_rest_routes() {
		register_rest_route( 'mock-api/v1', '/sentiment/', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analyze_sentiment' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'mock-api/v1', '/review-history/', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_review_history' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Analyze sentiment of a given text.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze_sentiment( $request ) {
		$params = $request->get_json_params();
		$text   = isset( $params['text'] ) ? sanitize_text_field( $params['text'] ) : '';

		if ( empty( $text ) ) {
			return new WP_Error( 'empty_text', 'No text provided for analysis.', [ 'status' => 400 ] );
		}

		$result = $this->compute_sentiment( $text );

		return rest_ensure_response( [
			'sentiment' => $result['sentiment'],
			'score'     => $result['score'],
		] );
	}

	/**
	 * Keyword-based sentiment computation.
	 * Returns an array with 'sentiment' (positive|negative|neutral) and 'score' (0.0–1.0).
	 *
	 * @param string $text
	 * @return array
	 */
	private function compute_sentiment( $text ) {
		$positive_words = [
			'great', 'excellent', 'amazing', 'good', 'love', 'fantastic',
			'wonderful', 'best', 'happy', 'perfect', 'awesome', 'superb',
			'outstanding', 'brilliant', 'impressive', 'recommend', 'satisfied',
		];

		$negative_words = [
			'bad', 'terrible', 'awful', 'hate', 'worst', 'poor', 'horrible',
			'disappointing', 'broken', 'useless', 'waste', 'refund', 'defective',
			'failed', 'ugly', 'slow', 'expensive', 'cheap', 'disgusting',
		];

		$lower      = strtolower( $text );
		$words      = preg_split( '/\W+/', $lower, -1, PREG_SPLIT_NO_EMPTY );
		$pos_count  = count( array_intersect( $words, $positive_words ) );
		$neg_count  = count( array_intersect( $words, $negative_words ) );

		if ( $pos_count > $neg_count ) {
			$score     = min( 1.0, 0.5 + ( $pos_count - $neg_count ) * 0.1 );
			$sentiment = 'positive';
		} elseif ( $neg_count > $pos_count ) {
			$score     = max( 0.0, 0.5 - ( $neg_count - $pos_count ) * 0.1 );
			$sentiment = 'negative';
		} else {
			$score     = 0.5;
			$sentiment = 'neutral';
		}

		return compact( 'sentiment', 'score' );
	}

	/**
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save_review_sentiment( $post_id, $post ) {
		// Avoid infinite loops on auto-save or revisions
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$text = $post->post_title . ' ' . wp_strip_all_tags( $post->post_content );

		if ( empty( trim( $text ) ) ) {
			return;
		}

		$result = $this->compute_sentiment( $text );

		update_post_meta( $post_id, 'sentiment', $result['sentiment'] );
		update_post_meta( $post_id, 'sentiment_score', $result['score'] );
	}

	/**
	 * REST endpoint: return last 5 reviews with their sentiment.
	 *
	 * @return WP_REST_Response
	 */
	public function get_review_history() {
		$reviews = get_posts( [
			'post_type'      => 'product_review',
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$response = [];

		foreach ( $reviews as $review ) {
			// FIX #6: Use ?: instead of ?? — get_post_meta returns "" not null
			$response[] = [
				'id'        => $review->ID,
				'title'     => $review->post_title,
				'sentiment' => get_post_meta( $review->ID, 'sentiment', true ) ?: 'neutral',
				'score'     => get_post_meta( $review->ID, 'sentiment_score', true ) ?: 0.5,
			];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Shortcode callback: renders a list of recent reviews with sentiment styling.
	 *
	 * @return string HTML output
	 */
	public function display_product_reviews() {
		$reviews = get_posts( [
			'post_type'      => 'product_review',
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$output  = '<style>';
		$output .= '.review-positive { color: white; font-weight: bold; background:green; }';
		$output .= '.review-negative { color: white; font-weight: bold; background:red;}';
		$output .= '</style>';

		$output .= '<ul>';

		foreach ( $reviews as $review ) {
			// FIX #6: Use ?: instead of ??
			$sentiment = get_post_meta( $review->ID, 'sentiment', true ) ?: 'neutral';

			$class = '';
			if ( $sentiment === 'positive' ) {
				$class = 'review-positive';
			} elseif ( $sentiment === 'negative' ) {
				$class = 'review-negative';
			}

			// FIX #2: Escape all dynamic values before outputting to HTML
			$output .= '<li class="' . esc_attr( $class ) . '">'
				. esc_html( $review->post_title )
				. ' (Sentiment: ' . esc_html( $sentiment ) . ')'
				. '</li>';
		}

		$output .= '</ul>';

		return $output;
	}
}

new Simple_Reviews();