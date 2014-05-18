<?php
/**
 * Plugin Name: myCRED subscription addon
 * Plugin URI: https://github.com/abhishekfdd/mycred-subscription-addon
 * Description: A myCRED subscription addon for forum and topic subscription points and daily user points limit.
 * Version: 1.0
 * Author: Abhishek Kumar
 * Author URI: https://github.com/abhishekfdd
 * License: GPL2
 */
add_filter('mycred_setup_hooks', 'register_my_custom_hook');

function register_my_custom_hook($installed) {
    $installed['hook_custom_bbpress'] = array(
        'title' => __('Addon Hook', 'mca'),
        'description' => __('This is an addon hook', 'mca'),
        'callback' => array('myCRED_custom_bbPress')
    );
    return $installed;
}

//Daily Limit
add_filter('mycred_add', 'restrict_points_to_once_per_day', 1, 3);

/**
 * Daily Limit
 * @version 1.0
 */
function restrict_points_to_once_per_day($reply, $request, $mycred) {

    $daily_limit_pref_obj = get_option('mycred_pref_hooks');

    $daily_limit = $daily_limit_pref_obj['hook_prefs']['hook_custom_bbpress']['daily_limit']['creds'];

    // If something else already declined this, respect it
    if ($reply === false) {
        return $reply;
    }

    // User ID
    $user_id = (int) $request['user_id'];

    // Today
    $today = date_i18n('Y-m-d');

    // Get stored limit
    $limits = (array) get_user_meta($user_id, 'daily_limits', true);

    // If no limits, or exisitng limit was for another date, reset
    if (empty($limits) || !isset($limits[$today])) {
        $limits[$today] = 0;
    }

    // Max 10 per reference
    if (intval($limits[$today]) >= $daily_limit) {
        extract($request);

        $amount = '0.0';

        $mycred->update_users_balance($user_id, $amount, $type);

        if (!empty($entry)) {

            ab_add_to_log($ref, $user_id, $amount, $entry, $ref_id, $data, $type);
        }

        return 'done';
    } else {
        extract($request);

        if ($limits[$today] + $amount > $daily_limit) {
            $amount = ( $limits[$today] + $amount ) - $daily_limit;
        }

        $mycred->update_users_balance($user_id, $amount, $type);

        if (!empty($entry)) {

            $mycred->add_to_log($ref, $user_id, $amount, $entry, $ref_id, $data, $type);
        }

        // Increment	
        $limits[$today] = intval($limits[$today]) + intval($request['amount']);

        // Save
        update_user_meta($user_id, 'daily_limits', $limits);

        return 'done';
    }
}

/**
 * bbPress custom Hook
 */
function ab_bbpress_init_class() {
    if (!class_exists('myCRED_custom_bbPress') && class_exists('myCRED_Hook')) {

        class myCRED_custom_bbPress extends myCRED_Hook {

            /**
             * Construct
             */
            function __construct($hook_prefs) {
                parent::__construct(array(
                    'id' => 'hook_custom_bbpress',
                    'defaults' => array(
                        'subscribe_topic' => array(
                            'creds' => 1,
                            'log' => '%plural% for subscribe topic'
                        ), 'unsubscribe_topic' => array(
                            'creds' => 0 - 1,
                            'log' => '%singular% deduction for unsubscribe topic'
                        )
                        , 'daily_limit' => array(
                            'creds' => 10,
                            'log' => 'daily_limit'
                        )
                    )
                        ), $hook_prefs);
            }

            /**
             * Run
             * @since 0.1
             * @version 1.2
             */
            public function run() {

                // Subscribe topic
                if ($this->prefs['subscribe_topic']['creds'] != 0)
                    add_action('bbp_add_user_subscription', array(&$this, 'subscribe_topic'), 1, 1);
                // Unubscribe topic
                if ($this->prefs['unsubscribe_topic']['creds'] != 0)
                    add_action('bbp_remove_user_subscription', array(&$this, 'unsubscribe_topic'), 1, 1);
            }

            /**
             * Subscribe Topic
             * @since 1.2
             * @version 1.1
             */
            public function subscribe_topic($user_id) {

                $topic_id = bbp_get_topic_id();

                // $user_id is loggedin_user, not author, so get topic author
                $topic_author = get_post_field('post_author', $topic_id);

                // Check if user is excluded (required)
                if ($this->core->exclude_user($current_user) || $topic_author == $user_id) {
                    return;
                }

                // Execute
                $this->core->add_creds(
                        'subscribe_topic', $topic_author, $this->prefs['subscribe_topic']['creds'], $this->prefs['subscribe_topic']['log'], $topic_id, $this->mycred_type
                );
            }

            /**
             * Unsubscribe Topic
             * @since 1.2
             * @version 1.1
             */
            public function unsubscribe_topic($user_id) {

                $topic_id = bbp_get_topic_id();

                // $user_id is loggedin_user, not author, so get topic author
                $topic_author = get_post_field('post_author', $topic_id);

                // Check if user is excluded (required)
                if ($this->core->exclude_user($current_user) || $topic_author == $user_id) {
                    return;
                }

                // Execute
                $this->core->add_creds(
                        'unsubscribe_topic', $topic_author, $this->prefs['unsubscribe_topic']['creds'], $this->prefs['unsubscribe_topic']['log'], $topic_id, $this->mycred_type
                );
            }

            /**
             * Preferences
             * @since 0.1
             * @version 1.2
             */
            public function preferences() {
                $prefs = $this->prefs;

                // Update
                if (!isset($prefs['subscribe_topic']))
                    $prefs['subscribe_topic'] = 0;
                if (!isset($prefs['unsubscribe_topic']))
                    $prefs['unsubscribe_topic'] = 0;
                if (!isset($prefs['daily_limit']))
                    $prefs['daily_limit'] = 0;
                ?>

                <!-- Daily Limit -->
                <label for="<?php echo $this->field_id(array('daily_limit', 'creds')); ?>" class="subheader"><?php echo $this->core->template_tags_general(__('Daily limit', 'mycred')); ?></label>
                <ol>
                    <li>
                        <div class="h2"><input type="text" name="<?php echo $this->field_name(array('daily_limit', 'creds')); ?>" id="<?php echo $this->field_id(array('daily_limit', 'creds')); ?>" value="<?php echo $this->core->number($prefs['daily_limit']['creds']); ?>" size="8" /></div>
                    </li>
                    <li class="empty">&nbsp;</li>
                </ol>

                <!-- Creds for Topic subscription -->
                <label for="<?php echo $this->field_id(array('subscribe_topic', 'creds')); ?>" class="subheader"><?php echo $this->core->template_tags_general(__('%plural% for Subscribe topic', 'mycred')); ?></label>
                <ol>
                    <li>
                        <div class="h2"><input type="text" name="<?php echo $this->field_name(array('subscribe_topic', 'creds')); ?>" id="<?php echo $this->field_id(array('unsubscribe_topic', 'creds')); ?>" value="<?php echo $this->core->number($prefs['subscribe_topic']['creds']); ?>" size="8" /></div>
                    </li>
                    <li class="empty">&nbsp;</li>
                    <li>
                        <label for="<?php echo $this->field_id(array('subscribe_topic', 'log')); ?>"><?php _e('Log template', 'mycred'); ?></label>
                        <div class="h2"><input type="text" name="<?php echo $this->field_name(array('subscribe_topic', 'log')); ?>" id="<?php echo $this->field_id(array('subscribe_topic', 'log')); ?>" value="<?php echo $prefs['subscribe_topic']['log']; ?>" class="long" /></div>
                        <span class="description"><?php echo $this->available_template_tags(array('general', 'post')); ?></span>
                    </li>
                </ol>
                <!-- Creds for Topic unsubscription -->
                <label for="<?php echo $this->field_id(array('unsubscribe_topic', 'creds')); ?>" class="subheader"><?php echo $this->core->template_tags_general(__('%plural% for Unsubscribe topic', 'mycred')); ?></label>
                <ol>
                    <li>
                        <div class="h2"><input type="text" name="<?php echo $this->field_name(array('unsubscribe_topic', 'creds')); ?>" id="<?php echo $this->field_id(array('unsubscribe_topic', 'creds')); ?>" value="<?php echo $this->core->number($prefs['unsubscribe_topic']['creds']); ?>" size="8" /></div>
                    </li>
                    <li class="empty">&nbsp;</li>
                    <li>
                        <label for="<?php echo $this->field_id(array('unsubscribe_topic', 'log')); ?>"><?php _e('Log template', 'mycred'); ?></label>
                        <div class="h2"><input type="text" name="<?php echo $this->field_name(array('unsubscribe_topic', 'log')); ?>" id="<?php echo $this->field_id(array('unsubscribe_topic', 'log')); ?>" value="<?php echo $prefs['unsubscribe_topic']['log']; ?>" class="long" /></div>
                        <span class="description"><?php echo $this->available_template_tags(array('general', 'post')); ?></span>
                    </li>
                </ol>
                <?php
            }

        }

    }
}

add_action('plugins_loaded', 'ab_bbpress_init_class');

/** Custom function for adding log * */
function ab_add_to_log($ref = '', $user_id = '', $amount = '', $entry = '', $ref_id = '', $data = '', $type = 'mycred_default') {

    $obj_settings = new myCRED_Settings();

    // All the reasons we would fail
    if (empty($ref) || empty($user_id) || empty($amount))
        return false;

    global $wpdb;

    // Strip HTML from log entry
    $entry = $obj_settings->allowed_tags($entry);

    // Enforce max
    if ($obj_settings->max() > $obj_settings->zero() && $amount > $obj_settings->max()) {
        $amount = $obj_settings->number($obj_settings->max());
    }

    // Type
    if (empty($type))
        $type = $obj_settings->get_cred_id();

    // Creds format
    if ($obj_settings->format['decimals'] > 0)
        $format = '%f';
    elseif ($obj_settings->format['decimals'] == 0)
        $format = '%d';
    else
        $format = '%s';

    $time = apply_filters('mycred_log_time', date_i18n('U'), $ref, $user_id, $amount, $entry, $ref_id, $data, $type);

    // Insert into DB
    $new_entry = $wpdb->insert(
            $obj_settings->log_table, array(
        'ref' => $ref,
        'ref_id' => $ref_id,
        'user_id' => (int) $user_id,
        'creds' => $amount,
        'ctype' => $type,
        'time' => $time,
        'entry' => $entry,
        'data' => ( is_array($data) || is_object($data) ) ? serialize($data) : $data
            ), array(
        '%s',
        '%d',
        '%d',
        $format,
        '%s',
        '%d',
        '%s',
        ( is_numeric($data) ) ? '%d' : '%s'
            )
    );

    // $wpdb->insert returns false on fail
    if (!$new_entry)
        return false;

    delete_transient('mycred_log_entries');
    return true;
}
