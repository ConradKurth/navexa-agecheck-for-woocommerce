/**
 * Editor registration. The block is forced into the checkout's payment step
 * (block.json: parent woocommerce/checkout-payment-block, lock.remove), so
 * WooCommerce inserts it there; merchants cannot remove it.
 */
import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit.js';
import metadata from './block.json';

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
