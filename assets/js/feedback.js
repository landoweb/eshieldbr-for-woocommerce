jQuery(document).ready(function($) {
	$('#eshieldbr-for-woocommerce-feedback-modal').dialog({
		title: 'Quick Feedback',
		dialogClass: 'wp-dialog',
		autoOpen: false,
		draggable: false,
		width: 'auto',
		modal: true,
		resizable: false,
		closeOnEscape: false,
		position: {
			my: 'center',
			at: 'center',
			of: window
		},
				
		open: function() {
			$('.ui-widget-overlay').bind('click', function() {
				$('#eshieldbr-for-woocommerce-feedback-modal').dialog('close');
			});
		},
			
		create: function() {
			$('.ui-dialog-titlebar-close').addClass('ui-button');
		},
	});

	$('.deactivate a').each(function(i, ele) {
		if ($(ele).attr('href').indexOf('eshieldbr-for-woocommerce') > -1) {
			$('#eshieldbr-for-woocommerce-feedback-modal').find('a').attr('href', $(ele).attr('href'));

			$(ele).on('click', function(e) {
				e.preventDefault();

				$('#eshieldbr-for-woocommerce-feedback-response').html('');
				$('#eshieldbr-for-woocommerce-feedback-modal').dialog('open');
			});

			$('input[name="eshieldbr-for-woocommerce-feedback"]').on('change', function(e) {
				if($(this).val() == 4) {
					$('#eshieldbr-for-woocommerce-feedback-other').show();
				} else {
					$('#eshieldbr-for-woocommerce-feedback-other').hide();
				}
			});

			$('#eshieldbr-for-woocommerce-submit-feedback-button').on('click', function(e) {
				e.preventDefault();

				$('#eshieldbr-for-woocommerce-feedback-response').html('');

				if (!$('input[name="eshieldbr-for-woocommerce-feedback"]:checked').length) {
					$('#eshieldbr-for-woocommerce-feedback-response').html('<div style="color:#cc0033;font-weight:800">Please select your feedback.</div>');
				} else {
					$(this).val('Loading...');
					$.post(ajaxurl, {
						action: 'eshieldbr_woocommerce_submit_feedback',
						feedback: $('input[name="eshieldbr-for-woocommerce-feedback"]:checked').val(),
						others: $('#eshieldbr-for-woocommerce-feedback-other').val(),
					}, function(response) {
						window.location = $(ele).attr('href');
					}).always(function() {
						window.location = $(ele).attr('href');
					});
				}
			});
		}
	});
});