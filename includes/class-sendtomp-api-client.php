<?php
/**
 * SendToMP_API_Client — HTTP client for the Supabase middleware edge functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_API_Client {

	private string $api_url;
	private string $api_key;

	public function __construct() {
		$this->api_url = untrailingslashit( (string) sendtomp()->get_setting( 'api_url' ) );
		$this->api_key = (string) sendtomp()->get_setting( 'api_key' );
	}

	public function resolve_member( string $postcode, string $house = 'commons' ) {
		return $this->request( '/resolve-member', [
			'postcode' => $postcode,
			'house'    => $house,
		] );
	}

	public function resolve_member_by_id( int $member_id, string $house = 'lords' ) {
		return $this->request( '/resolve-member', [
			'member_id' => $member_id,
			'house'     => $house,
		] );
	}

	public function search_members( string $query, string $house = 'lords', string $party = '' ) {
		$body = [
			'query' => $query,
			'house' => $house,
		];

		if ( ! empty( $party ) ) {
			$body['party'] = $party;
		}

		return $this->request( '/search-members', $body );
	}

	public function log_delivery( array $data ): void {
		$this->request( '/log-delivery', $data, false );
	}

	private function request( string $endpoint, array $body, bool $blocking = true ) {
		$url = $this->api_url . $endpoint;

		$args = [
			'method'  => 'POST',
			'timeout' => $blocking ? 15 : 1,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			],
			'body'     => wp_json_encode( $body ),
			'blocking' => $blocking,
		];

		$response = wp_remote_post( $url, $args );

		if ( ! $blocking ) {
			return [];
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = isset( $decoded['message'] ) ? $decoded['message'] : 'API request failed.';

			return new WP_Error(
				'sendtomp_api_error',
				$message,
				[ 'status' => $code ]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( null === $decoded ) {
			return new WP_Error(
				'sendtomp_invalid_response',
				'Invalid JSON response from API.'
			);
		}

		return $decoded;
	}
}
