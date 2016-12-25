<?php
/**
 * @var array $expiration
 */
?>
<div id="post-expiration" class="misc-pub-section">
	<span>
		<span class="wp-media-buttons-icon dashicons dashicons-clock"></span>&nbsp;
		<?php esc_html_e( 'Expires: ', 'post-expiration' ); ?><span id="post-expiration-display" data-when="<?php esc_attr_e( 'Never', 'post-expiration' ); ?>"><?php esc_html_e( $expiration[ 'expires_label' ] ); ?></span>
	</span>
	<a href="#" id="post-expiration-edit" class="post-expiration-edit hide-if-no-js">
		<span aria-hidden="true"><?php esc_html_e( 'Edit', 'post-expiration' ); ?></span>
		<span class="screen-reader-text">(<?php esc_html_e( 'Edit expiration date', 'post-expiration' ); ?>)</span>
	</a>
	<div id="post-expiration-field-group" class="hide-if-js">
		<p>
			<label for="post-expiration-date"><?php esc_html_e( 'Expire On:', 'post-expiration' ); ?></label>
			<input type="text" name="post_expiration[expiration_date]" id="post-expiration-date" value="<?php esc_attr_e( $expiration[ 'expiration_date' ] ); ?>" placeholder="<?php esc_attr_e( 'Select Date', 'post-expiration' ); ?>">
		</p>
		<p>
			<label for="post-expiration-action"><?php esc_html_e( 'On Expire:', 'post-expiration' ); ?></label>
			<select name="post_expiration[expires_action]" id="post-expiration-action">
				<option <?php selected( 'set_to_expired', $expiration[ 'expires_action' ] ); ?> value="set_to_expired"><?php esc_html_e( 'Set Status to Expired', 'post-expiration' ); ?></option>
				<option <?php selected( 'set_to_draft', $expiration[ 'expires_action' ] ); ?> value="set_to_draft"><?php esc_html_e( 'Set Status to Draft', 'post-expiration' ); ?></option>
				<option <?php selected( 'trash_post',   $expiration[ 'expires_action' ] ); ?> value="trash_post"  ><?php esc_html_e( 'Trash Post',   'post-expiration' ); ?></option>
			</select>
		</p>
		<p>
			<a href="#ok"     class="post-expiration-hide-expiration button secondary"><?php esc_html_e( 'OK', 'post-expiration' ); ?></a>
			<a href="#cancel" class="post-expiration-hide-expiration cancel"><?php esc_html_e( 'Cancel', 'post-expiration' ); ?></a>
		</p>
	</div>
</div>