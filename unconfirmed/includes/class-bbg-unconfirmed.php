<?php

/**
 * Unconfirmed class.
 */
class BBG_Unconfirmed {
	/**
	 * The list of users created in the setup_users() method
	 */
	var $users;

	/**
	 * The requested sort order. Only needed in this format when sorting by resent counts
	 */
	var $order;

	/**
	 * An array of results created by the activate_user() and resend_email() methods
	 */
	var $results;

	/**
	 * Users to be printed in the error/updated message box
	 */
	var $results_emails;

	/**
	 * A utility value to allow multisite value to be filterable by plugin
	 */
	var $is_multisite;

	/**
	 * Base URL for this WordPress install
	 */
	var $base_url;

	/**
	 * Count of total user accounts.
	 */
	var $total_users;

	/**
	 * Array of messages.
	 */
	var $message;

	/**
	 * Constructor.
	 *
	 * This function sets up a base url to use for URL concatenation throughout the plugin.
	 *
	 * It also adds the admin menu with either the network_admin_menu or the admin_menu hook. By
	 * default, the network_admin_hook is used on multisite installations, but you can alter
	 * this behavior by filtering 'unconfirmed_admin_hook'.
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses apply_filters() Filter 'unconfirmed_admin_hook' to alter whether the admin menu
	 *    goes in the Site Admin or Network Admin
	 */
	function __construct() {
		$this->load_textdomain();

		add_filter( 'bbg_cpt_pag_add_args', array( $this, 'add_args' ) );

		add_filter( 'boones_sortable_columns_keys_to_remove', array( $this, 'sortable_keys_to_remove' ) );

		add_filter( 'map_meta_cap', array( $this, 'map_moderate_signups_cap' ), 10, 4 );

		// Multisite behavior? Configurable for plugins
		$this->is_multisite = apply_filters( 'unconfirmed_is_multisite', is_multisite() );

		/**
		 * Should the Unconfirmed panel appear in the Network admin?
		 *
		 * @since 1.3
		 *
		 * @param bool $do_network_admin
		 */
		$do_network_admin = apply_filters( 'unconfirmed_do_network_admin', $this->is_multisite );

		$this->base_url = add_query_arg( 'page', 'unconfirmed', $do_network_admin ? network_admin_url( 'users.php' ) : admin_url( 'users.php' ) );

		$admin_hook = apply_filters( 'unconfirmed_admin_hook', $do_network_admin ? 'network_admin_menu' : 'admin_menu' );

		add_action( $admin_hook, array( $this, 'add_admin_panel' ) );
	}

	/**
	 * Load textdomain.
	 *
	 * @since 1.3.2
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'unconfirmed', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Adds the admin panel and detects incoming admin actions
	 *
	 * When the admin submits an action like "activate" or "resend activation email", it will
	 * ultimately result in a redirect. In order to minimize the amount of work done in the
	 * interim page load (after the link is clicked but before the redirect happens), I check
	 * for these actions (out of $_REQUEST parameters) before the admin panel is even added to the
	 * Dashboard.
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses BBG_Unconfirmed::delete_user() to delete registrations
	 * @uses BBG_Unconfirmed::activate_user() to process manual activations
	 * @uses BBG_Unconfirmed::resend_email() to process manual activations
	 * @uses add_users_page() to add the admin panel underneath user.php
	 */
	function add_admin_panel() {
		$page = add_submenu_page( 'users.php', __( 'Unconfirmed', 'unconfirmed' ), __( 'Unconfirmed', 'unconfirmed' ), 'moderate_signups', 'unconfirmed', array( $this, 'admin_panel_main' ) );
		add_action( "admin_print_styles-$page", array( $this, 'add_admin_styles' ) );

		if ( isset( $_POST['performed_search'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['performed_search'] ) ) ) {
			return;
		}

		// Look for actions first
		$unconfirmed_action = null;
		if ( isset( $_POST['unconfirmed_action'] ) ) {
			$unconfirmed_action = sanitize_text_field( wp_unslash( $_POST['unconfirmed_action'] ) );
		} elseif ( isset( $_GET['unconfirmed_action'] ) ) {
			$unconfirmed_action = sanitize_text_field( wp_unslash( $_GET['unconfirmed_action'] ) );
		}

		if ( $unconfirmed_action ) {
			switch ( $unconfirmed_action ) {
				case 'delete':
					$this->delete_user();
					break;

				case 'activate':
					$this->activate_user();
					break;

				case 'resend':
				default:
					$this->resend_email();
					break;
			}

			$this->do_redirect();
		}

		if ( isset( $_GET['unconfirmed_complete'] ) ) {
			$this->setup_get_users();
		}
	}

	/**
	 * Enqueues the Unconfirmed stylesheet
	 *
	 * @package Unconfirmed
	 * @since 1.0.1
	 *
	 * @uses wp_enqueue_style()
	 */
	function add_admin_styles() {
		wp_enqueue_style( 'unconfirmed-css', plugins_url( 'css/style.css', __FILE__ ) );
	}

	/**
	 * Map the 'moderate_signups' cap.
	 *
	 * 'moderate_signups' is the custom capability used by Unconfirmed for management of signups.
	 * By default, we map this to 'create_users', but it is possible to override.
	 *
	 * @since 1.3
	 *
	 * @param array  $caps
	 * @param string $cap
	 * @param int    $user_id
	 * @param array  $args
	 */
	public function map_moderate_signups_cap( $caps, $cap, $user_id, $args ) {
		if ( 'moderate_signups' === $cap ) {
			$caps = array( 'create_users' );
		}

		return $caps;
	}

	/**
	 * Queries and prepares a list of unactivated registrations for use elsewhere in the plugin
	 *
	 * This function is only called when such a list is required, i.e. on the admin pane
	 * itself. See BBG_Unconfirmed::admin_panel_main().
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses apply_filters() Filter 'unconfirmed_paged_query' to alter the per-page query
	 * @uses apply_filters() Filter 'unconfirmed_total_query' to alter the total count query
	 *
	 * @param array $args See $defaults below for documentation
	 */
	function setup_users( $args ) {
		global $wpdb;

		/**
		 * Override the $defaults with the following parameters:
		 *   - 'orderby': Which column should determine the sort? Accepts:
		 *    - 'registered' (MS) / 'user_registered' (non-MS) - These are translated to
		 *      each other accordingly, depending on is_multisite(), so you don't
		 *      have to be too careful about which one you pass
		 *    - 'user_login'
		 *    - 'user_email'
		 *    - 'activation_key' (MS) / 'user_activation_key' (non-MS) - As in the case
		 *      of 'registered', this will be switched to the appropriate version
		 *      automatically
		 *   - 'order': In conjunction with 'orderby', how should users be sorted? Accepts:
		 *     'desc', 'asc'
		 *   - 'offset': Which user are we starting with? Eg for the third page of 10, use
		 *     31
		 *   - 'number': How many users to return?
		 */
		$defaults = array(
			'orderby' => 'registered',
			'order'   => 'desc',
			'offset'  => 0,
			'number'  => 10,
		);

		$r = wp_parse_args( $args, $defaults );

		$orderby = $r['orderby'];
		$order   = $r['order'];
		$offset  = $r['offset'];
		$number  = $r['number'];

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( $_REQUEST['s'] ) ) ) : '';

		// Our query will be different for multisite and for non-multisite
		if ( $this->is_multisite ) {
			$sql['select'] = "SELECT * FROM $wpdb->signups";
			$sql['where']  = 'WHERE active = 0';

			if ( ! empty( $search ) ) {
				if ( method_exists( $wpdb, 'esc_like' ) ) { // WP >= 4.0.0
					$search_text = '%' . $wpdb->esc_like( $search ) . '%';
				} else {
					$search_text = '%' . like_escape( $search ) . '%';
				}
				$sql['where'] .= $wpdb->prepare( ' AND ( user_login LIKE %s OR user_email LIKE %s )', $search_text, $search_text );
			}

			// Switch the non-MS orderby keys to their MS counterparts
			if ( 'user_registered' == $orderby ) {
				$orderby = 'registered';
			} elseif ( 'user_activation_key' == $orderby ) {
				$orderby = 'activation_key';
			}

			$sql['orderby'] = "ORDER BY $orderby";
			$sql['order']   = strtoupper( $order );
			$sql['limit']   = $wpdb->prepare( 'LIMIT %d, %d', $offset, $number );
		} else {
			// Stinky WP_User_Query doesn't allow filtering by user_status, so we must
			// query wp_users directly. I should probably send a patch upstream to WP
			$sql['select'] = "SELECT u.*, um.meta_value AS activation_key FROM $wpdb->users u INNER JOIN $wpdb->usermeta um ON ( u.ID = um.user_id )";

			// The convention of using user_status = 2 for an unactivated user comes (I
			// think) from BuddyPress. This will probably do nothing if you're not
			// running BP.
			$sql['where'] = "WHERE u.user_status = 2 AND um.meta_key = 'activation_key'";

			if ( ! empty( $search ) ) {
				if ( method_exists( $wpdb, 'esc_like' ) ) { // WP >= 4.0.0
					$search_text = '%' . $wpdb->esc_like( $search ) . '%';
				} else {
					$search_text = '%' . like_escape( $search ) . '%';
				}
				$sql['where'] .= $wpdb->prepare( ' AND ( u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s )', $search_text, $search_text, $search_text );
			}

			// Switch the MS orderby keys to their non-MS counterparts
			if ( 'registered' == $orderby ) {
				$orderby = 'user_registered';
			} elseif ( 'activation_key' == $orderby ) {
				$orderby = 'um.activation_key';
			}

			$sql['orderby'] = $wpdb->prepare( 'ORDER BY %s', $orderby );
			$sql['order']   = strtoupper( $order );
			$sql['limit']   = $wpdb->prepare( 'LIMIT %d, %d', $offset, $number );
		}

		// Get the resent counts
		$resent_counts = get_site_option( 'unconfirmed_resent_counts' );

		$paged_query = apply_filters( 'unconfirmed_paged_query', join( ' ', $sql ), $sql, $args, $r );

		$users = $wpdb->get_results( $paged_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		/*
		 * Now loop through the users and unserialize their metadata for nice display
		 * Probably only necessary with BuddyPress
		 * We'll also use this opportunity to add the resent counts to the user objects
		 */
		foreach ( (array) $users as $key => $user ) {

			$meta = ! empty( $user->meta ) ? maybe_unserialize( $user->meta ) : false;

			foreach ( (array) $meta as $mkey => $mvalue ) {
				$user->$mkey = $mvalue;
			}

			if ( $this->is_multisite ) {
				// Multisite
				$akey = $user->activation_key;
			} else {
				// Non-multisite
				$akey = $user->activation_key;

				if ( $user->user_registered ) {
					$user->registered = $user->user_registered;
				}
			}

			$akey = isset( $user->activation_key ) ? $user->activation_key : $user->user_activation_key;

			$user->resent_count = isset( $resent_counts[ $akey ] ) ? $resent_counts[ $akey ] : 0;

			$users[ $key ] = $user;
		}

		$this->users = $users;

		// Gotta run a second query to get the overall pagination data
		unset( $sql['limit'] );
		$sql['select'] = preg_replace( '/SELECT.*?FROM/', 'SELECT COUNT(*) FROM', $sql['select'] );
		$total_query   = apply_filters( 'unconfirmed_total_query', join( ' ', $sql ), $sql, $args, $r );

		$this->total_users = $wpdb->get_var( $total_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}


	function sortable_keys_to_remove( $keys ) {
		$unconfirmed_keys = array(
			'unconfirmed_complete',
			'unconfirmed_key',
			'updated_resent',
			'updated_activated',
			'error_couldntactivate',
			'error_nouser',
			'error_nokey',
		);

		$keys = array_merge( $keys, $unconfirmed_keys );

		return $keys;
	}

	/**
	 * Get userdata from an activation key, when using WP single
	 *
	 * For maximum flexibility, this method looks both in the user_activation_key column of
	 * wp_users (rarely used) and the activation_key usermeta row (used by BP).
	 *
	 * Part of the function is borrowed from BP itself.
	 *
	 * @package Unconfirmed
	 * @since 1.2
	 */
	function get_userdata_from_key( $key ) {
		global $wpdb;

		$user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE user_activation_key = %s", $key ) );
		if ( $user ) {
			$key_loc = 'users';
		} else {
			$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'activation_key' AND meta_value = %s", $key ) );
			if ( $user_id ) {
				$key_loc = 'usermeta';
				$user    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE ID = %d", (int) $user_id ) );
			}
		}

		return $user;
	}

	/**
	 * Activates a user
	 *
	 * Depending on the result, the admin will be redirected back to the main Unconfirmed panel,
	 * with additional URL params that explain success/failure.
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses wpmu_activate_signup() WP's core function for user activation on Multisite
	 */
	function activate_user() {
		global $wpdb;

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		// Did you mean to do this? HMMM???
		if ( isset( $_POST['unconfirmed_bulk'] ) ) {
			check_admin_referer( 'unconfirmed_bulk_action' );
		} else {
			check_admin_referer( 'unconfirmed_activate_user' );
		}

		// Get the activation key(s) out of the URL params
		if ( ! isset( $_REQUEST['unconfirmed_key'] ) ) {
			$this->record_status( 'error_nokey' );
			return;
		}

		$keys = wp_unslash( $_REQUEST['unconfirmed_key'] );
		if ( is_array( $keys ) ) {
			$keys = array_map( 'sanitize_text_field', $keys );
		} else {
			$keys = (array) sanitize_text_field( $keys );
		}

		foreach ( $keys as $key ) {
			if ( $this->is_multisite ) {
				$result  = wpmu_activate_signup( $key );
				$user_id = ! is_wp_error( $result ) && isset( $result['user_id'] ) ? $result['user_id'] : 0;
			} else {
				$user = $this->get_userdata_from_key( $key );

				if ( empty( $user->ID ) ) {
					$this->record_status( 'error_nouser' );
					return;
				} else {
					$user_id = $user->ID;
				}

				if ( empty( $user_id ) ) {
					return new WP_Error( 'invalid_key', __( 'Invalid activation key', 'unconfirmed' ) );
				}

				// Change the user's status so they become active
				$result = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->users SET user_status = 0 WHERE ID = %d", $user_id ) );
				if ( ! $result ) {
					return new WP_Error( 'invalid_key', __( 'Invalid activation key', 'unconfirmed' ) );
				}
			}

			if ( is_wp_error( $result ) ) {
				$this->record_status( 'error_couldntactivate', $key );
			} else {
				do_action( 'unconfirmed_user_activated', $user_id, $key );
				$this->record_status( 'updated_activated', $key );
			}
		}
	}

	/**
	 * Deletes an unactivated registration
	 *
	 * @package Unconfirmed
	 * @since 1.2
	 */
	function delete_user() {
		global $wpdb;

		if ( ! current_user_can( 'remove_users' ) ) {
			return;
		}

		// Don't go there
		if ( isset( $_REQUEST['unconfirmed_bulk'] ) ) {
			check_admin_referer( 'unconfirmed_bulk_action' );
		} else {
			check_admin_referer( 'unconfirmed_delete_user' );
		}

		// Get the activation key(s) out of the URL params
		if ( ! isset( $_REQUEST['unconfirmed_key'] ) ) {
			$this->record_status( 'error_nokey' );
			return;
		}

		$keys = wp_unslash( $_REQUEST['unconfirmed_key'] );
		if ( is_array( $keys ) ) {
			$keys = array_map( 'sanitize_text_field', $keys );
		} else {
			$keys = (array) sanitize_text_field( $keys );
		}

		foreach ( $keys as $key ) {
			if ( $this->is_multisite ) {
				// Ensure the user exists before deleting, and pass the data along
				// to a hook
				$check = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE activation_key = %s", $key ) );

				if ( ! $check ) {
					$this->record_status( 'error_nouser' );
					return;
				} else {
					do_action( 'unconfirmed_pre_user_delete', $key, $check );
				}

				$delete_sql = apply_filters( 'unconfirmed_delete_sql', $wpdb->prepare( "DELETE FROM $wpdb->signups WHERE activation_key = %s", $key ), $key, $this->is_multisite );
				$result     = $wpdb->query( $delete_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				// Ensure the user exists before deleting, and pass the data along
				// to a hook
				$check = $this->get_userdata_from_key( $key );

				if ( ! $check ) {
					$this->record_status( 'error_nouser' );
					return;
				} else {
					do_action( 'unconfirmed_pre_user_delete', $key, $check );
				}

				$user_id = isset( $check->ID ) ? $check->ID : $check->user_id;

				$result = wp_delete_user( $user_id );
			}

			if ( ! $key ) {
				$key = 0;
			}

			if ( $result ) {
				do_action( 'unconfirmed_user_deleted', $key, $check );
				$this->record_status( 'updated_deleted', $key );
			} else {
				$this->record_status( 'error_couldntdelete', $key );
			}
		}
	}

	/**
	 * Resends an activation email
	 *
	 * This sends exactly the same email the registrant originally got, using data pulled from
	 * their registration. In the future I may add a UI for customized emails.
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses wpmu_signup_blog_notification() to notify users who signed up with a blog
	 * @uses wpmu_signup_user_notification() to notify users who signed up without a blog
	 */
	function resend_email() {
		global $wpdb;

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		// Hubba hubba
		if ( isset( $_REQUEST['unconfirmed_bulk'] ) ) {
			check_admin_referer( 'unconfirmed_bulk_action' );
		} else {
			check_admin_referer( 'unconfirmed_resend_email' );
		}

		// Get the user's activation key out of the URL params
		$keys = wp_unslash( $_REQUEST['unconfirmed_key'] );
		if ( is_array( $keys ) ) {
			$keys = array_map( 'sanitize_text_field', $keys );
		} else {
			$keys = (array) sanitize_text_field( $keys );
		}

		$resent_counts = get_site_option( 'unconfirmed_resent_counts' );

		foreach ( $keys as $key ) {
			if ( $this->is_multisite ) {
				$user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->signups WHERE activation_key = %s", $key ) );
			} else {
				$user = $this->get_userdata_from_key( $key );
			}

			if ( ! $user ) {
				$this->record_status( 'error_nouser', $key );
				continue;
			}

			if ( $this->is_multisite ) {
				// We use a different email function depending on whether they registered with blog
				if ( ! empty( $user->domain ) ) {
					wpmu_signup_blog_notification( $user->domain, $user->path, $user->title, $user->user_login, $user->user_email, $user->activation_key, maybe_unserialize( $user->meta ) );
				} else {
					wpmu_signup_user_notification( $user->user_login, $user->user_email, $user->activation_key, maybe_unserialize( $user->meta ) );
				}
			} else {
				// If you're running BP on a non-multisite instance of WP, use the
				// BP function to send the email
				if ( function_exists( 'bp_core_signup_send_validation_email' ) ) {
					bp_core_signup_send_validation_email( (int) $user->ID, $user->user_email, $key );
				}
			}

			if ( isset( $resent_counts[ $key ] ) ) {
				$resent_counts[ $key ] = $resent_counts[ $key ] + 1;
			} else {
				$resent_counts[ $key ] = 1;
			}

			// I can't do a true/false check on whether the email was sent because of
			// the crappy way that WPMU and BP work together to send these messages
			// See bp_core_activation_signup_user_notification()
			$this->record_status( 'updated_resent', $key );
		}

		update_site_option( 'unconfirmed_resent_counts', $resent_counts );
	}

	/**
	 * Utility function for recording the status of a resend/activation attempt
	 *
	 * @package Unconfirmed
	 * @since 1.1
	 *
	 * @param str $status Something like 'updated_resent'
	 * @param str $key The activation key of the affected user, if available
	 */
	function record_status( $status, $key = false ) {
		$this->results[ $status ][] = $key;
	}

	/**
	 * Redirects the user after the requestion actions have been performed
	 *
	 * The function builds the redirect URL out of the $results array, so that messages can be
	 * rendered on the redirected page.
	 *
	 * @package Unconfirmed
	 * @since 1.1
	 */
	function do_redirect() {
		$query_vars = array( 'unconfirmed_complete' => '1' );

		foreach ( (array) $this->results as $status => $keys ) {
			$query_vars[ $status ] = implode( ',', $keys );
		}

		$redirect_url = add_query_arg( $query_vars, $this->base_url );

		wp_redirect( $redirect_url );
	}

	function add_args( $add_args ) {
		if ( ! empty( $_REQUEST['s'] ) ) {
			$search_text   = urlencode( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
			$add_args['s'] = $search_text;
		} else {
			$add_args['s'] = '';
		}
		return $add_args;
	}

	/**
	 * Gets user activation keys out of the URL parameters and converts them to email addresses
	 *
	 * @package Unconfirmed
	 * @since 1.1
	 */
	function setup_get_users() {
		global $wpdb;

		foreach ( $_REQUEST as $get_key => $activation_keys ) {
			$get_key = explode( '_', $get_key );

			if ( 'updated' === $get_key[0] || 'error' === $get_key[0] ) {
				$activation_keys = array_map( 'sanitize_text_field', explode( ',', $activation_keys ) );

				if ( $this->is_multisite ) {
					foreach ( (array) $activation_keys as $ak_index => $activation_key ) {
						$activation_keys[ $ak_index ] = '"' . $wpdb->prepare( $activation_key ) . '"';
					}
					$activation_keys = implode( ',', $activation_keys );

					$registrations = $wpdb->get_results( "SELECT user_email, activation_key FROM $wpdb->signups WHERE activation_key IN ({$activation_keys})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				} else {
					$registrations = array();
					foreach ( (array) $activation_keys as $akey ) {
						$user = $this->get_userdata_from_key( $akey );

						$registration                 = new stdClass();
						$registration->user_email     = isset( $user->user_email ) ? $user->user_email : '';
						$registration->activation_key = isset( $user->user_activation_key ) ? $user->user_activation_key : ''; // todo: usermeta compat

						$registrations[] = $registration;
					}
				}

				$updated_or_error = $get_key[0];
				$message_type     = $get_key[1];

				$this->results_emails[ $updated_or_error ][ $message_type ] = $registrations;
			}
		}
	}

	/**
	 * Loops through the results_emails to create success/failure messages
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 */
	function setup_messages() {
		global $wpdb;

		if ( ! empty( $this->results_emails ) ) {

			// Cycle through the successful actions first
			if ( ! empty( $this->results_emails['updated'] ) ) {
				foreach ( $this->results_emails['updated'] as $message_type => $registrations ) {
					if ( ! empty( $registrations ) ) {
						$emails = array();

						foreach ( $registrations as $registration ) {
							$emails[] = $registration->user_email;
						}

						$emails = implode( ', ', $emails );

					}

					$message = '';

					switch ( $message_type ) {
						case 'activated':
							/* translators: list of email addresses */
							$message = sprintf( __( 'You successfully activated the following users: %s', 'unconfirmed' ), $emails );
							break;

						case 'resent':
							/* translators: list of email addresses */
							$message = sprintf( __( 'You successfully resent activation emails to the following users: %s', 'unconfirmed' ), $emails );
							break;

						case 'deleted':
							if ( count( $registrations ) > 1 ) {
								$message = __( 'Registrations successfully deleted.', 'unconfirmed' );
							} else {
								$message = __( 'Registration successfully deleted.', 'unconfirmed' );
							}
							break;

						default:
							break;
					}
				}

				$this->message['updated'] = $message;
			}

			// Now cycle through the failures
			if ( ! empty( $this->results_emails['error'] ) ) {
				foreach ( $this->results_emails['error'] as $message_type => $registrations ) {
					if ( ! empty( $registrations ) ) {
						$emails = array();

						foreach ( $registrations as $registration ) {
							$emails[] = $registration->user_email;
						}

						$emails = implode( ', ', $emails );
					}

					switch ( $message_type ) {
						case 'nokey':
							$message = __( 'You didn\'t provide an activation key.', 'unconfirmed' );
							break;

						case 'couldntactivate':
							/* translators: list of email addresses */
							$message = sprintf( __( 'The following users could not be activated: %s', 'unconfirmed' ), $emails );
							break;

						case 'nouser':
							$message = __( 'You provided invalid activation keys.', 'unconfirmed' );
							break;

						case 'unsent':
							/* translators: list of email addresses */
							$message = sprintf( __( 'Activations emails could not be resent to the following email addresses: %s', 'unconfirmed' ), $emails );
							break;

						default:
							break;
					}
				}

				$this->message['error'] = $message;
			}
		}
	}

	/**
	 * Echoes the error message to the screen.
	 *
	 * Uses the standard WP admin nag markup.
	 *
	 * Not sure why I put this in a separate method. I guess, so you can override it easily?
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 */
	function render_messages() {
		$this->setup_messages();

		if ( ! empty( $this->message ) ) {
			?>

			<?php foreach ( (array) $this->message as $message_type => $text ) : ?>
			<div id="message" class="<?php echo esc_attr( $message_type ); ?>">
				<p><?php echo esc_html( $text ); ?></p>
			</div>
		<?php endforeach ?>

			<?php
		}
	}

	/**
	 * Renders the main Unconfirmed Dashboard panel
	 *
	 * @package Unconfirmed
	 * @since 1.0
	 *
	 * @uses BBG_CPT_Pag aka Boone's Pagination
	 * @uses BBG_CPT_Sort aka Boone's Sortable Columns
	 * @uses BBG_Unconfirmed::setup_users() to get a list of unactivated users
	 */
	function admin_panel_main() {

		if ( ! class_exists( 'BBG_CPT_Pag' ) ) {
			require_once UNCONFIRMED_PLUGIN_DIR . '/lib/boones-pagination.php';
		}
		$pagination = new BBG_CPT_Pag();

		// Load the sortable helper
		if ( ! class_exists( 'BBG_CPT_Sort' ) ) {
			require_once UNCONFIRMED_PLUGIN_DIR . '/lib/boones-sortable-columns.php';
		}

		$cols = array(
			array(
				'name'      => 'user_login',
				'title'     => __( 'User Login', 'unconfirmed' ),
				'css_class' => 'login',
			),
			array(
				'name'      => 'user_email',
				'title'     => __( 'Email Address', 'unconfirmed' ),
				'css_class' => 'email',
			),
			array(
				'name'          => 'registered',
				'title'         => 'Registered',
				'css_class'     => 'registered',
				'default_order' => 'desc',
				'is_default'    => true,
			),
			array(
				'name'      => 'activation_key',
				'title'     => __( 'Activation Key', 'unconfirmed' ),
				'css_class' => 'activation-key',
			),
			array(
				'name'          => 'resent_count',
				'title'         => __( '# of Times Resent', 'unconfirmed' ),
				'css_class'     => 'resent-count',
				'default_order' => 'desc',
				'is_sortable'   => false,
			),
		);

		// On non-multisite installations, we have the display name available. Show it.
		if ( ! $this->is_multisite ) {
			$non_ms_cols = array(
				array(
					'name'  => 'display_name',
					'title' => __( 'Display Name', 'unconfirmed' ),
				),
			);

			// Can't get array_splice to work right for this multi-d array, so I'm
			// hacking around it
			$col0      = array( $cols[0] );
			$cols_rest = array_slice( $cols, 1 );
			$cols      = array_merge( $col0, $non_ms_cols, $cols_rest );
		}

		$sortable = new BBG_CPT_Sort( $cols );

		$offset = $pagination->get_per_page * ( $pagination->get_paged - 1 );

		$args = array(
			'orderby' => $sortable->get_orderby,
			'order'   => $sortable->get_order,
			'number'  => $pagination->get_per_page,
			'offset'  => $offset,
		);

		$this->setup_users( $args );

		// Setting this up a certain way to make pagination/sorting easier
		$query        = new stdClass();
		$query->users = $this->users;

		// In order for Boone's Pagination to work, this stuff must be set manually
		$query->found_posts   = $this->total_users;
		$query->max_num_pages = ceil( $query->found_posts / $pagination->get_per_page );

		// Complete the pagination setup
		$pagination->setup_query( $query );

		$search_value = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		?>
		<div class="wrap">

		<h2><?php esc_html_e( 'Unconfirmed', 'unconfirmed' ); ?></h2>

		<?php $this->render_messages(); ?>

		<form action="<?php echo esc_attr( $this->base_url ); ?>" method="post">

		<p class="search-box">
			<label class="screen-reader-text" for="unconfirmed-search-input"><?php esc_html_e( 'Search:', 'unconfirmed' ); ?></label>
			<input type="search" id="unconfirmed-search-input" name="s" value="<?php echo esc_attr( $search_value ); ?>">
			<input type="hidden" id="unconfirmed-performed-search-input" name="performed_search" value="0">
			<input type="submit" name="search_submit" id="search-submit" class="button" value="Search" onclick="document.getElementById('unconfirmed-performed-search-input').value = '1';">
		</p>

		<?php if ( ! empty( $this->users ) ) : ?>
			<div class="tablenav top">
				<div class="alignleft actions">
					<select name="unconfirmed_action">
						<option value="resend"><?php esc_html_e( 'Resend Activation Email', 'unconfirmed' ); ?>&nbsp;&nbsp;</option>
						<option value="activate"><?php esc_html_e( 'Activate', 'unconfirmed' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'unconfirmed' ); ?></option>
					</select>

					<input id="doaction" class="button-secondary action" type="submit" value="<?php esc_html_e( 'Apply', 'unconfirmed' ); ?>" />
					<input type="hidden" name="unconfirmed_bulk" value="1" />

					<?php wp_nonce_field( 'unconfirmed_bulk_action' ); ?>
				</div>

				<div class="tablenav-pages unconfirmed-pagination">
					<div class="currently-viewing alignleft">
						<?php $pagination->currently_viewing_text(); ?>
					</div>

					<div class="pag-links alignright">
						<?php $pagination->paginate_links(); ?>
					</div>
				</div>
			</div>

			<table class="wp-list-table widefat ia-invite-list">

			<thead>
				<tr>
					<th scope="col" id="cb" class="check-column">
						<input type="checkbox" />
					</th>

					<?php
					if ( $sortable->have_columns() ) {
						while ( $sortable->have_columns() ) {
							$sortable->the_column();
							$sortable->the_column_th();
						}
					}
					?>

				</tr>
			</thead>

			<tbody>
				<?php foreach ( $this->users as $user ) : ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="checkbox" name="unconfirmed_key[]" value="<?php echo esc_attr( $user->activation_key ); ?>" />
					</th>

					<td class="login">
						<?php echo esc_html( $user->user_login ); ?>

						<div class="row-actions">
							<?php
							$resend_url = wp_nonce_url(
								add_query_arg(
									array(
										'unconfirmed_action' => 'resend',
										'unconfirmed_key' => $user->activation_key,
									),
									$this->base_url
								),
								'unconfirmed_resend_email'
							);
							?>
							<span class="edit"><a class="confirm" href="<?php echo esc_attr( $resend_url ); ?>"><?php esc_html_e( 'Resend Activation Email', 'unconfirmed' ); ?></a></span>

							&nbsp;&nbsp;
							<?php
							$activate_url = wp_nonce_url(
								add_query_arg(
									array(
										'unconfirmed_action' => 'activate',
										'unconfirmed_key' => $user->activation_key,
									),
									$this->base_url
								),
								'unconfirmed_activate_user'
							);
							?>
							<span class="edit"><a class="confirm" href="<?php echo esc_attr( $activate_url ); ?>"><?php esc_html_e( 'Activate', 'unconfirmed' ); ?></a></span>

							&nbsp;&nbsp;
							<?php
							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'unconfirmed_action' => 'delete',
										'unconfirmed_key' => $user->activation_key,
									),
									$this->base_url
								),
								'unconfirmed_delete_user'
							);
							?>
							<span class="delete"><a title="<?php esc_html_e( 'Deleting a registration means that it will be removed from the database, and the user will be unable to activate his account. Proceed with caution!', 'unconfirmed' ); ?>" class="confirm" href="<?php echo esc_attr( $delete_url ); ?>"><?php esc_html_e( 'Delete', 'unconfirmed' ); ?></a></span>

						</div>
					</td>

					<?php if ( ! $this->is_multisite ) : ?>
						<td class="display_name">
							<?php echo esc_html( $user->display_name ); ?>
						</td>
					<?php endif ?>

					<td class="email">
						<?php echo esc_html( $user->user_email ); ?>
					</td>

					<td class="registered">
						<?php echo esc_html( $user->registered ); ?>
					</td>

					<td class="activation_key">
						<?php echo esc_html( $user->activation_key ); ?>
					</td>

					<td class="activation_key">
						<?php echo esc_html( (int) $user->resent_count ); ?>
					</td>

				</tr>
				<?php endforeach ?>
			</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="unconfirmed-pagination alignright tablenav-pages">
					<div class="currently-viewing alignleft">
						<?php $pagination->currently_viewing_text(); ?>
					</div>

					<div class="pag-links alignright">
						<?php $pagination->paginate_links(); ?>
					</div>
				</div>
			</div>

		<?php else : ?>

			<p><?php esc_html_e( 'No unactivated members were found.', 'unconfirmed' ); ?></p>

		<?php endif ?>

		</form>

		</div>
		<?php
	}
}
