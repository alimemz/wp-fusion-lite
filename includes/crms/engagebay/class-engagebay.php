<?php

class WPF_EngageBay {

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 *  string for API for TAGS
	 */

	private $tag_str              = 'panel/tags';
	private $custom_fields_str    = 'panel/customfields/list/';
	private $contact_by_email_str = 'panel/subscribers/contact-by-email/';
	private $tags_by_id_str       = 'panel/subscribers/get-tags-by-id/';
	private $add_contact_str      = 'panel/subscribers/subscriber';
	private $remove_tags_str      = 'panel/subscribers/contact/tags/delete/';
	private $add_tags_str         = 'panel/subscribers/contact/tags/add/';
	private $update_partial_str   = 'panel/subscribers/update-partial';
	private $get_contact_str      = 'panel/subscribers/';
	private $search_str           = 'search';
	private $subscriber_str       = 'subscriber';
	private $update_tags_str      = 'subscribers/email/tags/add';


	/**
	 * API url for the account
	 */

	public $api_url;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'engagebay';
		$this->name     = 'EngageBay';
		$this->supports = array( 'add_tags' );

		$this->api_url = 'https://app.engagebay.com/dev/api/';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_EngageBay_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		add_action( 'wpf_api_success', array( $this, 'api_success' ), 10, 2 );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

	}

	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( wp_fusion()->settings->get( 'site_tracking' ) == false ) {
			return;
		}

		$tracking_id = wp_fusion()->settings->get( 'site_tracking_acct' );

		if ( empty( $tracking_id ) ) {
			return;
		}

		$domain = wp_fusion()->settings->get( 'engagebay_domain' );

		if ( wpf_is_user_logged_in() ) {
			$user  = get_userdata( wpf_get_current_user_id() );
			$email = $user->user_email;
		}

		echo '<script type="text/javascript" >';
		echo 'var EhAPI = EhAPI || {}; EhAPI.after_load = function(){';
		echo 'EhAPI.set_account("' . $tracking_id . '", "' . $domain . '");';
		echo "EhAPI.execute('rules');};(function(d,s,f) {";
		echo "var sc=document.createElement(s);sc.type='text/javascript';";
		echo 'sc.async=true;sc.src=f;var m=document.getElementsByTagName(s)[0];';
		echo 'm.parentNode.insertBefore(sc,m);';
		echo "})(document, 'script', '//d2p078bqz5urf7.cloudfront.net/jsapi/ehform.js');";
		echo '</script>';
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'engagebay' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {

				$response = new WP_Error( 'error', $body->message );

			} elseif ( 403 == wp_remote_retrieve_response_code( $response ) ) {

				$response = new WP_Error( 'error', 'Invalid API key.' );

			}
		}

		return $response;

	}


	/**
	 * Sends a JSON success after Agile API actions so they show as success in the app
	 *
	 * @access public
	 * @return array
	 */

	public function api_success( $user_id, $method ) {

		wp_send_json_success();

	}

	/**
	 * Gets params for API calls
	 *
	 * NOTE: EngageBay Auth is strange, doesnt follow standard auth
	 * protocol, so it was adjusted to work properly
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $api_key = null ) {

		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'engagebay_key' );
		}

		$this->params = array(
			'timeout'    => 20,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Authorization' => $api_key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Host'          => 'app.engagebay.com',
			),
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = $this->api_url . $this->tag_str;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}

	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$request  = $this->api_url . $this->tag_str;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body as $EBTag ) {
			$tag                    = $EBTag->{'tag'};
			$available_tags[ $tag ] = $tag;
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$built_in_fields = array();

		// Load built in fields
		require dirname( __FILE__ ) . '/admin/engagebay-fields.php';

		foreach ( $engagebay_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = $this->get_custom_fields( 'CONTACT' );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}

	/**
	 * grabs a list of custom fields, returns a hash array
	 * fields[ field_name ] = <field_label>
	 * valid $ext parameters are: CONTACT, COMPANY, LIST, DEAL, TICKET
	 * NOTE: ALL custom fields in EngageBay are in a properties subarray
	 * in the API calls
	 *
	 * @access private
	 * @return array CRM Fields
	 */

	private function get_custom_fields( $ext ) {
		$custom_fields = array();

		$request  = $this->api_url . $this->custom_fields_str . $ext;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_array( $body ) ) {
			foreach ( $body as $field ) {
				$custom_fields[ $field->{'field_name'} ] = $field->{'field_label'};
			}
		}
		return $custom_fields;
	}

	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->api_url . $this->contact_by_email_str . $email_address;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) ) {
			return false;
		}

		return $body->{'id'};
	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$tags = array();

		$request  = $this->api_url . $this->tags_by_id_str . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) ) {
			return $tags;
		}

		// Check if we need to update the available tags list
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach ( $body as $tag ) {

			$tags[] = $tag->tag;

			if ( ! isset( $available_tags[ $tag->tag ] ) ) {  // set if not already set
				$available_tags[ $tag->tag ] = $tag->tag;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * NOTE: EngageBay has a bit of a discrepancy
	 * for this API call its currently non-standard
	 * and follows URL encoded format.  We hope that
	 * soon they will change it over to JSON (much easier to deal with)
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params                            = $this->params;
		$params['body']                    = 'tags=' . json_encode( $tags );
		$params['headers']['Content-Type'] = 'application/x-www-form-urlencoded';

		$request  = $this->api_url . $this->add_tags_str . $contact_id;
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Removes tags from a contact
	 *
	 * NOTE: EngageBay has a bit of a discrepancy
	 * for this API call its currently non-standard
	 * and follows URL encoded format.  We hope that
	 * soon they will change it over to JSON (much easier to deal with)
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// engageBay is funny: this API currently
		// accepts URL encoded data - but VERY Specific URL encoded
		// hopefully this will change over to JSON encoded going forward
		$data =
			'contactId=' . $contact_id . '&' .
			'tags=' . wp_json_encode( $tags );

		$params                            = $this->params;
		$params['body']                    = $data;
		$params['headers']['Content-Type'] = 'application/x-www-form-urlencoded';

		$request  = $this->api_url . $this->remove_tags_str;
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$data = $this->format_contact_api_payload( $data );

		$params         = $this->params;
		$params['body'] = json_encode( $data );

		$request  = $this->api_url . $this->add_contact_str;
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_a( $body, 'stdClass' ) ) {
			return $body->{'id'};
		} else {
			return new WP_Error( 'error', 'Unknown error creating new contact.' );
		}
	}

	/**
	 * Update contact
	 * Thanks to Jack for code from AgileCRM to format the payload fields
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data = $this->format_contact_api_payload( $data );

		// EngageBay requires CONTACT_ID as part of the payload
		$data['id'] = $contact_id;

		$params           = $this->params;
		$params['body']   = json_encode( $data );
		$params['method'] = 'PUT';

		$request  = $this->api_url . $this->update_partial_str;
		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 * lifted code from AgileCRM - written by Jack to handle
	 * properties and subtypes
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->api_url . $this->get_contact_str . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$loaded_meta = array();

		foreach ( $body_json->properties as $field_object ) {

			if ( ! empty( $field_object->subtype ) ) {

				$loaded_meta[ $field_object->name . '+' . $field_object->subtype ] = $field_object->value;

			} else {

				$fldval = '';
				if ( isset( $field_object->value ) ) {
					$fldval = $field_object->value;
				}

				$maybe_json = json_decode( $fldval );

				if ( json_last_error() === JSON_ERROR_NONE && is_object( $maybe_json ) ) {

					foreach ( (array) $maybe_json as $key => $value ) {
						$loaded_meta[ $field_object->name . '+' . $key ] = $value;
					}
				} else {

					$loaded_meta[ $field_object->name ] = $fldval;

				}
			}
		}

		// Fix email fields if no main email is set
		if ( empty( $loaded_meta['email'] ) ) {
			if ( ! empty( $loaded_meta['email+work'] ) ) {
				$loaded_meta['email'] = $loaded_meta['email+work'];
			} elseif ( ! empty( $loaded_meta['email+home'] ) ) {
				$loaded_meta['email'] = $loaded_meta['email+home'];
			}
		}

		// grab list of fields to process
		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		// Set missing fields
		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		foreach ( $loaded_meta as $name => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $name ] ) &&
				 ! isset( $crm_fields['Custom Fields'][ $name ] ) ) {
				$crm_fields['Custom Fields'][ $name ] = $name;
				wp_fusion()->settings->set( 'crm_fields', $crm_fields );
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * AS OF JULY 30 2020 - Waiting for EngageBay to FIX
	 * the search by tag.  The query returns status 200
	 * but does not yet return results.
	 *
	 * for ANY tags
	 * filter_json : {"or_rules":[{"LHS":"tags","CONDITION":"EQUALS","RHS":"tag1"}, [{"LHS":"tags","CONDITION":"EQUALS","RHS":"tag2"}]}
	 *
	 * For All, add rule condition in and_rules
	 * filter_json : {"rules":[{"LHS":"tags","CONDITION":"EQUALS","RHS":"tag1"}, [{"LHS":"tags","CONDITION":"EQUALS","RHS":"tag2"}]}
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( 1 == 0 ) {
			// USE SEARCH API - which doesnt work yet
			$request = $this->api_url . $this->search_str;

			$params = $this->params;

			$filter = array(
				'or_rules' => array(
					array(
						'LHS'       => 'tags',
						'CONDITION' => 'EQUALS',
						'RHS'       => $tag,
					),
				),
			);

			$params['body'] = array(
				'page_size'   => 10000,
				'q'           => '%',
				'filter_json' => $filter,
				'type'        => 'Subscriber',
			);
		} else {
			// try the LIST SUBSCRIBERS
			$request = $this->api_url . $this->get_contact_str;
			$params  = $this->params;

			$filter = array(
				'rules' => array(
					array(
						'LHS'       => 'tags',
						'CONDITION' => 'EQUALS',
						'RHS'       => $tag,
					),
				),
			);

			$params['body'] = array(
				'filter_json' => $filter,
			);

			$params['method'] = 'POST';
		}

		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		// for now, change all CSV tag values into array
		// so we can use array_diff during the loop (makes it easy)
		$mustHaveTags = explode( ',', $tag );

		$contact_ids = array();

		foreach ( $body_json as $i => $contact_object ) {

			// for now - iterate all engagebay contacts
			// search for the requested tag
			if ( ! empty( $contact_object->tags ) ) {
				$contactTags = $this->convertTagsToArray( $contact_object->tags );
				if ( 0 == count( array_diff( $mustHaveTags, $contactTags ) ) ) {
					$contact_ids[] = $contact_object->id;
				}
			}
		}

		return $contact_ids;

	}

	private function convertTagsToArray( $tags ) {
		if ( empty( $tags ) ) {
			return array();
		}

		$result = array();
		foreach ( $tags as $tag ) {
			$result[] = $tag->tag;  // just the tag name is all we want
		}
		return $result;
	}

	/**
	 * Formats contact data for AgileCRM preferred update / create structure
	 *
	 * @access public
	 * @return array
	 */

	public function format_contact_api_payload( $data ) {

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/engagebay-fields.php';

		$contact_data = array( 'properties' => array() );
		$address_data = array();

		foreach ( $data as $crm_key => $value ) {

			// SYSTEM FIELDS
			foreach ( $engagebay_fields as $system_field ) {

				if ( $system_field['crm_field'] == $crm_key ) {

					if ( strpos( $crm_key, '+' ) !== false ) {

						// For system fields with subtypes
						$field_components = explode( '+', $crm_key );

						if ( $field_components[0] == 'address' ) {

							$address_data[ $field_components[1] ] = $value;
							continue 2;

						} else {

							$contact_data['properties'][] = array(
								'type'    => 'SYSTEM',
								'name'    => $field_components[0],
								'subtype' => $field_components[1],
								'value'   => $value,
							);

							continue 2;

						}
					} else {

						// For standard system fields
						$contact_data['properties'][] = array(
							'type'  => 'SYSTEM',
							'name'  => $crm_key,
							'value' => $value,
						);

						continue 2;

					}
				}
			}

			// CUSTOM FIELDS
			// If field didn't match a system field
			$contact_data['properties'][] = array(
				'type'  => 'CUSTOM',
				'name'  => $crm_key,
				'value' => $value,
			);

		}

		// If we're updating address data
		if ( ! empty( $address_data ) ) {

			$contact_data['properties'][] = array(
				'type'  => 'SYSTEM',
				'name'  => 'address',
				'value' => json_encode( $address_data ),
			);

		}

		return $contact_data;

	}

}
