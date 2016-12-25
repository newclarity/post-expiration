jQuery(function($){
	var $edit        = $('#post-expiration-edit');
	var $hide        = $('.post-expiration-hide-expiration');
	var $fieldGroup  = $('#post-expiration-field-group');
	var $displayDate = $('#post-expiration-display');
	var $expiresDate = $('#post-expiration-date');
	var previousDate = $expiresDate.val();

	$expiresDate.datepicker({
		dateFormat: postExpiration.dateFormat
	});

	$edit.on('click',function(e){
		e.preventDefault();
		$edit.hide();
		$fieldGroup.slideDown('fast');
	});

	$hide.on('click',function(e){
		e.preventDefault();
		$edit.show();
		$fieldGroup.slideUp('fast');

		if($(this).hasClass('cancel')){
			$expiresDate.val(previousDate);
		}else{
			$displayDate.text($expiresDate.val().length?$expiresDate.val():$displayDate.data('when'));
			previousDate = $expiresDate.val();
		}
	});
});
