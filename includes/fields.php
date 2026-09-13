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
	$seen   = array();
	foreach ( explode( ',', (string) $spec ) as $chunk ) {
		$parts = array_map( 'trim', explode( ':', trim( $chunk ) ) );
		if ( empty( $parts[0] ) ) {
			continue;
		}
		$raw_key = $parts[0];
		// remove_accents before sanitize_key so "prénom" becomes "prenom", not "prnom".
		$key = sanitize_key( remove_accents( $raw_key ) );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			return array(); // Ambiguous definitions must not silently overwrite a value.
		}
		$seen[ $key ] = true;

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
			// Label from the RAW key (before sanitize_key) to keep accents.
			$label = ucfirst( str_replace( array( '_', '-' ), ' ', $raw_key ) );
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
		'courriel'  => 'email',
		'tel'       => 'tel',
		'phone'     => 'tel',
		'telephone' => 'tel',
		'portable'  => 'tel',
		'message'   => 'textarea',
		'rgpd'      => 'checkbox',
		'gdpr'      => 'checkbox',
		'consent'   => 'checkbox',
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : 'text';
}

/**
 * Maximum length per field type. The `data` column is TEXT (64 KB): without
 * caps, a huge payload would be silently truncated by MySQL and stored as
 * invalid JSON. Caps keep every stored lead intact and readable.
 */
function petit_form_max_length_for( $type ) {
	$map = array(
		'text'     => 255,
		'name'     => 255,
		'email'    => 255,
		'tel'      => 32,
		'textarea' => 10000,
		'checkbox' => 1,
	);
	return isset( $map[ $type ] ) ? $map[ $type ] : 255;
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
		case 'tel':
			// Validate the original address/number; never manufacture another one.
			return trim( $value );
		case 'checkbox':
			return $value ? '1' : '';
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
	if ( $field['required'] && '' === $value ) {
		return new WP_Error( 'PF-E1101', sprintf( 'Required field "%s" is empty.', $field['key'] ) );
	}
	if ( '' === $value ) {
		return true; // optional and empty: fine
	}
	$max = petit_form_max_length_for( $field['type'] );
	if ( mb_strlen( $value ) > $max ) {
		return new WP_Error( 'PF-E1104', sprintf( 'Field "%s" exceeds %d chars.', $field['key'], $max ) );
	}
	switch ( $field['type'] ) {
		case 'email':
			if ( ! is_email( $value ) ) {
				return new WP_Error( 'PF-E1102', sprintf( 'Invalid email in field "%s".', $field['key'] ) );
			}
			break;
		case 'tel':
			$digits = strlen( preg_replace( '/[^0-9]/', '', $value ) );
			if ( ! preg_match( '/^\+?[0-9(). \-]+$/D', $value ) || $digits < 6 || $digits > 15 ) {
				return new WP_Error( 'PF-E1103', sprintf( 'Invalid phone in field "%s".', $field['key'] ) );
			}
			break;
	}
	return true;
}
