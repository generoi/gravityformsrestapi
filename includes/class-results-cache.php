<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Manages the entry results cache expiry and rebuild.
 *
 * GF_Results_Cache::get_results() will attempt to calculate the results inside the time_limit arg.
 * If incomplete then a WP Cron task is kicked off.
 * If the cron task is unable to finish within time_limit_cron then another task is scheduled until the results are complete.
 *
 * @package    Gravity Forms
 * @subpackage GF_Results_Cache
 * @access     public
 */
class GF_Results_Cache {
	public function __construct() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			add_action( 'gravityforms_results_cron', array( $this, 'results_cron' ), 10, 4 );
			return;
		}

		add_action( 'gform_entry_created', array( $this, 'entry_created' ), 10, 2 );
		add_action( 'gform_after_update_entry', array( $this, 'entry_updated' ), 10, 2 );
		add_action( 'gform_update_status', array( $this, 'update_entry_status' ), 10, 2 );
		add_action( 'gform_after_save_form', array( $this, 'after_save_form' ), 10, 2 );
	}

	/**
	 * Contains an instance of this class, if available.
	 *
	 * @access private
	 * @var GF_Results_Cache $_instance If available, contains an instance of this class.
	 */
	private static $_instance = null;

	/**
	 * Returns an instance of this class, and stores it in the $_instance property.
	 *
	 * @access public
	 * @static
	 * @return GF_Results_Cache $_instance
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GF_Results_Cache();
		}

		return self::$_instance;
	}

	/**
	 * Returns the default args for the results cache process.
	 *
	 * time_limit           - Max seconds allowed per batch.
	 * time_limit_cron      - Max seconds allowed per batch while inside the cron task.
	 * page_size            - Page size for each batch search results.
	 * callbacks            - An array of callbacks. One supported callback: 'calculation' $cache_data, $form, $fields, $entries
	 * wait                 - Time in seconds to wait between each cron task.
	 * field_ids            - An array of field IDs to include in the results.
	 * @return array
	 */
	public function get_default_args() {
		return array(
			'time_limit' => 15, // Max seconds for the initial attempt.
			'time_limit_cron' => 15, // Max seconds for the cron task.
			'page_size' => 100,
			'callbacks' => array(),
			'wait' => 10,
			'field_ids' => false,
			'labels' => true,
		);
	}


	/**
	 * Callback for the gform_update_status action.
	 *
	 * @param $lead_id
	 */
	public function update_entry_status( $lead_id ) {
		$lead    = RGFormsModel::get_lead( $lead_id );
		$form_id = $lead['form_id'];
		$form    = GFFormsModel::get_form_meta( $form_id );
		$this->maybe_update_results_cache_meta( $form );
	}

	/**
	 * Callback for the gform_after_update_entry action.
	 *
	 * @param $form
	 * @param $lead_id
	 */
	public function entry_updated( $form, $lead_id ) {
		$this->maybe_update_results_cache_meta( $form );
	}


	/**
	 * Callback for the gform_entry_created action.
	 *
	 * @param $entry
	 * @param $form
	 */
	public function entry_created( $entry, $form ) {
		$this->maybe_update_results_cache_meta( $form );
	}

	/**
	 * Callback for the gform_after_save_form action.
	 *
	 * @param $form
	 * @param $is_new
	 */
	public function after_save_form( $form, $is_new ) {
		if ( $is_new ) {
			return;
		}
		$form_id = $form['id'];

		// only need to update the cache meta when cached results exist
		if ( ! $this->cached_results_exists( $form_id ) ) {
			return;
		}

		$fields              = rgar( $form, 'fields' );
		$current_fields_hash = wp_hash( json_encode( $fields ) );

		$cache_meta         = $this->get_results_cache_meta( $form_id );
		$cached_fields_hash = rgar( $cache_meta, 'fields_hash' );

		if ( ! hash_equals( $current_fields_hash, $cached_fields_hash ) ) {
			// delete the meta for this form
			$this->delete_results_cache_meta( $form_id );
			// delete all cached results for this form
			$this->delete_cached_results( $form_id );
		}
	}

	/**
	 * When entries are added or updated the cache needs to be expired and rebuilt.
	 * This cache meta records the last updated time for each form and a hash of the fields array.
	 * Each time results are requested this value is checked to make sure the cache is still valid.
	 *
	 * @param $form
	 */
	private function maybe_update_results_cache_meta( $form ) {
		$form_id = $form['id'];

		// Only need to update the cache meta when cached results exist.
		if ( ! $this->cached_results_exists( $form_id ) ) {
			return;
		}

		$this->update_results_cache_meta( $form_id, rgar( $form, 'fields' ) );
	}

	/**
	 * Updates the results cache meta containing a hash of the all the fields and a timestamp.
	 *
	 * @param $form_id
	 * @param $fields
	 */
	private function update_results_cache_meta( $form_id, $fields ) {

		$data = array(
			'fields_hash' => wp_hash( json_encode( $fields ) ),
			'timestamp'   => time(),
		);

		$key = $this->get_results_cache_meta_key( $form_id );

		$this->update_results_cache( $key, $data );

	}

	/**
	 * Deletes the cache meta.
	 *
	 * @param $form_id
	 */
	private function delete_results_cache_meta( $form_id ) {

		$key = $this->get_results_cache_meta_key( $form_id );

		delete_option( $key );

	}

	/**
	 * Returns the cache meta key.
	 *
	 * @param $form_id
	 *
	 * @return string
	 */
	private function get_results_cache_meta_key( $form_id ) {
		$key = 'gf-results-cache-meta-form-' . $form_id;

		return $key;
	}

	/**
	 * Returns the cache meta.
	 *
	 * @param $form_id
	 *
	 * @return mixed|void
	 */
	private function get_results_cache_meta( $form_id ) {

		$key        = $this->get_results_cache_meta_key( $form_id );
		$cache_meta = get_option( $key );

		return $cache_meta;
	}

	/**
	 * Updates the results cache.
	 *
	 * @param $key
	 * @param $data
	 *
	 * @return bool
	 */
	private function update_results_cache( $key, $data ) {

		/* From: https://codex.wordpress.org/Function_Reference/add_option
		 *
		 * Until version 4.2, you could not specify autoload='no' if you use update_option().
		 * If you need to specify autoload='no', and you are not sure whether the option already exists,
		 * then call delete_option() first before calling add_option().
		 */

		delete_option( $key );

		$result = add_option( $key, $data, '', 'no' );

		return $result;
	}

	/**
	 * Checks whether a cache exists for the given form ID.
	 *
	 * @param $form_id
	 *
	 * @return bool
	 */
	private function cached_results_exists( $form_id ) {
		global $wpdb;

		$key = $this->get_results_cache_key_prefix( $form_id );

		$key = '%' . GFCommon::esc_like( $key ) . '%';

		$sql = $wpdb->prepare( "SELECT count(option_id) FROM $wpdb->options WHERE option_name LIKE %s", $key );

		$result = $wpdb->get_var( $sql );

		return $result > 0;

	}

	/**
	 * Deletes all the cached results for the given form ID.
	 *
	 * @param $form_id
	 *
	 * @return false|int|void
	 */
	public function delete_cached_results( $form_id ) {
		global $wpdb;

		$form = GFAPI::get_form( $form_id );
		if ( ! ( $form ) || ! is_array( $form ) ) {
			return;
		}

		$key = $this->get_results_cache_key_prefix( $form_id );

		$key = '%' . GFCommon::esc_like( $key ) . '%';

		$sql = $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", $key );

		$result = $wpdb->query( $sql );

		return $result;
	}

	/**
	 * Returns the prefix for the results cache option name.
	 *
	 * @param $form_id
	 *
	 * @return string
	 */
	public function get_results_cache_key_prefix( $form_id ) {

		$key = sprintf( 'gf-results-cache-%s-', $form_id );

		// The option_name column in the options table has a max length of 64 chars.
		// Truncate the key if it's too long for column and allow space for the 'tmp' prefix
		$key = substr( $key, 0, 60 );

		return $key;
	}

	/**
	 * Generates a unique key for the cache meta based on form ID, fields and
	 *
	 * @param $form_id
	 * @param $fields
	 * @param $search_criteria
	 *
	 * @return string
	 */
	public function get_results_cache_key( $form_id, $search_criteria = array() ) {

		$key = $this->get_results_cache_key_prefix( $form_id );
		$key .= wp_hash( json_encode( $search_criteria ) );

		return $key;
	}

	/**
	 * Recursive wp_cron task to continue the calculation of results.
	 *
	 * @param $form_id
	 * @param $search_criteria
	 * @param $args
	 */
	public function results_cron( $form_id, $search_criteria, $args ) {

		$args = wp_parse_args( $args, $this->get_default_args() );

		$form = GFAPI::get_form( $form_id );
		$key     = $this->get_results_cache_key( $form_id, $search_criteria );
		$key_tmp = 'tmp' . $key;
		$state   = get_option( $key_tmp, array() );

		if ( ! empty( $state ) ) {
			$results    = $this->calculate( $form, $search_criteria, $state, $args );
			if ( 'complete' == $results['status'] ) {
				if ( isset( $results['progress'] ) ) {
					unset( $results['progress'] );
				}
				$this->update_results_cache( $key, $results );
				if ( false == empty( $state ) ) {
					delete_option( $key_tmp );
				}
			} else {
				$this->update_results_cache( $key_tmp, $results );

				$data = get_option( $key );
				if ( $data ) {
					$data['progress'] = $results['progress'];
					$this->update_results_cache( $key, $data );
				}

				$this->schedule_results_cron( $form_id, $search_criteria, $args );
			}
		}
	}

	/**
	 * Schedules the cron task.
	 *
	 * @param $form_id
	 * @param $search_criteria
	 * @param $args
	 */
	private function schedule_results_cron( $form_id, $search_criteria, $args ) {
		$args = wp_parse_args( $args, $this->get_default_args() );

		$cron_args = array( $form_id, $search_criteria, $args );
		$delay_in_seconds = $args['wait'];
		wp_schedule_single_event( time() + $delay_in_seconds, $this->get_results_cron_hook(), $cron_args );
	}

	/**
	 *
	 *
	 * @param $form_id
	 * @param $search_criteria
	 * @param $args
	 *
	 * @return false|int
	 */
	public function results_cron_is_scheduled( $form_id, $search_criteria, $args ) {
		$args = wp_parse_args( $args, $this->get_default_args() );
		$cron_args = array( $form_id, $search_criteria, $args );

		return wp_next_scheduled( $this->get_results_cron_hook(), $cron_args );
	}

	public function get_results_cron_hook() {
		return 'gravityforms_results_cron';
	}

	/**
	 * Returns an array with the results for all the fields in the form.
	 * If the results can be calculated within the time allowed in GFResults then the results are returned and nothing is cached.
	 * If the calculation has not finished then a single recursive wp_cron task will be scheduled for immediate execution.
	 * While the cache is being built by the wp_cron task this function will return the expired cache results if available or the latest step in the cache build.
	 * Add-On-specific results are not included e.g. grade frequencies in the Quiz Add-On.
	 *
	 * @param int $form_id
	 * @param array $search_criteria
	 * @param array $args
	 *
	 * @return array|mixed|void
	 */
	public function get_results( $form_id, $search_criteria = array(), $args = array() ) {

		$args = wp_parse_args( $args, $this->get_default_args() );

		$form = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found', 'gravityforms' ) );
		}

		$fields = rgar( $form, 'fields' );

		$form_id = $form['id'];
		$key     = $this->get_results_cache_key( $form_id, $search_criteria );
		$key_tmp = 'tmp' . $key;

		$data = get_option( $key, array() );

		$cache_meta = $this->get_results_cache_meta( $form_id );

		// add the cache meta early so form editor updates can test for valid field hash
		if ( empty( $cache_meta ) ) {
			$this->update_results_cache_meta( $form_id, $fields );
		}

		$cache_expiry    = rgar( $cache_meta, 'timestamp' );
		$cache_timestamp = isset( $data['timestamp'] ) ? $data['timestamp'] : 0;
		$cache_expired   = $cache_expiry ? $cache_expiry > $cache_timestamp : false;

		// check for valid cached results first
		if ( ! empty( $data ) && 'complete' == rgar( $data, 'status' ) && ! $cache_expired ) {
			$results = $data;
			if ( isset( $results['progress'] ) ) {
				unset( $results['progress'] );
			}
		} else {

			$state = get_option( $key_tmp );

			if ( empty( $state ) || ( 'complete' == rgar( $data, 'status' ) && $cache_expired ) ) {

				$results            = $this->calculate( $form, $search_criteria, $state, $args );
				if ( rgar( $results, 'status' ) == 'complete' ) {
					if ( false == empty( $state ) ) {
						delete_option( $key_tmp );
					}
				} else {

					if ( ! empty( $data ) && rgar( $data, 'status' ) == 'complete' && $cache_expired ) {
						$data['status']   = 'expired';
						$data['progress'] = $results['progress'];
						$this->update_results_cache( $key, $data );
					}

					$this->update_results_cache( $key_tmp, $results );

					$this->schedule_results_cron( $form_id, $search_criteria, $args );

					if ( $data ) {
						$results = $data;
					}
				}
			} else {

				// The cron task is recursive, not periodic, so system restarts, script timeouts and memory issues can prevent the cron from restarting.
				// Check timestamp and kick off the cron again if it appears to have stopped
				$state_timestamp = rgar( $state, 'timestamp' );
				$state_age       = time() - $state_timestamp;
				if ( $state_age > 180 && ! $this->results_cron_is_scheduled( $form, $search_criteria, $args ) ) {
					$this->schedule_results_cron( $form_id, $search_criteria, $args );
				}

				if ( ! empty( $data ) && rgar( $data, 'status' ) == 'expired' ) {
					$results = $data;
				} else {
					$results = $state;
				}
			}
		}

		$field_data = rgar( $results, 'field_data' );

		if ( ! empty( $field_data ) && $args['labels']) {
			// add choice labels to the results so the client doesn't need to cross-reference with the form object
			$results['labels'] = $this->get_labels( $form, $args );
		}

		return $results;
	}

	/**
	 * Calculate a batch of entry results.
	 *
	 * @param $form
	 * @param array $search_criteria
	 * @param array $state_array
	 * @param $args
	 *
	 * @return array|mixed
	 */
	public function calculate( $form, $search_criteria = array(), $state_array = array(), $args ) {

		$args = wp_parse_args( $args, $this->get_default_args() );

		$max_execution_time = defined( 'DOING_CRON' ) && DOING_CRON ? $args['time_limit_cron'] : $args['time_limit'];
		$page_size = $args['page_size'];
		$callbacks = $args['callbacks'];

		$time_start = microtime( true );

		$form_id     = $form['id'];
		$data        = array();
		$offset      = 0;
		$entry_count = 0;
		$field_data  = array();

		$fields = $this->filter_fields( $form, $args['field_ids'] );

		if ( $state_array ) {
			// get counts from state
			$data   = $state_array;
			$offset = (int) rgar( $data, 'offset' );

			unset( $data['offset'] );
			$entry_count = $offset;
			$field_data  = rgar( $data, 'field_data' );
		} else {
			// initialize counts
			foreach ( $fields as $field ) {
				/* @var GF_Field $field */
				$field_type = $field->get_input_type();
				if ( ! isset( $field->choices ) ) {
					$field_data[ $field->id ] = 0;
					continue;
				}
				$choices = $field->choices;

				if ( $field_type == 'likert' && $field->gsurveyLikertEnableMultipleRows ) {
					foreach ( $field->gsurveyLikertRows as $row ) {
						foreach ( $choices as $choice ) {
							$field_data[ $field->id ][ $row['value'] ][ $choice['value'] ] = 0;
						}
						if ( $field->gsurveyLikertEnableScoring ) {
							$field_data[ $field->id ][ $row['value'] ]['row_score_sum'] = 0;
						}
					}
				} else {
					if ( ! empty( $choices ) && is_array( $choices ) ) {
						foreach ( $choices as $choice ) {
							$field_data[ $field->id ][ $choice['value'] ] = 0;
						}
					} else {
						$field_data[ $field->id ] = 0;
					}
				}
				if ( $field_type == 'likert' && rgar( $field, 'gsurveyLikertEnableScoring' ) ) {
					$field_data[ $field->id ]['sum_of_scores'] = 0;
				}
			}
		}

		$count_search_leads  = GFAPI::count_entries( $form_id, $search_criteria );
		$data['entry_count'] = $count_search_leads;

		if ( $count_search_leads == 0 ) {
			$data['status'] = 'complete';
		}

		$entries_left = $count_search_leads - $offset;

		while ( $entries_left > 0 ) {

			$paging = array(
				'offset'    => $offset,
				'page_size' => $page_size,
			);

			$search_leads_time_start = microtime( true );
			$leads                   = GFFormsModel::search_leads( $form_id, $search_criteria, null, $paging );
			$search_leads_time_end   = microtime( true );
			$search_leads_time       = $search_leads_time_end - $search_leads_time_start;

			$leads_in_search = count( $leads );

			$entry_count += $leads_in_search;
			$leads_processed = 0;
			foreach ( $leads as $lead ) {

				$lead_time_start = microtime( true );
				foreach ( $fields as $field ) {
					$field_type = $field->get_input_type();
					$field_id   = $field->id;

					$value      = RGFormsModel::get_lead_field_value( $lead, $field );

					if ( $field_type == 'likert' && rgar( $field, 'gsurveyLikertEnableMultipleRows' ) ) {

						if ( empty( $value ) ) {
							continue;
						}
						foreach ( $value as $value_vector ) {
							if ( empty( $value_vector ) ) {
								continue;
							}
							list( $row_val, $col_val ) = explode( ':', $value_vector, 2 );
							if ( isset( $field_data[ $field->id ][ $row_val ] ) && isset( $field_data[ $field->id ][ $row_val ][ $col_val ] ) ) {
								$field_data[ $field->id ][ $row_val ][ $col_val ] ++;
								if ( $field->gsurveyLikertEnableScoring ) {
									$field_data[ $field->id ][ $row_val ]['row_score_sum'] += $this->get_likert_row_score( $row_val, $field, $lead );
								}
							}
						}
					} elseif ( $field_type == 'rank' ) {
						$score  = count( rgar( $field, 'choices' ) );
						$values = explode( ',', $value );
						foreach ( $values as $ranked_value ) {
							$field_data[ $field->id ][ $ranked_value ] += $score;
							$score --;
						}
					} else {

						if ( empty( $field->choices ) ) {
							if ( ( ! is_array( $value ) && ! empty( $value ) ) || ( is_array( $value ) && ! GFCommon::is_empty_array( $value ) ) ) {
								$field_data[ $field_id ] ++;
							}
							continue;
						}

						$choices = $field->choices;

						foreach ( $choices as $choice ) {
							$choice_is_selected = false;
							if ( is_array( $value ) ) {
								$choice_value = rgar( $choice, 'value' );
								if ( in_array( $choice_value, $value ) ) {
									$choice_is_selected = true;
								}
							} else {
								if ( RGFormsModel::choice_value_match( $field, $choice, $value ) ) {
									$choice_is_selected = true;
								}
							}
							if ( $choice_is_selected ) {
								$field_data[ $field_id ][ $choice['value'] ] ++;
							}
						}
					}
					if ( $field_type == 'likert' && rgar( $field, 'gsurveyLikertEnableScoring' ) ) {
						$field_data[ $field->id ]['sum_of_scores'] += $this->get_likert_score( $field, $lead );
					}
				}
				$leads_processed ++;
				$lead_time_end       = microtime( true );
				$total_execution_time = $lead_time_end - $search_leads_time_start;
				$lead_execution_time = $lead_time_end - $lead_time_start;
				if ( $total_execution_time + $lead_execution_time > $max_execution_time ) {
					break;
				}
			}
			$data['field_data'] = $field_data;
			if ( isset( $callbacks['calculation'] ) && is_callable( $callbacks['calculation'] ) ) {
				$data       = call_user_func( $callbacks['calculation'], $data, $form, $fields, $leads );
				$field_data = $data['field_data'];
			}
			$offset += $leads_processed;
			$entries_left -= $leads_processed;

			$time_end       = microtime( true );
			$execution_time = ( $time_end - $time_start );

			if ( $entries_left > 0 && $execution_time + $search_leads_time > $max_execution_time ) {
				$data['status']   = 'incomplete';
				$data['offset']   = $offset;
				$progress         = $data['entry_count'] > 0 ? round( $data['offset'] / $data['entry_count'] * 100 ) : 0;
				$data['progress'] = $progress;
				break;
			}

			if ( $entries_left <= 0 ) {
				$data['status'] = 'complete';
			}
		}

		$data['timestamp'] = time();

		return $data;
	}

	private function get_likert_row_score( $row_val, $field, $entry ) {
		return is_callable( array(
			'GFSurvey',
			'get_likert_row_score',
		) ) ? GFSurvey::get_likert_row_score( $row_val, $field, $entry ) : 0;
	}

	private function get_likert_score( $field, $entry ) {
		return is_callable( array(
			'GFSurvey',
			'get_field_score',
		) ) ? GFSurvey::get_field_score( $field, $entry ) : 0;
	}

	private function get_labels( $form, $args ) {

		$args = wp_parse_args( $args, $this->get_default_args() );

		$fields = $this->filter_fields( $form, $args['field_ids'] );

		$labels = array();

		// replace the values/ids with text labels
		foreach ( $fields as $field ) {
			$field_id = $field->id;
			$field = GFFormsModel::get_field( $form, $field_id );

			if ( is_array( $field->choices ) ) {
				$label = array();
				$choice_labels = array();
				foreach ( $field->choices as $choice ) {
					$choice_labels[ $choice['value'] ] = $choice['text'];
				}

				if ( $field instanceof GF_Field_Likert && $field->gsurveyLikertEnableMultipleRows ) {
					/* @var GF_Field_Likert $field  */
					$label = array(
						'label' => $field->label,
						'cols' => $choice_labels,
						'rows' => array(),
					);
					foreach ( $field->gsurveyLikertRows as $row ) {
						$label['rows'][ $row['value'] ] = $row['text'];
					}
				} else {
					$label['label'] = $field->label;
					$label['choices'] = $choice_labels;
				}
			} else {
				$label = $field['label'];
			}

			$labels[ $field->id ] = $label;
		}

		return $labels;
	}

	private function filter_fields( $form, $field_ids ) {
		$fields = $form['fields'];
		if ( is_array( $field_ids ) && ! empty( $field_ids ) ) {
			foreach ( $fields as $key => $field ) {
				if ( ! in_array( $field->id, $field_ids ) ) {
					unset( $fields[ $key ] );
				}
			}
			$fields = array_values( $fields );
		}
		return $fields;
	}
}

function gf_results_cache() {
	return GF_Results_Cache::get_instance();
}

gf_results_cache();
