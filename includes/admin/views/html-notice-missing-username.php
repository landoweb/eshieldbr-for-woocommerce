<?php
/**
 * Aviso de WooCommerce ausente.
 *
 * @package EshieldBR_for_WooCommerce/Admin/Notices
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="error">
	<p><strong>A configuração do EshieldBR não está completa.</strong> Vá para a página de configuração do plugin para inserir seu Username e Password.</p>
	<?php $url = self_admin_url( 'admin.php?page=woocommerce-eshieldbr' ); ?>
	<p><a href="<?php echo esc_url( $url ); ?>" class="button button-primary">Configurar EshieldBR</a></p>
</div>
