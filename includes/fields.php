<?php
/**
 * Field definitions: parsing shortcode attributes, per-type sanitization
 * and validation. Pure logic lives here so tests/smoke.php can exercise it
 * without a WordPress bootstrap.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supported field types and their sanitizers.
 *
 * - text: single line, tags stripped
 * - name: single line, tags stripped
 * - email: single line, must be a valid email
 * - tel: single line, loose international phone shape
 * - textarea: multi-line, tags stripped
 * - checkbox: must be "1" when required (GDPR consent)
 */
function petit_form_field_types() {
	return array( 'text', 'name', 'email', 'tel', 'textarea', 'checkbox' );
}

/**
 * Parse the shortcode "fields" attribute into a normalized definition list.
 *
 * Syntax: fields="name:required, email:required, message:textarea:required, rgpd:checkbox:required:J'accepte..."
 * Each field: "key[:type][:required][:Label]". Type defaults: name->name,
 * email->email, message->textarea, rgpd->checkbox, anything else->text.
 *
 * @return array<int,array{key:string,type:string,required:bool,label:string}>
 */
function petit_form_parse_fields( $spec ) {
	$fields = array();
	foreach ( explode( ',', (string) $spec ) as $chunk ) {
		$parts = array_map( 'trim', explode( ':', trim( $chunk ) ) );
		if ( empty( $parts[0] ) ) {
			continue;
		}
		$key = sanitize_key( $parts[0] );

		$type     = null;
		$required = false;
		$label    = null;
		foreach ( array_slice( $parts, 1 ) as $part ) {
			if ( in_array( $part, petit_form_field_types(), true ) ) {
				$type = $part;
			} elseif ( 'required' === $part ) {
				$required = true;
			} elseif ( '' !== $part ) {
				$label = $part;
			}
		}
		if ( null === $type ) {
			$type = petit_form_default_type_for( $key );
		}
		if ( null === $label ) {
			$label = ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
		}
		$fields[] = array(
			'key'      => $key,
			'type'     => $type,
			'required' => $required,
			'label'    => $label,
		);
	}
	return $fields;
}

/**
 * Sensible default field type from its key.
 */
function petit_form_default_type_for( $key ) {
	$map = array(
		'name'      => 'name',
		'nom'       => 'name',
		'prenom'    => 'name',
		'email'     => 'email',
		'e-mail'    => 'email',
		'tel'       => 'tel',
		'phone'     => 'tel',
		'telephone' => 'tel',
		'message'   => 'textarea',
		'rgpd'      => 'checkbox',
		'gdpr'      => 'checkbox',
		'consent'   => 'checkbox',
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : 'text';
}

/**
 * Sanitize one submitted value according to its field type.
 *
 * @return string
 */
function petit_form_sanitize_value( $value, $type ) {
	$value = is_string( $value ) ? $value : '';
	switch ( $type ) {
		case 'textarea':
			return sanitize_textarea_field( $value );
		case 'email':
			return sanitize_email( $value );
		case 'checkbox':
			return $value ? '1' : '';
		case 'tel':
			// Keep digits, spaces and +().- only; header-injection safe by construction.
			return trim( preg_replace( '/[^0-9+().\-\s]/', '', $value ) );
		default:
			return sanitize_text_field( $value );
	}
}

/**
 * Validate one sanitized value. Returns true or a WP_Error with a PF-E11xx code.
 *
 * @return true|WP_Error
 */
function petit_form_validate_value( $field, $value ) {
	$label = $field['label'];
	if ( $field['required'] && '' === $value ) {
		return new WP_Error( 'PF-E1101', sprintf( 'Required field "%s" is empty.', $field['key'] ), array( 'label' => $label ) );
	}
	if ( '' === $value ) {
		return true; // optional and empty: fine
	}
	switch ( $field['type'] ) {
		case 'email':
			if ( ! is_email( $value ) ) {
				return new WP_Error( 'PF-E1102', sprintf( 'Invalid email in field "%s".', $field['key'] ), array( 'label' => $label ) );
			}
			break;
		case 'tel':
			if ( ! preg_match( '/^\+?[0-9().\-\s]{6,20}$/', $value ) ) {
				return new WP_Error( 'PF-E1103', sprintf( 'Invalid phone in field "%s".', $field['key'] ), array( 'label' => $label ) );
			}
			break;
	}
	return true;
}
