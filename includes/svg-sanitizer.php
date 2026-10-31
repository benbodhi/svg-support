<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The plugin's sanitizer: the bundled enshrined/svg-sanitize library, plus
 * two adjustments for how SVG Support uses it.
 *
 * 1. Animations: an animation element can rewrite an attribute after the file
 *    is sanitized, and Safari lets even <animateTransform> retarget a link's
 *    href. The library drops an attributeName that targets href; this also
 *    drops one that targets an event handler, and any values/from/to/by that
 *    carry a script or non-image data: URL anywhere in the list, so no payload
 *    is left in the file.
 * 2. Link targets: with removeRemoteReferences(true) the library also removes
 *    an <a> element's http(s) link. A link is only followed when clicked, it is
 *    never fetched, so it is kept, as it was before the library update.
 */
class bodhi_svg_sanitizer extends \enshrined\svgSanitize\Sanitizer {

	/**
	 * Elements that can change another element's attributes over time.
	 *
	 * @var array
	 */
	protected static $animation_tags = array( 'animate', 'animatecolor', 'animatemotion', 'animatetransform', 'set' );

	/**
	 * Animation attributes that hold the value(s) written to the target.
	 *
	 * @var array
	 */
	protected static $animation_value_attributes = array( 'values', 'from', 'to', 'by' );

	/**
	 * Only allow attributes that are on the whitelist.
	 *
	 * @param \DOMElement $element
	 */
	protected function cleanAttributesOnWhitelist( \DOMElement $element ) {
		$link_targets = $this->get_link_targets( $element );

		parent::cleanAttributesOnWhitelist( $element );

		// Put back the link targets that were only removed for being remote.
		foreach ( $link_targets as $link ) {
			if ( ! $element->hasAttributeNS( $link['namespace'], 'href' ) ) {
				$element->setAttributeNS( $link['namespace'], $link['name'], $link['value'] );
			}
		}

		if ( in_array( strtolower( $element->localName ), self::$animation_tags, true ) ) {
			$this->clean_animation_attributes( $element );
		}
	}

	/**
	 * The href / xlink:href values of an <a> element that the library would
	 * only remove as remote references, so they can be restored afterwards.
	 *
	 * @param \DOMElement $element
	 * @return array
	 */
	protected function get_link_targets( \DOMElement $element ) {
		$links = array();

		if ( ! $this->removeRemoteReferences || 'a' !== strtolower( $element->localName ) ) {
			return $links;
		}

		foreach ( $element->attributes as $attribute ) {
			$namespace = $attribute->namespaceURI;

			if ( 'href' !== $attribute->localName || ( null !== $namespace && \enshrined\svgSanitize\Helper::XLINK_NAMESPACE_URI !== $namespace ) ) {
				continue;
			}

			// The same checks the library applies to every href, so a value it
			// rejects for any reason other than being remote stays removed.
			if ( ! in_array( strtolower( $attribute->nodeName ), $this->allowedAttrs, true )
				|| ! $this->isHrefSafeValue( $attribute->value )
				|| $this->hasUnsafeUrlScheme( $attribute->value )
			) {
				continue;
			}

			$links[] = array(
				'namespace' => $namespace,
				'name'      => $attribute->nodeName,
				'value'     => $attribute->value,
			);
		}

		return $links;
	}

	/**
	 * Remove the attributes that would let an animation element turn a link
	 * into script: a target attribute that is an href or an event handler, and
	 * animated values that carry a script or non-image data: URL.
	 *
	 * @param \DOMElement $element
	 */
	protected function clean_animation_attributes( \DOMElement $element ) {
		foreach ( iterator_to_array( $element->attributes, false ) as $attribute ) {
			$name = strtolower( $attribute->localName );

			if ( 'attributename' === $name ) {
				// Read the target the way a browser does: no whitespace, any case,
				// any namespace prefix (xlink:href, XLINK:HREF, x:href).
				$target = strtolower( preg_replace( '/[\x00-\x20\x7F]+/', '', $attribute->value ) );
				$colon  = strrpos( $target, ':' );
				if ( false !== $colon ) {
					$target = substr( $target, $colon + 1 );
				}
				$unsafe = false !== strpos( $target, 'href' ) || 0 === strpos( $target, 'on' );
			} elseif ( in_array( $name, self::$animation_value_attributes, true ) ) {
				// Check the whole list, not only its first entry: values="x;javascript:…".
				$value  = preg_replace( '/[\x00-\x20\x7F]+/', '', $attribute->value );
				$unsafe = (bool) preg_match( '~(?:java|vb)script:|data:(?!image/)~i', $value );
			} else {
				continue;
			}

			if ( $unsafe ) {
				$element->removeAttributeNode( $attribute );
				$this->addXmlIssue( 'Suspicious attribute \'' . $attribute->nodeName . '\'', $element->getLineNo() );
			}
		}
	}
}
