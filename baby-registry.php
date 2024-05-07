<?php
/*
Plugin Name: WooCommerce Baby Registry
Plugin URI: http://metazone.store/
Description: Enables a baby registry feature for WooCommerce stores.
Version: 1.0
Author: Psyscho bit
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Activation Hook: Set up database tables for registries and registry items.
 */
function baby_registry_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $registry_details_table = $wpdb->prefix . "baby_registry_details";
    $sql_registry_details = "CREATE TABLE $registry_details_table (
        registry_id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        registry_name text NOT NULL,
        registry_description text,
        due_date DATE,
        baby_room INT,
        items_count INT DEFAULT 0,
        items_purchased INT DEFAULT 0,
        PRIMARY KEY  (registry_id),
        FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}users(ID) ON DELETE CASCADE
    ) $charset_collate;";

    dbDelta($sql_registry_details);

    $registry_items_table = $wpdb->prefix . "baby_registry_items";
    $sql_registry_items = "CREATE TABLE $registry_items_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        registry_id mediumint(9) NOT NULL,
        product_id bigint(20) UNSIGNED NOT NULL,
        quantity smallint(5) NOT NULL DEFAULT 1,
        purchased BOOLEAN NOT NULL DEFAULT 0,
        purchased_quantity smallint(5) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        FOREIGN KEY (registry_id) REFERENCES $registry_details_table(registry_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES {$wpdb->prefix}posts(ID) ON DELETE CASCADE
    ) $charset_collate;";

    dbDelta($sql_registry_items);

    error_log('Creating tables for baby registry.');

}
register_activation_hook(__FILE__, 'baby_registry_activate');



/**
 * Get regisrty items based on product categories
 */

 function get_registry_items($registry_id, $category_filters = []) {
    global $wpdb;

    // Check if registry_id is provided and valid
    if (empty($registry_id)) {
        return new WP_Error('invalid_registry', 'No valid registry ID provided.');
    }

    // Convert category filters from slugs or names to IDs if they are not IDs
    $category_ids = array_map(function($item) use ($wpdb) {
        if (is_numeric($item)) {
            return intval($item);
        } else {
            $term = get_term_by('slug', $item, 'product_cat');
            return ($term) ? $term->term_id : 0;
        }
    }, $category_filters);

    // Add check to return all items if no category filters are provided
    $category_condition = "";
    if (!empty($category_ids)) {
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $category_condition = "AND tt.term_id IN ($placeholders)";
    }

    // Prepare the query to fetch products associated with the specific registry and optionally by categories
    $query = $wpdb->prepare("
        SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->prefix}posts AS p
        JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
        JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$wpdb->prefix}baby_registry_items AS bri ON p.ID = bri.product_id
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND bri.registry_id = %d
        $category_condition",
        array_merge([$registry_id], $category_ids)
    );

    // Execute the query and return the results
    $products = $wpdb->get_results($query);

    // Optionally process results, such as getting more detailed product info
    return array_map(function($product) {
        return wc_get_product($product->ID);
    }, $products);
}


/**
 * Display the registry 
 */
function display_baby_registry($registry_id, $category_filters = []) {
    global $wpdb;

    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view registry details.</p>';
    }

    // Fetch the registry name from the database
    $registry_details_table = $wpdb->prefix . "baby_registry_details";
    $registry_name = $wpdb->get_var($wpdb->prepare(
        "SELECT registry_name FROM $registry_details_table WHERE registry_id = %d",
        $registry_id
    ));

    if (!$registry_name) {
        return '<p>Registry not found.</p>'; // Handle case where registry is not found
    }

    $output = '<h3>' . esc_html($registry_name) . '</h3>'; // Display the fetched registry name

    // Show delete button if the logged-in user is the owner of the registry
if ($registry->user_id == get_current_user_id()) {
    $output .= '<button id="deleteRegistryButton" class="delete-registry-button" data-registry-id="' . esc_attr($registry_id) . '">Delete Registry</button>';
}


    // Get items filtered by category
    $items = get_registry_items($registry_id, $category_filters);  // Assume get_registry_items now also takes registry_id

    // Check if items exist
    if (!empty($items)) {
        $output .= "<ul>";
        foreach ($items as $product) {
            if ($product) {
                $name = esc_html($product->get_name());
                $short_description = esc_html($product->get_short_description());
                $price = wc_price($product->get_price());  // Format price with WooCommerce settings
                $sale_price = $product->get_sale_price() ? wc_price($product->get_sale_price()) : 'N/A';
                $image_id = $product->get_image_id();
                $gallery_image_ids = implode(', ', $product->get_gallery_image_ids()); // Converts array to comma-separated string

                // Format for displaying image; assumes you want to show the main image
                $image_url = wp_get_attachment_url($image_id);
                $image_html = $image_url ? "<img src='{$image_url}' alt='{$name}' style='width:100px;' />" : 'No image available';

                $output .= "<li>";
                $output .= "{$image_html} <strong>{$name}</strong> - {$short_description}<br>";
                $output .= "Price: {$price} ";
                $output .= ($sale_price !== 'N/A') ? "Sale Price: {$sale_price} " : "";
                $output .= "</li>";
            }
        }
        $output .= "</ul>";
    } else {
        $output .= "<p>No products found in this category.</p>";
    }

    return $output;
}

add_shortcode('baby_registry', 'display_baby_registry');

/**
 * get user registries
 */

function get_user_registries() {
    global $wpdb;
    $user_id = get_current_user_id();

    // Ensure the user is logged in
    if (empty($user_id)) {
        return new WP_Error('no_user', 'User is not logged in.');
    }

    $table_name = $wpdb->prefix . "baby_registry_details";
    $registries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d",
        $user_id
    ));

    if (empty($registries)) {
        // Optionally handle the scenario where no registries are found
        return [];
    }

    return $registries;
}


/**
 * Display user registries Shortcode
 */

function display_user_registries() {
    $registries = get_user_registries();
    if (is_wp_error($registries)) {
        return '<p>Error: ' . $registries->get_error_message() . '</p>';
    }

    if (empty($registries)) {
        return '<p>No registries found.</p>';
    }

    $output = '<ul class="user-registries">';
    foreach ($registries as $registry) {
        $output .= '<li>';
        $output .= '<h3>' . esc_html($registry->registry_name) . '</h3>';
        $output .= '<p>Description: ' . esc_html($registry->registry_description) . '</p>';
        $output .= '<p>Due Date: ' . esc_html($registry->due_date) . '</p>';
        $output .= '<p>Baby Room: ' . intval($registry->baby_room) . '</p>';
        $output .= '</li>';
    }
    $output .= '</ul>';

    return $output;
}
add_shortcode('user_registries', 'display_user_registries');


/**
 * Adds a 'Add to Registry' button on each WooCommerce product.
 */
function add_registry_button_on_product() {
    global $product;
    
    // Get registries for the current user
    $registries = get_user_registries();
    
    // Start form output
    echo '<form class="registry-form">';
    
    // Dropdown for selecting registry
    if (!empty($registries)) {
        echo '<select name="registry_id" class="registry-select" style="margin-right: 5px;">';
        foreach ($registries as $registry) {
            echo '<option value="' . esc_attr($registry->id) . '">' . esc_html($registry->name) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<p>No registries available. Please create one first.</p>';
    }

    // Quantity input
    echo '<input type="number" name="quantity" class="registry-quantity-input" value="1" min="1" style="margin-right: 5px;">';
    
    // Add to registry button
    echo '<button type="button" class="add-to-registry-button" data-product_id="' . esc_attr($product->get_id()) . '">Add to Registry</button>';
    
    // End form
    echo '</form>';
}
add_action('woocommerce_after_shop_loop_item', 'add_registry_button_on_product', 20);


/**
 * AJAX handler for adding items to the registry.
 */
function add_to_registry_ajax() {
    $registry_id = isset($_POST['registry_id']) ? intval($_POST['registry_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

    if ($registry_id <= 0) {
        wp_send_json_error('Invalid registry specified.');
        return;
    }

    if ($product_id > 0 && $quantity > 0) {
        $result = add_to_baby_registry($registry_id, $product_id, $quantity);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Item successfully added to registry.');
        }
    } else {
        wp_send_json_error('Failed to add item to the registry.');
    }
}
add_action('wp_ajax_add_to_registry_ajax', 'add_to_registry_ajax');


/**
 * Enqueue scripts and styles, include AJAX script.
 */
function baby_registry_scripts() {
    // Enqueue the JavaScript file
    wp_enqueue_script('baby-registry-js', plugin_dir_url(__FILE__) . 'js/baby-registry.js', array('jquery'), '1.0', true);
    
    // Localize the script to pass the AJAX URL and a nonce for security
    wp_localize_script('baby-registry-js', 'babyRegistryParams', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('baby_registry_nonce')
    ]);

    // Enqueue the CSS file
    wp_enqueue_style('baby-registry-css', plugin_dir_url(__FILE__) . 'css/baby-registry.css');
}
add_action('wp_enqueue_scripts', 'baby_registry_scripts');


/**
 * Check registry count
 */

define('MAX_REGISTRIES_PER_USER', 2);  // Define a constant for the max number of registries

function can_create_new_registry($user_id) {
    global $wpdb;
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}baby_registry_details WHERE user_id = %d",
        $user_id
    ));
    return ($count < MAX_REGISTRIES_PER_USER);
}


/**
 * Function to create a new baby registry.
 */
function create_baby_registry($user_id, $names, $description = '', $due_date = '', $baby_room = 0) {
    global $wpdb;

    // Check if the user can create more registries
    if (!can_create_new_registry($user_id)) {
        return new WP_Error('registry_limit_reached', 'You have reached the maximum number of registries allowed.');
    }

    // Validate and sanitize input data
    if (empty($user_id) || empty($names)) {
        return new WP_Error('invalid_data', 'Invalid user ID or registry name.');
    }

    $names_json = json_encode($names);
    $description = sanitize_textarea_field($description);
    $due_date = sanitize_text_field($due_date);
    $baby_room = (int)$baby_room;

    // Insert the new registry into the database
    $result = $wpdb->insert(
        $wpdb->prefix . "baby_registry_details",
        array(
            'user_id' => $user_id,
            'registry_name' => $names_json,
            'registry_description' => $description,
            'due_date' => $due_date,
            'baby_room' => $baby_room
        ),
        array('%d', '%s', '%s', '%s', '%d')
    );

    // Check for successful insertion
    if ($result === false) {
        return new WP_Error('db_insert_error', 'Failed to insert the registry into the database.');
    }

    // Return the ID of the newly created registry
    return $wpdb->insert_id;
}



/**
 * Shortcode to display form for creating a new registry.
 */
function display_registry_form() {
    $output = '<form action="" method="post">';
    $output .= '<label for="registry_name">Registry Name:</label>';
    $output .= '<input type="text" id="registry_name" name="registry_name" placeholder="Enter Registry Name" required>';
    $output .= '<label for="registry_description">Description:</label>';
    $output .= '<textarea id="registry_description" name="registry_description" placeholder="Enter Registry Description"></textarea>';
    $output .= '<label for="due_date">Due Date:</label>';
    $output .= '<input type="date" id="due_date" name="due_date">';
    $output .= '<label for="baby_room">Baby Room Number:</label>';
    $output .= '<input type="number" id="baby_room" name="baby_room" placeholder="Enter Baby Room Number">';
    $output .= '<input type="submit" name="submit_registry" value="Create Registry">';
    $output .= '</form>';

    if (isset($_POST['submit_registry'])) {
        $user_id = get_current_user_id();
        if ($user_id) {
            $name = isset($_POST['registry_name']) ? $_POST['registry_name'] : '';
            $description = isset($_POST['registry_description']) ? $_POST['registry_description'] : '';
            $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : '';
            $baby_room = isset($_POST['baby_room']) ? intval($_POST['baby_room']) : 0;

            $registry_id = create_baby_registry($user_id, $name, $description, $due_date, $baby_room);
            if ($registry_id) {
                $output .= '<p>Registry created successfully!</p>';
            } else {
                $output .= '<p>Error creating registry.</p>';
            }
        } else {
            $output .= '<p>You must be logged in to create a registry.</p>';
        }
    }

    return $output;
}
add_shortcode('create_baby_registry_form', 'display_registry_form');


/**
 * Function to add item to registry.
 */
function wp_ajax_add_to_registry_handler() {
    // Check nonce sent from AJAX for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'baby_registry_nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
        return;
    }

    // Get data from AJAX request
    $registry_id = isset($_POST['registry_id']) ? intval($_POST['registry_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

    // Ensure user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'You must be logged in to add items to the registry.'));
        return;
    }

    // Get the current user's ID
    $user_id = get_current_user_id();

    // Verify that the registry belongs to the user
    global $wpdb;
    $registry_owner_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}baby_registry_details WHERE registry_id = %d",
        $registry_id
    ));

    if ($registry_owner_id != $user_id) {
        wp_send_json_error(array('message' => 'You do not have permission to add items to this registry.'));
        return;
    }

    // Insert the product into the specified registry
    $result = $wpdb->insert(
        "{$wpdb->prefix}baby_registry_items",
        array(
            'registry_id' => $registry_id,
            'product_id' => $product_id,
            'quantity' => $quantity
        ),
        array(
            '%d', '%d', '%d'
        )
    );

    if (false === $result) {
        wp_send_json_error(array('message' => 'Failed to insert the product into the registry.'));
    } else {
        wp_send_json_success(array('message' => 'Product added successfully', 'insert_id' => $wpdb->insert_id));
    }
}

// Hook the function to WordPress AJAX actions for logged-in users
add_action('wp_ajax_add_to_registry_ajax', 'wp_ajax_add_to_registry_handler');


/**
 * Function to delete registry.
 */

 function wp_ajax_delete_baby_registry_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_baby_registry_nonce')) {
        wp_send_json_error(['message' => __('Nonce verification failed, unable to delete registry.')]);
        return;
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in to delete registries.')]);
        return;
    }

    $registry_id = intval($_POST['registry_id'] ?? 0);
    if (!$registry_id) {
        wp_send_json_error(['message' => __('Invalid registry ID.')]);
        return;
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $registry_owner_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}baby_registry_details WHERE registry_id = %d", $registry_id));

    if ($registry_owner_id != $user_id) {
        wp_send_json_error(['message' => __('You do not have permission to delete this registry.')]);
        return;
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');

    // Delete registry items first
    $items_deleted = $wpdb->delete(
        "{$wpdb->prefix}baby_registry_items",
        ['registry_id' => $registry_id],
        ['%d']
    );

    if (false === $items_deleted) {
        $wpdb->query('ROLLBACK'); // Rollback transaction
        wp_send_json_error(array('message' => 'Error deleting registry items.'));
        return;
    }

    // Delete the registry itself
    $registry_deleted = $wpdb->delete(
        "{$wpdb->prefix}baby_registry_details",
        ['registry_id' => $registry_id],
        ['%d']
    );

    if (false === $registry_deleted) {
        $wpdb->query('ROLLBACK'); // Rollback transaction
        wp_send_json_error(array('message' => 'Error deleting registry.'));
        return;
    }

    // If everything went well, commit the transaction
    $wpdb->query('COMMIT');
    wp_send_json_success(array('message' => 'Registry deleted successfully.'));
}

// Hook the function to WordPress AJAX actions for logged-in users
add_action('wp_ajax_delete_baby_registry_ajax', 'wp_ajax_delete_baby_registry_handler');
