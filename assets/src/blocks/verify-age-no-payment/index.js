/**
 * Editor registration of the fallback Verify age block: WooCommerce hides the
 * payment step (and the main block inside it) when the cart needs no
 * payment, so this one sits in the checkout fields area and shows only then.
 */
import { registerBlockType } from '@wordpress/blocks';
import Edit from '../verify-age/edit.js';
import metadata from './block.json';

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
