<?php
/*
Plugin Name: WooCommerce Baby Registry
Plugin URI: http://metazone.store/
Description: Enables a baby registry feature for WooCommerce stores.
Version: 1.1
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
        registry_name JSON NOT NULL,
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

    // Fetch the registry details from the database
    $registry_details_table = $wpdb->prefix . "baby_registry_details";

    // Fetch registry details
    $registry = $wpdb->get_row($wpdb->prepare(
        "SELECT registry_name, user_id FROM $registry_details_table WHERE registry_id = %d",
        $registry_id
    ));

    if (!$registry) {
        return '<p>Registry not found.</p>'; // Handle case where registry is not found
    }

    // Decode the JSON registry name
    $names = json_decode($registry->registry_name);

    // Determine if there are multiple names or a single name
    if (is_array($names) && count($names) > 1) {
        $formatted_names = implode(' and ', array_map('esc_html', $names));
    } else {
        $formatted_names = esc_html(is_array($names) ? $names[0] : $names);
    }

    $output = "<h3>{$formatted_names} baby Registry</h3>"; // Display the formatted registry name

    // Show delete button if the logged-in user is the owner of the registry
    if ($registry->user_id == get_current_user_id()) {
        $output .= '<button id="deleteRegistryButton" class="delete-registry-button" data-registry-id="' . esc_attr($registry_id) . '">Delete Registry</button>';
    }

    $registry_items_table = $wpdb->prefix . "baby_registry_items";
    $posts_table = $wpdb->prefix . "posts";

    // Get registry items
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_excerpt, pm.meta_value AS price
         FROM $registry_items_table ri
         JOIN $posts_table p ON p.ID = ri.product_id
         LEFT JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_price'
         WHERE ri.registry_id = %d AND ri.purchased = 0",
        $registry_id
    ));

    // Check if items exist
    if (!empty($items)) {
        $output .= "<ul>";
        foreach ($items as $product) {
            $price = wc_price($product->price); // Format price with WooCommerce settings

            // Get the product image URL
            $image_id = get_post_thumbnail_id($product->ID);
            $image_url = wp_get_attachment_url($image_id);
            $image_html = $image_url ? "<img src='{$image_url}' alt='{$product->post_title}' style='width:100px;' />" : 'No image available';

            $output .= "<li>";
            $output .= "{$image_html} <strong>" . esc_html($product->post_title) . "</strong> - " . esc_html($product->post_excerpt) . "<br>";
            $output .= "Price: {$price}";
            $output .= "</li>";
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
        // Handle the scenario where no registries are found
        return new WP_Error('no_registry_found', 'User has no registry.');
    }

    // Decode JSON data in registry_name for each registry and format names
    foreach ($registries as $registry) {
        if (!empty($registry->registry_name)) {
            // Assuming the registry_name is stored as a JSON string
            $decoded_names = json_decode($registry->registry_name, true);
            
            // Format names based on the count
            if (is_array($decoded_names)) {
                $formatted_names = count($decoded_names) === 2
                                   ? implode(' and ', $decoded_names)
                                   : $decoded_names[0];
            } else {
                // Handle non-array data or single name
                $formatted_names = $decoded_names;
            }

            // Append "Baby Registry" to the end of the formatted name
            $registry->registry_name = esc_html($formatted_names) . ' Baby Registry';
        }
    }

    return $registries;
}


/**
 * initialize registry image
 */

function registry_image($baby_room) {
    // Static list of image URLs
    $images = array(
        plugin_dir_url(__FILE__) . 'public/images/0.jpg',
        plugin_dir_url(__FILE__) . 'public/images/1.jpg',
        plugin_dir_url(__FILE__) . 'public/images/2.jpg',
        plugin_dir_url(__FILE__) . 'public/images/3.jpg'
    );

    // Use baby_room to index images, ensure it cycles through the array length
    $index = abs($baby_room) % count($images);  // abs ensures the index is positive

    // Return the image URL
    return $images[$index];
}


/**
 * Display user registries Shortcode
 */

 function display_user_registries() {
    $registries = get_user_registries();

    if (empty($registries)) {
        return '<p>No registries found.</p>';
    }

    $output = '<ul class="user-registries">';
    foreach ($registries as $registry) {
        $image_url = registry_image($registry->baby_room);  // Get image URL based on baby_room
        $output .= '<li>';
        $output .= '<img src="' . esc_url($image_url) . '" alt="Registry Image" style="width:100px;height:auto;">';
        $output .= '<h3>' . esc_html($registry->registry_name) . '</h3>';
        $output .= '<p>Description: ' . esc_html($registry->registry_description) . '</p>';
        $output .= '<p>Due Date: ' . esc_html($registry->due_date) . '</p>';
        // Baby room info is not displayed
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
        echo '<select name="registry_id" class="registry-select">';
        foreach ($registries as $registry) {
            echo '<option value="' . esc_attr($registry->registry_id) . '">' . esc_html($registry->registry_name) . '</option>';
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

    // Ensure the user can still create new registries
    if (!can_create_new_registry($user_id)) {
        return new WP_Error('registry_limit_reached', 'You have reached the maximum number of registries allowed.');
    }

    // Validate input data
    if (empty($user_id) || empty($names)) {
        return new WP_Error('invalid_data', 'Invalid user ID or registry names.');
    }

    // Encode names into JSON and handle possible errors
    $names_json = json_encode($names);
    if ($names_json === false) {
        return new WP_Error('json_encoding_error', 'Failed to encode registry names.');
    }

    // Sanitize and validate input data
    $description = sanitize_textarea_field($description);
    $due_date = sanitize_text_field($due_date);
    // Optionally, validate due_date format here (e.g., using DateTime)
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
    $output = '';

    // Handle the POST request
    if (isset($_POST['submit_registry'])) {
        $user_id = get_current_user_id();
        if ($user_id) {
            // Collect names from multiple fields
            $names = [];
            for ($i = 1; $i <= 2; $i++) {
                if (!empty($_POST["registry_name$i"])) {
                    $names[] = trim($_POST["registry_name$i"]);
                }
            }
            
            // Collect additional form data
            $description = isset($_POST['registry_description']) ? $_POST['registry_description'] : '';
            $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : '';
            $baby_room = isset($_POST['baby_room']) ? intval($_POST['baby_room']) : 0;
    
            // Call create_baby_registry function
            $registry_creation_result = create_baby_registry($user_id, $names, $description, $due_date, $baby_room);

            // Check the result and provide feedback
            if (is_wp_error($registry_creation_result)) {
                $output .= '<p>Error: ' . $registry_creation_result->get_error_message() . '</p>';
            } else {
                $output .= '<p>Registry created successfully! Registry ID: ' . $registry_creation_result . '</p>';
            }
        } else {
            $output .= '<p>You must be logged in to create a registry.</p>';
        }
    }

    // Generate the form HTML
    $output .= '<form action="" method="post">';
    $output .= '<label for="registry_name1">First Parent Name:</label>';
    $output .= '<input type="text" id="registry_name1" name="registry_name1" placeholder="Enter Name" required>';
    $output .= '<label for="registry_name2">Second Parent Name:</label>';
    $output .= '<input type="text" id="registry_name2" name="registry_name2" placeholder="Enter Name">';
    $output .= '<label for="registry_description">Description:</label>';
    $output .= '<textarea id="registry_description" name="registry_description" placeholder="Enter Registry Description"></textarea>';
    $output .= '<label for="due_date">Due Date:</label>';
    $output .= '<input type="date" id="due_date" name="due_date">';
    $output .= '<label for="baby_room">Baby Room Number:</label>';
    $output .= '<input type="number" id="baby_room" name="baby_room" placeholder="Enter Baby Room Number">';
    $output .= '<input type="submit" name="submit_registry" value="Create Registry">';
    $output .= '</form>';

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
