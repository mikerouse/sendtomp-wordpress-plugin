<?php
/**
 * SendToMP_Submission — normalised submission data object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Submission {

	public string $constituent_name = '';
	public string $constituent_email = '';
	public string $constituent_postcode = '';
	public string $constituent_address = '';
	public string $message_subject = '';
	public string $message_body = '';
	public string $target_house = 'commons';
	public int $target_member_id = 0;
	public string $source_adapter = '';
	public string $source_form_id = '';
	public array $raw_data = [];
	public array $metadata = [];
	public array $resolved_member = [];

	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				// Type coercion for typed properties (prevents TypeError in PHP 8.x).
				if ( 'target_member_id' === $key ) {
					$value = (int) $value;
				}
				$this->$key = $value;
			}
		}
	}

	public function validate() {
		$errors = new WP_Error();

		if ( empty( trim( $this->constituent_name ) ) ) {
			$errors->add( 'missing_name', 'Constituent name is required.' );
		}

		if ( ! is_email( $this->constituent_email ) ) {
			$errors->add( 'invalid_email', 'A valid email address is required.' );
		}

		if ( empty( trim( $this->constituent_postcode ) ) ) {
			$errors->add( 'missing_postcode', 'Postcode is required.' );
		} elseif ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', trim( $this->constituent_postcode ) ) ) {
			$errors->add( 'invalid_postcode', 'A valid UK postcode is required.' );
		}

		if ( empty( trim( $this->message_body ) ) ) {
			$errors->add( 'missing_message', 'Message body is required.' );
		}

		if ( ! in_array( $this->target_house, [ 'commons', 'lords' ], true ) ) {
			$errors->add( 'invalid_house', 'Target house must be commons or lords.' );
		}

		if ( 'lords' === $this->target_house && $this->target_member_id <= 0 ) {
			$errors->add( 'missing_member_id', 'A target member ID is required when writing to the House of Lords.' );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	public function normalise_postcode(): string {
		$postcode = strtoupper( trim( $this->constituent_postcode ) );
		$postcode = preg_replace( '/\s+/', '', $postcode );

		if ( strlen( $postcode ) > 3 ) {
			$postcode = substr( $postcode, 0, -3 ) . ' ' . substr( $postcode, -3 );
		}

		$this->constituent_postcode = $postcode;

		return $postcode;
	}

	public function to_array(): array {
		return [
			'constituent_name'    => $this->constituent_name,
			'constituent_email'   => $this->constituent_email,
			'constituent_postcode' => $this->constituent_postcode,
			'constituent_address' => $this->constituent_address,
			'message_subject'     => $this->message_subject,
			'message_body'        => $this->message_body,
			'target_house'        => $this->target_house,
			'target_member_id'    => $this->target_member_id,
			'source_adapter'      => $this->source_adapter,
			'source_form_id'      => $this->source_form_id,
			'raw_data'            => $this->raw_data,
			'metadata'            => $this->metadata,
			'resolved_member'     => $this->resolved_member,
		];
	}

	public function get_hash(): string {
		return hash( 'sha256', $this->constituent_email . '|' . $this->constituent_postcode . '|' . $this->message_body );
	}
}
