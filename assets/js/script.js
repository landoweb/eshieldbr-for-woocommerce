jQuery(document).ready(function($){
	$("#eshieldbr-woocommerce-notice").click(function(){
		return data={action:"eshieldbr_woocommerce_admin_notice",eshieldbr_woocommerce_admin_nonce:eshieldbr_woocommerce_admin.eshieldbr_woocommerce_admin_nonce
		},$.post(ajaxurl,data),event.preventDefault(),!1
	});

	$('#button-purge').on('click', function(e) {
		if (!confirm('WARNING: All data will be permanently deleted from the local storage (WordPress). It won\'t affect the data kept inside EshieldBR server. Are you sure you want to proceed with the deletion?')) {
			e.preventDefault();
		}
		else {
			$('#form-purge').submit();
		}
	});
});