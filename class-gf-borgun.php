<?php

add_action( 'wp', array( 'GFBorgun', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFBorgun extends GFPaymentAddOn {

	private static $_instance = null;
	protected $_version = GF_KORTA_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformsborgun';
	protected $_path = 'gravityformsborgun/borgun.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms Borgun Add-On';
	protected $_short_title = 'Borgun';
	protected $_supports_callbacks = true;
	protected $_capabilities = array( 'gravityforms_borgun', 'gravityforms_borgun_uninstall' );

	// Members plugin integration
	protected $_capabilities_settings_page = 'gravityforms_borgun';

	// Permissions
	protected $_capabilities_form_settings = 'gravityforms_borgun';
	protected $_capabilities_uninstall = 'gravityforms_borgun_uninstall';
	protected $_enable_rg_autoupgrade = true;

	// Automatic upgrade enabled
	private $production_url = 'https://securepay.borgun.is/securepay/default.aspx';
	private $sandbox_url = 'https://test.borgun.is/SecurePay/default.aspx';

	public static function get_config_by_entry( $entry ) {

		$borgun = GFBorgun::get_instance();

		$feed = $borgun->get_payment_feed( $entry );

		if ( empty( $feed ) ) {
			return false;
		}

		return $feed['addon_slug'] == $borgun->_slug ? $feed : false;
	}

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFBorgun();
		}

		return self::$_instance;
	} /* do nothing */

	public function get_payment_feed( $entry, $form = false ) {

		$feed = parent::get_payment_feed( $entry, $form );

		if ( empty( $feed ) && ! empty( $entry['id'] ) ) {
			//looking for feed created by legacy versions
			$feed = $this->get_borgun_feed_by_entry( $entry['id'] );
		}

		$feed = apply_filters( 'gform_borgun_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form( $entry['form_id'] ) );

		return $feed;
	}

	//----- SETTINGS PAGES ----------//

	private function get_borgun_feed_by_entry( $entry_id ) {

		$feed_id = gform_get_meta( $entry_id, 'borgun_feed_id' );
		$feed    = $this->get_feed( $feed_id );

		return ! empty( $feed ) ? $feed : false;
	}

	public static function get_config( $form_id ) {

		$borgun = GFBorgun::get_instance();
		$feed   = $borgun->get_feeds( $form_id );

		//Ignore IPN messages from forms that are no longer configured with the Borgun add-on
		if ( ! $feed ) {
			return false;
		}

		return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
	}

	public function maybe_thankyou_page() {

		$instance = self::get_instance();

		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}

		if ( $str = rgget( 'gf_borgun_return' ) ) {
			$str = base64_decode( $str );

			parse_str( $str, $query );

			if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] ) {
				list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

				$form = GFAPI::get_form( $form_id );
				$lead = GFAPI::get_entry( $lead_id );

				// Get the feed
				$borgun   = GFBorgun::get_instance();
				$feed     = $borgun->get_payment_feed( $lead );
				$settings = $instance->get_plugin_settings();

				// Get the request variables
				$status               = ! empty( $query['status'] ) ? $query['status'] : $_REQUEST['status'];
				$step                 = ! empty( $query['step'] ) ? $query['step'] : $_REQUEST['step'];
				$orderid              = ! empty( $query['orderid'] ) ? $query['orderid'] : $_REQUEST['orderid'];
				$orderhash            = ! empty( $query['orderhash'] ) ? $query['orderhash'] : $_REQUEST['orderhash'];
				$gf_borgun_secret_key = ! empty( $settings['gf_borgun_secret_key'] ) ? $settings['gf_borgun_secret_key'] : '';


				if ( $status == 'OK' ) {

					$hash                     = $instance->check_entry_hash( $lead, $query['amount'], $gf_borgun_secret_key );
					$payment_date             = gmdate( 'y-m-d H:i:s' );
					$lead['payment_date']     = $payment_date;
					$action['id']             = $query['hash'];
					$action['transaction_id'] = $orderid;
					$action['amount']         = $query['amount'];
					$action['entry_id']       = $lead['id'];

					if ( $hash == $orderhash ) {

						$action['type'] = 'complete_payment';
						$instance->complete_payment( $lead, $action );

						$url = $feed['meta']['mode'] == 'production' ? $instance->production_url : $instance->sandbox_url;
						if ( strpos( $step, 'Payment' ) !== false ) {
							$xml = '<PaymentNotification>Accepted</PaymentNotification>';
							wp_remote_post(
								$url,
								array(
									'method'      => 'POST',
									'timeout'     => 45,
									'redirection' => 5,
									'httpversion' => '1.0',
									'headers'     => array( 'Content-Type' => 'text/xml' ),
									'body'        => array( 'postdata' => $xml, 'postfield' => 'value' ),
									'sslverify'   => false
								)
							);
						}

					} else {
						$instance->fail_payment( $lead, $action );
					}

				} else {
					$action['type'] = 'cancelled';
					$instance->fail_payment( $lead, $action );
				}

				if ( ! class_exists( 'GFFormDisplay' ) ) {
					require_once( GFCommon::get_base_path() . '/form_display.php' );
				}

				$confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );

				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					header( "Location: {$confirmation['redirect']}" );
					exit;
				}

				GFFormDisplay::$submission[ $form_id ] = array(
					'is_confirmation'      => true,
					'confirmation_message' => $confirmation,
					'form'                 => $form,
					'lead'                 => $lead
				);
			}
		}
	}

	public function init_frontend() {
		parent::init_frontend();

		add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
		add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
	}

	public function plugin_settings_fields() {

		return array(
			array(
				'title'       => '',
				'description' => '',
				'fields'      => array(
					array(
						'name'  => 'gf_borgun_merchant_id',
						'label' => esc_html__( 'Merchant ID', 'gravityformsborgun' ),
						'type'  => 'text',
					),
					array(
						'name'  => 'gf_borgun_payment_gateway_id',
						'label' => esc_html__( 'Payment Gateway ID', 'gravityformsborgun' ),
						'type'  => 'text',
					),
					array(
						'name'  => 'gf_borgun_secret_key',
						'label' => esc_html__( 'Secret Key', 'gravityformsborgun' ),
						'type'  => 'text',
					),
					array(
						'name'  => 'gf_borgun_notification_email',
						'label' => esc_html__( 'Notification Email', 'gravityformsborgun' ),
						'type'  => 'text',
					),
					array(
						'name'    => 'gf_borgun_language',
						'label'   => esc_html__( 'Borgun Language', 'gravityformsborgun' ),
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => esc_html__( 'English', 'gravityformsborgun' ),
								'value' => 'en',
							),
							array(
								'label' => esc_html__( 'Icelandic', 'gravityformsborgun' ),
								'value' => 'is',
							),
							array(
								'label' => esc_html__( 'German', 'gravityformsborgun' ),
								'value' => 'de',
							),
							array(
								'label' => esc_html__( 'French', 'gravityformsborgun' ),
								'value' => 'fr',
							),
							array(
								'label' => esc_html__( 'Italian', 'gravityformsborgun' ),
								'value' => 'it',
							),
							array(
								'label' => esc_html__( 'Portugese', 'gravityformsborgun' ),
								'value' => 'pt',
							),
							array(
								'label' => esc_html__( 'Russian', 'gravityformsborgun' ),
								'value' => 'ru',
							),
							array(
								'label' => esc_html__( 'Spanish', 'gravityformsborgun' ),
								'value' => 'es',
							),
							array(
								'label' => esc_html__( 'Swedish', 'gravityformsborgun' ),
								'value' => 'se',
							),
							array(
								'label' => esc_html__( 'Hungarian', 'gravityformsborgun' ),
								'value' => 'hu',
							),
							array(
								'label' => esc_html__( 'Slovene', 'gravityformsborgun' ),
								'value' => 'si',
							),
						)
					),
					array(
						'type'     => 'save',
						'messages' => array(
							'success' => esc_html__( 'Settings have been updated.', 'gravityformsborgun' )
						),
					),
				),
			),
		);
	}

	public function feed_list_no_item_message() {
		$settings = $this->get_plugin_settings();
		if ( ! rgar( $settings, 'gf_borgun_configured' ) ) {
			return sprintf( esc_html__( 'To get started, please configure your %sBorgun Settings%s!', 'gravityformsborgun' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
		} else {
			return parent::feed_list_no_item_message();
		}
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		//--add Borgun Email Address field
		$fields = array(
			array(
				'name'    => 'mode',
				'label'   => esc_html__( 'Mode', 'gravityformsborgun' ),
				'type'    => 'radio',
				'choices' => array(
					array(
						'id'    => 'gf_borgun_mode_production',
						'label' => esc_html__( 'Production', 'gravityformsborgun' ),
						'value' => 'production'
					),
					array(
						'id'    => 'gf_borgun_mode_test',
						'label' => esc_html__( 'Test', 'gravityformsborgun' ),
						'value' => 'test'
					),

				),

				'horizontal'    => true,
				'default_value' => 'production',
				'tooltip'       => '<h6>' . esc_html__( 'Mode', 'gravityformsborgun' ) . '</h6>' . esc_html__( 'Select Production to receive live payments. Select Test for testing purposes when using the Borgun development sandbox.', 'gravityformsborgun' )
			),
		);

		$default_settings = parent::add_field_after( 'feedName', $fields, $default_settings );

		//--add Page Style, Continue Button Label, Cancel URL
		$fields = array();

		if ( $this->get_setting( 'delayNotification' ) || ! $this->is_gravityforms_supported( '1.9.12' ) ) {
			$fields[] = array(
				'name'    => 'notifications',
				'label'   => esc_html__( 'Notifications', 'gravityformsborgun' ),
				'type'    => 'notifications',
				'tooltip' => '<h6>' . esc_html__( 'Notifications', 'gravityformsborgun' ) . '</h6>' . esc_html__( "Enable this option if you would like to only send out this form's notifications for the 'Form is submitted' event after payment has been received. Leaving this option disabled will send these notifications immediately after the form is submitted. Notifications which are configured for other events will not be affected by this option.", 'gravityformsborgun' )
			);
		}

		//Add post fields if form has a post
		$form = $this->get_current_form();
		if ( GFCommon::has_post_field( $form['fields'] ) ) {
			$post_settings = array(
				'name'    => 'post_checkboxes',
				'label'   => esc_html__( 'Posts', 'gravityformsborgun' ),
				'type'    => 'checkbox',
				'tooltip' => '<h6>' . esc_html__( 'Posts', 'gravityformsborgun' ) . '</h6>' . esc_html__( 'Enable this option if you would like to only create the post after payment has been received.', 'gravityformsborgun' ),
				'choices' => array(
					array(
						'label' => esc_html__( 'Create post only when payment is received.', 'gravityformsborgun' ),
						'name'  => 'delayPost'
					),
				),
			);

			if ( $this->get_setting( 'transactionType' ) == 'subscription' ) {
				$post_settings['choices'][] = array(
					'label'    => esc_html__( 'Change post status when subscription is canceled.', 'gravityformsborgun' ),
					'name'     => 'change_post_status',
					'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
				);
			}

			$fields[] = $post_settings;
		}

		//Adding custom settings for backwards compatibility with hook 'gform_borgun_add_option_group'
		$fields[] = array(
			'name'  => 'custom_options',
			'label' => '',
			'type'  => 'custom',
		);

		$default_settings = $this->add_field_after( 'billingInformation', $fields, $default_settings );
		//-----------------------------------------------------------------------------------------

		//--get billing info section and add customer first/last name
		$billing_info   = parent::get_field( 'billingInformation', $default_settings );
		$billing_fields = $billing_info['field_map'];
		$add_first_name = true;
		$add_last_name  = true;
		$add_phone      = true;
		foreach ( $billing_fields as $mapping ) {
			//add first/last name if it does not already exist in billing fields
			if ( $mapping['name'] == 'firstName' ) {
				$add_first_name = false;
			} else if ( $mapping['name'] == 'lastName' ) {
				$add_last_name = false;
			} else if ( $mapping['name'] == 'phone' ) {
				$add_phone = false;
			}
		}

		if ( $add_phone ) {
			array_unshift( $billing_info['field_map'], array(
				'name'     => 'phone',
				'label'    => esc_html__( 'Phone', 'gravityformsborgun' ),
				'required' => false
			) );
		}

		if ( $add_last_name ) {
			//add last name
			array_unshift( $billing_info['field_map'], array(
				'name'     => 'lastName',
				'label'    => esc_html__( 'Last Name', 'gravityformsborgun' ),
				'required' => false
			) );
		}
		if ( $add_first_name ) {
			array_unshift( $billing_info['field_map'], array(
				'name'     => 'firstName',
				'label'    => esc_html__( 'First Name', 'gravityformsborgun' ),
				'required' => false
			) );
		}

		$default_settings = parent::replace_field( 'billingInformation', $billing_info, $default_settings );
		//----------------------------------------------------------------------------------------------------

		//hide default display of setup fee, not used by Borgun Standard
		$default_settings = parent::remove_field( 'setupFee', $default_settings );

		//-----------------------------------------------------------------------------------------------------

		/**
		 * Filter through the feed settings fields for the Borgun feed
		 *
		 * @param array $default_settings The Default feed settings
		 * @param array $form The Form object to filter through
		 */
		return apply_filters( 'gform_borgun_feed_settings_fields', $default_settings, $form );
	}

	public function supported_billing_intervals() {

		$billing_cycles = array(
			'day'   => array( 'label' => esc_html__( 'day(s)', 'gravityformsborgun' ), 'min' => 1, 'max' => 90 ),
			'week'  => array( 'label' => esc_html__( 'week(s)', 'gravityformsborgun' ), 'min' => 1, 'max' => 52 ),
			'month' => array( 'label' => esc_html__( 'month(s)', 'gravityformsborgun' ), 'min' => 1, 'max' => 24 ),
			'year'  => array( 'label' => esc_html__( 'year(s)', 'gravityformsborgun' ), 'min' => 1, 'max' => 5 )
		);

		return $billing_cycles;
	}

	public function field_map_title() {
		return esc_html__( 'Borgun Field', 'gravityformsborgun' );
	}

	public function settings_trial_period( $field, $echo = true ) {
		//use the parent billing cycle function to make the drop down for the number and type
		$html = parent::settings_billing_cycle( $field );

		return $html;
	}

	public function set_trial_onchange( $field ) {
		//return the javascript for the onchange event
		return "
		if(jQuery(this).prop('checked')){
			jQuery('#{$field['name']}_product').show('slow');
			jQuery('#gaddon-setting-row-trialPeriod').show('slow');
			if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
				jQuery('#{$field['name']}_amount').show('slow');
			}
			else{
				jQuery('#{$field['name']}_amount').hide();
			}
		}
		else {
			jQuery('#{$field['name']}_product').hide('slow');
			jQuery('#{$field['name']}_amount').hide();
			jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
		}";
	}

	public function settings_options( $field, $echo = true ) {
		$html = $this->settings_checkbox( $field, false );

		//--------------------------------------------------------
		//For backwards compatibility.
		ob_start();
		do_action( 'gform_borgun_action_fields', $this->get_current_feed(), $this->get_current_form() );
		$html .= ob_get_clean();
		//--------------------------------------------------------

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	public function settings_custom( $field, $echo = true ) {

		ob_start();
		?>
        <div id='gf_borgun_custom_settings'>
			<?php
			do_action( 'gform_borgun_add_option_group', $this->get_current_feed(), $this->get_current_form() );
			?>
        </div>

        <script type='text/javascript'>
            jQuery(document).ready(function () {
                jQuery('#gf_borgun_custom_settings label.left_header').css('margin-left', '-200px');
            });
        </script>

		<?php

		$html = ob_get_clean();

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	//------ SENDING TO KORTA -----------//

	public function settings_notifications( $field, $echo = true ) {
		$checkboxes = array(
			'name'    => 'delay_notification',
			'type'    => 'checkboxes',
			'onclick' => 'ToggleNotifications();',
			'choices' => array(
				array(
					'label' => esc_html__( "Send notifications for the 'Form is submitted' event only when payment is received.", 'gravityformsborgun' ),
					'name'  => 'delayNotification',
				),
			)
		);

		$html = $this->settings_checkbox( $checkboxes, false );

		$html .= $this->settings_hidden( array(
			'name' => 'selectedNotifications',
			'id'   => 'selectedNotifications'
		), false );

		$form                      = $this->get_current_form();
		$has_delayed_notifications = $this->get_setting( 'delayNotification' );
		ob_start();
		?>
        <ul id="gf_borgun_notification_container"
            style="padding-left:20px; margin-top:10px; <?php echo $has_delayed_notifications ? '' : 'display:none;' ?>">
			<?php
			if ( ! empty( $form ) && is_array( $form['notifications'] ) ) {
				$selected_notifications = $this->get_setting( 'selectedNotifications' );
				if ( ! is_array( $selected_notifications ) ) {
					$selected_notifications = array();
				}

				//$selected_notifications = empty($selected_notifications) ? array() : json_decode($selected_notifications);

				$notifications = GFCommon::get_notifications( 'form_submission', $form );

				foreach ( $notifications as $notification ) {
					?>
                    <li class="gf_borgun_notification">
                        <input type="checkbox" class="notification_checkbox" value="<?php echo $notification['id'] ?>"
                               onclick="SaveNotifications();" <?php checked( true, in_array( $notification['id'], $selected_notifications ) ) ?> />
                        <label class="inline"
                               for="gf_borgun_selected_notifications"><?php echo $notification['name']; ?></label>
                    </li>
					<?php
				}
			}
			?>
        </ul>
        <script type='text/javascript'>
            function SaveNotifications() {
                var notifications = [];
                jQuery('.notification_checkbox').each(function () {
                    if (jQuery(this).is(':checked')) {
                        notifications.push(jQuery(this).val());
                    }
                });
                jQuery('#selectedNotifications').val(jQuery.toJSON(notifications));
            }

            function ToggleNotifications() {

                var container = jQuery('#gf_borgun_notification_container');
                var isChecked = jQuery('#delaynotification').is(':checked');

                if (isChecked) {
                    container.slideDown();
                    jQuery('.gf_borgun_notification input').prop('checked', true);
                }
                else {
                    container.slideUp();
                    jQuery('.gf_borgun_notification input').prop('checked', false);
                }

                SaveNotifications();
            }
        </script>
		<?php

		$html .= ob_get_clean();

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	public function checkbox_input_change_post_status( $choice, $attributes, $value, $tooltip ) {
		$markup = $this->checkbox_input( $choice, $attributes, $value, $tooltip );

		$dropdown_field = array(
			'name'     => 'update_post_action',
			'choices'  => array(
				array( 'label' => '' ),
				array( 'label' => esc_html__( 'Mark Post as Draft', 'gravityformsborgun' ), 'value' => 'draft' ),
				array( 'label' => esc_html__( 'Delete Post', 'gravityformsborgun' ), 'value' => 'delete' ),

			),
			'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
		);
		$markup         .= '&nbsp;&nbsp;' . $this->settings_select( $dropdown_field, false );

		return $markup;
	}

	/**
	 * Prevent the GFPaymentAddOn version of the options field being added to the feed settings.
	 *
	 * @return bool
	 */
	public function option_choices() {

		return false;
	}

	public function save_feed_settings( $feed_id, $form_id, $settings ) {

		//--------------------------------------------------------
		//For backwards compatibility
		$feed = $this->get_feed( $feed_id );

		//Saving new fields into old field names to maintain backwards compatibility for delayed payments
		$settings['type'] = $settings['transactionType'];

		if ( isset( $settings['recurringAmount'] ) ) {
			$settings['recurring_amount_field'] = $settings['recurringAmount'];
		}

		$feed['meta'] = $settings;
		$feed         = apply_filters( 'gform_borgun_save_config', $feed );

		//call hook to validate custom settings/meta added using gform_borgun_action_fields or gform_borgun_add_option_group action hooks
		$is_validation_error = apply_filters( 'gform_borgun_config_validation', false, $feed );
		if ( $is_validation_error ) {
			//fail save
			return false;
		}

		$settings = $feed['meta'];

		//--------------------------------------------------------

		return parent::save_feed_settings( $feed_id, $form_id, $settings );
	}

	public function redirect_url( $feed, $submission_data, $form, $entry ) {
		global $wp_version;
		//Don't process redirect url if request is a Borgun return
		if ( ! rgempty( 'gf_borgun_return', $_GET ) ) {
			return false;
		}

		//updating lead's payment_status to Processing
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );

		//Getting Url (Production or Sandbox)
		$url = $feed['meta']['mode'] == 'production' ? $this->production_url : $this->sandbox_url;

		$settings = $this->get_plugin_settings();

		$gf_borgun_merchant_id        = ! empty( $settings['gf_borgun_merchant_id'] ) ? $settings['gf_borgun_merchant_id'] : '';
		$gf_borgun_payment_gateway_id = ! empty( $settings['gf_borgun_payment_gateway_id'] ) ? $settings['gf_borgun_payment_gateway_id'] : '';
		$gf_borgun_secret_key         = ! empty( $settings['gf_borgun_secret_key'] ) ? $settings['gf_borgun_secret_key'] : '';
		$gf_borgun_language           = ! empty( $settings['gf_borgun_language'] ) ? $settings['gf_borgun_language'] : 'en';
		$gf_borgun_notification_email = ! empty( $settings['gf_borgun_notification_email'] ) ? $settings['gf_borgun_notification_email'] : get_option( 'admin_email' );

		if ( empty( $gf_borgun_merchant_id ) && empty( $gf_borgun_secret_key ) && empty( $gf_borgun_payment_gateway_id ) ) {
			return false;
		}

		$line_items     = rgar( $submission_data, 'line_items' );
		$discounts      = rgar( $submission_data, 'discounts' );
		$payment_amount = rgar( $submission_data, 'payment_amount' );
		$currency       = rgar( $entry, 'currency' );

		$return = $this->return_url( $form['id'], $entry['id'], $payment_amount );

		$hash[] = $gf_borgun_merchant_id;
		$hash[] = $return;
		$hash[] = $return;
		$hash[] = $entry['id'];
		$hash[] = number_format( $payment_amount, 2, '.', '' );

		//print_r( $hash );
		$hash[]           = $currency;
		$message          = implode( '|', $hash );
		$CheckHashMessage = utf8_encode( trim( $message ) );
		$hash             = hash_hmac( 'sha256', $CheckHashMessage, $gf_borgun_secret_key );

		//Customer fields
		$customer_fields = $this->customer_query_string( $feed, $entry );

		$borgun_args = array(
			'merchantid'       => $gf_borgun_merchant_id,
			'paymentgatewayid' => $gf_borgun_payment_gateway_id,
			'checkhash'        => $hash,
			'orderid'          => $entry['id'],
			'currency'         => $currency,
			'language'         => $gf_borgun_language,
			'SourceSystem'     => 'GF-' . $wp_version . ' -BRG-' . $entry['id'],
			'buyeremail'       => $customer_fields['email'],
			'amount'           => number_format( $payment_amount, 2, '.', '' ),
			'pagetype'         => '0',
			//If set as 1 then cardholder is required to insert email,mobile number,address.
			'skipreceiptpage'  => '1',
			'merchantemail'    => $gf_borgun_notification_email,
		);

		$item_loop = 0;
		//work on products
		if ( is_array( $line_items ) ) {
			foreach ( $line_items as $item ) {
				$product_name                                   = $item['name'];
				$quantity                                       = $item['quantity'];
				$price                                          = $item['unit_price'];
				$borgun_args[ 'itemdescription_' . $item_loop ] = html_entity_decode( $product_name, ENT_NOQUOTES, 'UTF-8' );
				$borgun_args[ 'itemcount_' . $item_loop ]       = $quantity;

				$options     = rgar( $item, 'options' );
				$is_shipping = rgar( $item, 'is_shipping' );

				if ( ! $is_shipping ) {

					if ( ! empty( $options ) && is_array( $options ) ) {
						$option_index   = 1;
						$options_string = ' (';
						foreach ( $options as $option ) {
							$field_label    = html_entity_decode( $option["field_label"], ENT_NOQUOTES, 'UTF-8' );
							$option_name    = html_entity_decode( $option["option_name"], ENT_NOQUOTES, 'UTF-8' );
							$price          += GFCommon::to_number( $option["unit_price"] );
							$amount         = GFCommon::to_number( $option['unit_price'] );
							$field_label    = str_replace( '+', ' ', $field_label );
							$option_name    = str_replace( '+', ' ', $option_name );
							$options_string .= $field_label . ':' . $option_name . '(' . $currency . ' ' . $amount . ') , ';
							$option_index ++;
						}
						$options_string                                 .= rtrim( $options_string, ', ' );
						$product_name                                   .= $options_string . ' )';
						$borgun_args[ 'itemdescription_' . $item_loop ] = html_entity_decode( $product_name, ENT_NOQUOTES, 'UTF-8' );
						$borgun_args[ 'itemcount_' . $item_loop ]       = $quantity;
						$borgun_args[ 'itemunitamount_' . $item_loop ]  = $price;
						$borgun_args[ 'itemamount_' . $item_loop ]      = $price * $quantity;
					} else {
						$borgun_args[ 'itemunitamount_' . $item_loop ] = number_format( $price, 2, '.', '' );
						$borgun_args[ 'itemamount_' . $item_loop ]     = number_format( $price * $quantity, 2, '.', '' );
					}
				}
				$item_loop ++;
			}

			//look for discounts to pass in the item_name
			if ( is_array( $discounts ) ) {
				foreach ( $discounts as $discount ) {
					$product_name                                   = $discount['name'];
					$quantity                                       = $discount['quantity'];
					$price                                          = $discount['price'];
					$borgun_args[ 'itemdescription_' . $item_loop ] = html_entity_decode( $product_name, ENT_NOQUOTES, 'UTF-8' );
					$borgun_args[ 'itemcount_' . $item_loop ]       = $quantity;
					$borgun_args[ 'itemunitamount_' . $item_loop ]  = $price;
					$borgun_args[ 'itemamount_' . $item_loop ]      = $price * $quantity;
				}
			}

		}

		$url = $url . '?' . http_build_query( $borgun_args );

		$url = $url . '&returnurlsuccess=' . $return . '&returnurlsuccessserver' . $return
		       . '&returnurlcancel=' . $return . '&returnurlerror=' . $return . '&merchantemail=' . $gf_borgun_notification_email;
		$url = gf_apply_filters( 'gform_borgun_request', $form['id'], $url, $form, $entry, $feed, $submission_data );
		$this->log_debug( __METHOD__ . "(): Sending to Borgun: {$url}" );

		return $url;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return false|string
	 */
	public function check_entry_hash( $entry, $amount, $secret_key ) {
		$hash             = array();
		$hash[]           = $entry['id'];
		$hash[]           = number_format( $amount, 2, '.', '' );
		$hash[]           = rgar( $entry, 'currency' );
		$message          = implode( '|', $hash );
		$CheckHashMessage = utf8_encode( trim( $message ) );
		$Checkhash        = hash_hmac( 'sha256', $CheckHashMessage, $secret_key );

		return $Checkhash;
	}

	public function return_url( $form_id, $lead_id, $amount ) {

		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_borgun_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( $server_port != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );
		$ids_query .= '&amount=' . $amount;

		$url = add_query_arg( 'gf_borgun_return', base64_encode( $ids_query ), $pageURL );

		$query = 'gf_borgun_return=' . base64_encode( $ids_query );

		/**
		 * Filters Borgun's return URL, which is the URL that users will be sent to after completing the payment on Borgun's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string $url The URL to be filtered.
		 * @param int $form_id The ID of the form being submitted.
		 * @param int $entry_id The ID of the entry that was just created.
		 * @param string $query The query string portion of the URL.
		 */
		return apply_filters( 'gform_borgun_return_url', $url, $form_id, $lead_id, $query );

	}

	public function customer_query_string( $feed, $entry ) {
		$fields = array();
		foreach ( $this->get_customer_fields() as $field ) {
			$field_id = $feed['meta'][ $field['meta_name'] ];
			$value    = rgar( $entry, $field_id );

			if ( $field['name'] == 'country' ) {
				$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $value ) : GFCommon::get_country_code( $value );
			} elseif ( $field['name'] == 'state' ) {
				$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_us_state_code( $value ) : GFCommon::get_us_state_code( $value );
			}

			if ( ! empty( $value ) ) {
				$fields[ $field['name'] ] = $value;
			}
		}

		return $fields;
	}

	public function get_customer_fields() {
		return array(
			array( 'name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName' ),
			array( 'name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName' ),
			array( 'name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email' ),
			array( 'name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address' ),
			array( 'name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2' ),
			array( 'name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city' ),
			array( 'name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state' ),
			array( 'name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip' ),
			array( 'name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country' ),
			array( 'name' => 'phone', 'label' => 'Phone', 'meta_name' => 'billingInformation_phone' ),
		);
	}

	//------- PROCESSING KORTA IPN (Callback) -----------//

	public function convert_interval( $interval, $to_type ) {
		//convert single character into long text for new feed settings or convert long text into single character for sending to borgun
		//$to_type: text (change character to long text), OR char (change long text to character)
		if ( empty( $interval ) ) {
			return '';
		}

		$new_interval = '';
		if ( $to_type == 'text' ) {
			//convert single char to text
			switch ( strtoupper( $interval ) ) {
				case 'D' :
					$new_interval = 'day';
					break;
				case 'W' :
					$new_interval = 'week';
					break;
				case 'M' :
					$new_interval = 'month';
					break;
				case 'Y' :
					$new_interval = 'year';
					break;
				default :
					$new_interval = $interval;
					break;
			}
		} else {
			//convert text to single char
			switch ( strtolower( $interval ) ) {
				case 'day' :
					$new_interval = 'D';
					break;
				case 'week' :
					$new_interval = 'W';
					break;
				case 'month' :
					$new_interval = 'M';
					break;
				case 'year' :
					$new_interval = 'Y';
					break;
				default :
					$new_interval = $interval;
					break;
			}
		}

		return $new_interval;
	}

	public function delay_post( $is_disabled, $form, $entry ) {

		$feed            = $this->get_payment_feed( $entry );
		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
			return $is_disabled;
		}

		return ! rgempty( 'delayPost', $feed['meta'] );
	}

	public function delay_notification( $is_disabled, $notification, $form, $entry ) {
		if ( rgar( $notification, 'event' ) != 'form_submission' ) {
			return $is_disabled;
		}

		$feed            = $this->get_payment_feed( $entry );
		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
			return $is_disabled;
		}

		$selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();

		return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
	}

	public function get_entry( $custom_field ) {

		//Getting entry associated with this IPN message (entry id is sent in the 'custom' field)
		list( $entry_id, $hash ) = explode( '|', $custom_field );
		$hash_matches = wp_hash( $entry_id ) == $hash;

		//allow the user to do some other kind of validation of the hash
		$hash_matches = apply_filters( 'gform_borgun_hash_matches', $hash_matches, $entry_id, $hash, $custom_field );

		//Validates that Entry Id wasn't tampered with
		if ( ! rgpost( 'test_ipn' ) && ! $hash_matches ) {
			$this->log_error( __METHOD__ . "(): Entry ID verification failed. Hash does not match. Custom field: {$custom_field}. Aborting." );

			return false;
		}

		$this->log_debug( __METHOD__ . "(): IPN message has a valid custom field: {$custom_field}" );

		$entry = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			$this->log_error( __METHOD__ . '(): ' . $entry->get_error_message() );

			return false;
		}

		return $entry;
	}

	public function post_callback( $callback_action, $callback_result ) {
		if ( is_wp_error( $callback_action ) || ! $callback_action ) {
			return false;
		}

		//run the necessary hooks
		$entry          = GFAPI::get_entry( $callback_action['entry_id'] );
		$feed           = $this->get_payment_feed( $entry );
		$transaction_id = rgar( $callback_action, 'transaction_id' );
		$amount         = rgar( $callback_action, 'amount' );
		$subscriber_id  = rgar( $callback_action, 'subscriber_id' );
		$pending_reason = rgpost( 'pending_reason' );
		$reason         = rgpost( 'reason_code' );
		$status         = rgpost( 'payment_status' );
		$txn_type       = rgpost( 'txn_type' );
		$parent_txn_id  = rgpost( 'parent_txn_id' );

		//run gform_borgun_fulfillment only in certain conditions
		if ( rgar( $callback_action, 'ready_to_fulfill' ) && ! rgar( $callback_action, 'abort_callback' ) ) {
			$this->fulfill_order( $entry, $transaction_id, $amount, $feed );
		} else {
			if ( rgar( $callback_action, 'abort_callback' ) ) {
				$this->log_debug( __METHOD__ . '(): Callback processing was aborted. Not fulfilling entry.' );
			} else {
				$this->log_debug( __METHOD__ . '(): Entry is already fulfilled or not ready to be fulfilled, not running gform_borgun_fulfillment hook.' );
			}
		}

		do_action( 'gform_post_payment_status', $feed, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason );
		if ( has_filter( 'gform_post_payment_status' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_post_payment_status.' );
		}

		do_action( 'gform_borgun_ipn_' . $txn_type, $entry, $feed, $status, $txn_type, $transaction_id, $parent_txn_id, $subscriber_id, $amount, $pending_reason, $reason );
		if ( has_filter( 'gform_borgun_ipn_' . $txn_type ) ) {
			$this->log_debug( __METHOD__ . "(): Executing functions hooked to gform_borgun_ipn_{$txn_type}." );
		}

		do_action( 'gform_borgun_post_ipn', $_POST, $entry, $feed, false );
		if ( has_filter( 'gform_borgun_post_ipn' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_borgun_post_ipn.' );
		}
	}

	public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null ) {

		if ( ! $feed ) {
			$feed = $this->get_payment_feed( $entry );
		}

		$form = GFFormsModel::get_form_meta( $entry['form_id'] );
		if ( rgars( $feed, 'meta/delayPost' ) ) {
			$this->log_debug( __METHOD__ . '(): Creating post.' );
			$entry['post_id'] = GFFormsModel::create_post( $form, $entry );
			$this->log_debug( __METHOD__ . '(): Post created.' );
		}

		if ( rgars( $feed, 'meta/delayNotification' ) ) {
			//sending delayed notifications
			$notifications = $this->get_notifications_to_send( $form, $feed );
			GFCommon::send_notifications( $notifications, $form, $entry, true, 'form_submission' );
		}

		do_action( 'gform_borgun_fulfillment', $entry, $feed, $transaction_id, $amount );
		if ( has_filter( 'gform_borgun_fulfillment' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_borgun_fulfillment.' );
		}

	}

	/**
	 * Retrieve the IDs of the notifications to be sent.
	 *
	 * @param array $form The form which created the entry being processed.
	 * @param array $feed The feed which processed the entry.
	 *
	 * @return array
	 */
	public function get_notifications_to_send( $form, $feed ) {
		$notifications_to_send  = array();
		$selected_notifications = rgars( $feed, 'meta/selectedNotifications' );

		if ( is_array( $selected_notifications ) ) {
			// Make sure that the notifications being sent belong to the form submission event, just in case the notification event was changed after the feed was configured.
			foreach ( $form['notifications'] as $notification ) {
				if ( rgar( $notification, 'event' ) != 'form_submission' || ! in_array( $notification['id'], $selected_notifications ) ) {
					continue;
				}

				$notifications_to_send[] = $notification['id'];
			}
		}

		return $notifications_to_send;
	}

	//------- AJAX FUNCTIONS ------------------//

	public function cancel_subscription( $entry, $feed, $note = null ) {

		parent::cancel_subscription( $entry, $feed, $note );

		$this->modify_post( rgar( $entry, 'post_id' ), rgars( $feed, 'meta/update_post_action' ) );

		return true;
	}

	//------- ADMIN FUNCTIONS/HOOKS -----------//

	public function modify_post( $post_id, $action ) {

		$result = false;

		if ( ! $post_id ) {
			return $result;
		}

		switch ( $action ) {
			case 'draft':
				$post              = get_post( $post_id );
				$post->post_status = 'draft';
				$result            = wp_update_post( $post );
				$this->log_debug( __METHOD__ . "(): Set post (#{$post_id}) status to \"draft\"." );
				break;
			case 'delete':
				$result = wp_delete_post( $post_id );
				$this->log_debug( __METHOD__ . "(): Deleted post (#{$post_id})." );
				break;
		}

		return $result;
	}

	public function is_callback_valid() {
		if ( rgget( 'page' ) != 'gf_borgun_ipn' ) {
			return false;
		}

		return true;
	}

	public function init_ajax() {

		parent::init_ajax();

		add_action( 'wp_ajax_gf_dismiss_borgun_menu', array( $this, 'ajax_dismiss_menu' ) );

	}

	public function init_admin() {

		parent::init_admin();

		//add actions to allow the payment status to be modified
		add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );
		add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );

	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}

		return array(
			'complete_payment'          => esc_html__( 'Payment Completed', 'gravityformsborgun' ),
			'refund_payment'            => esc_html__( 'Payment Refunded', 'gravityformsborgun' ),
			'fail_payment'              => esc_html__( 'Payment Failed', 'gravityformsborgun' ),
			'add_pending_payment'       => esc_html__( 'Payment Pending', 'gravityformsborgun' ),
			'void_authorization'        => esc_html__( 'Authorization Voided', 'gravityformsborgun' ),
			'create_subscription'       => esc_html__( 'Subscription Created', 'gravityformsborgun' ),
			'cancel_subscription'       => esc_html__( 'Subscription Canceled', 'gravityformsborgun' ),
			'expire_subscription'       => esc_html__( 'Subscription Expired', 'gravityformsborgun' ),
			'add_subscription_payment'  => esc_html__( 'Subscription Payment Added', 'gravityformsborgun' ),
			'fail_subscription_payment' => esc_html__( 'Subscription Payment Failed', 'gravityformsborgun' ),
		);
	}

	public function ajax_dismiss_menu() {

		$current_user = wp_get_current_user();
		update_metadata( 'user', $current_user->ID, 'dismiss_borgun_menu', '1' );
	}

	public function admin_edit_payment_status( $payment_status, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_status;
		}

		//create drop down for payment status
		$payment_string = gform_tooltip( 'borgun_edit_payment_status', '', true );
		$payment_string .= '<select id="payment_status" name="payment_status">';
		$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
		$payment_string .= '<option value="Paid">Paid</option>';
		$payment_string .= '</select>';

		return $payment_string;
	}

	/**
	 * Editing of the payment details should only be possible if the entry was processed by Borgun, if the payment status is Pending or Processing, and the transaction was not a subscription.
	 *
	 * @param array $entry The current entry
	 * @param string $action The entry detail page action, edit or update.
	 *
	 * @return bool
	 */
	public function payment_details_editing_disabled( $entry, $action = 'edit' ) {
		if ( ! $this->is_payment_gateway( $entry['id'] ) ) {
			// Entry was not processed by this add-on, don't allow editing.
			return true;
		}

		$payment_status = rgar( $entry, 'payment_status' );
		if ( $payment_status == 'Approved' || $payment_status == 'Paid' || rgar( $entry, 'transaction_type' ) == 2 ) {
			// Editing not allowed for this entries transaction type or payment status.
			return true;
		}

		if ( $action == 'edit' && rgpost( 'screen_mode' ) == 'edit' ) {
			// Editing is allowed for this entry.
			return false;
		}

		if ( $action == 'update' && rgpost( 'screen_mode' ) == 'view' && rgpost( 'action' ) == 'update' ) {
			// Updating the payment details for this entry is allowed.
			return false;
		}

		// In all other cases editing is not allowed.

		return true;
	}

	public function admin_edit_payment_date( $payment_date, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_date;
		}

		$payment_date = $entry['payment_date'];
		if ( empty( $payment_date ) ) {
			$payment_date = gmdate( 'y-m-d H:i:s' );
		}

		$input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

		return $input;
	}

	public function borgun_fulfillment( $entry, $borgun_config, $transaction_id, $amount ) {
		//no need to do anything for borgun when it runs this function, ignore
		return false;
	}

	public function update_feed_id( $old_feed_id, $new_feed_id ) {
		global $wpdb;
		$entry_meta_table = self::get_entry_meta_table_name();
		$sql              = $wpdb->prepare( "UPDATE {$entry_meta_table} SET meta_value=%s WHERE meta_key='borgun_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id );
		$wpdb->query( $sql );
	}

	public static function get_entry_meta_table_name() {
		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_meta_table_name() : GFFormsModel::get_entry_meta_table_name();
	}

	public static function get_gravityforms_db_version() {

		if ( method_exists( 'GFFormsModel', 'get_database_version' ) ) {
			$db_version = GFFormsModel::get_database_version();
		} else {
			$db_version = GFForms::$version;
		}

		return $db_version;
	}

	public function copy_transactions() {
		//copy transactions from the borgun transaction table to the add payment transaction table
		global $wpdb;
		$old_table_name = $this->get_old_transaction_table_name();
		if ( ! $this->table_exists( $old_table_name ) ) {
			return false;
		}
		$this->log_debug( __METHOD__ . '(): Copying old Borgun transactions into new table structure.' );

		$new_table_name = $this->get_new_transaction_table_name();

		$sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
					SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

		$wpdb->query( $sql );

		$this->log_debug( __METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added." );
	}

	public function get_old_transaction_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'rg_borgun_transaction';
	}

	public function get_new_transaction_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'gf_addon_payment_transaction';
	}

	public function update_payment_gateway() {
		global $wpdb;
		$entry_meta_table = self::get_entry_meta_table_name();
		$sql              = $wpdb->prepare( "UPDATE {$entry_meta_table} SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='borgun'", $this->_slug );
		$wpdb->query( $sql );
	}

	public function update_lead() {
		global $wpdb;
		$entry_table      = self::get_entry_table_name();
		$entry_meta_table = self::get_entry_meta_table_name();
		$entry_id_column  = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';
		$sql              = $wpdb->prepare(
			"UPDATE {$entry_table}
			 SET payment_status='Paid', payment_method='Borgun'
		     WHERE payment_status='Approved'
		     		AND ID IN (
					  	SELECT {$entry_id_column} FROM {$entry_meta_table} WHERE meta_key='payment_gateway' AND meta_value=%s
				   	)",
			$this->_slug );

		$wpdb->query( $sql );
	}

	public static function get_entry_table_name() {
		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_table_name() : GFFormsModel::get_entry_table_name();
	}

	public function uninstall() {
		parent::uninstall();
		delete_option( 'gform_borgun_sslverify' );
	}

	//This function kept static for backwards compatibility

	private function is_valid_initial_payment_amount( $entry_id, $amount_paid ) {

		//get amount initially sent to borgun
		$amount_sent = gform_get_meta( $entry_id, 'payment_amount' );
		if ( empty( $amount_sent ) ) {
			return true;
		}

		$epsilon    = 0.00001;
		$is_equal   = abs( floatval( $amount_paid ) - floatval( $amount_sent ) ) < $epsilon;
		$is_greater = floatval( $amount_paid ) > floatval( $amount_sent );

		//initial payment is valid if it is equal to or greater than product/subscription amount
		if ( $is_equal || $is_greater ) {
			return true;
		}

		return false;

	}

	//This function kept static for backwards compatibility
	//This needs to be here until all add-ons are on the framework, otherwise they look for this function

	private function __clone() {
	}
}