<?php
/*
Plugin Name: WooCommerce Baby Registry
Plugin URI: https://github.com/DOAbleLLC/RegistryPlugin_WP
Description: Enables a baby registry feature for WooCommerce stores.
Version: 1.2
Author: Psyscho bit
This plugin was styled using the Astra WP theme 
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
        registry_url VARCHAR(255),
        thumbnail_url VARCHAR(255),
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

    $category_condition = "";
    if (!empty($category_ids)) {
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $category_condition = "AND tt.term_id IN ($placeholders)";
    }

    // Extend the SELECT clause to fetch more details
    $query = $wpdb->prepare("
        SELECT DISTINCT p.ID, p.post_title, p.post_content, p.guid, tt.term_id
        FROM {$wpdb->prefix}posts AS p
        JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
        JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$wpdb->prefix}baby_registry_items AS bri ON p.ID = bri.product_id
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND bri.registry_id = %d
        $category_condition",
        array_merge([$registry_id], $category_ids)
    );

    $products = $wpdb->get_results($query);

    if (is_wp_error($products) || empty($products)) {
        return new WP_Error('no_products', 'No products found for this registry.');
    }

    return array_map(function($product) {
        $wc_product = wc_get_product($product->ID);
        if (!$wc_product) return false;
        return [
            'id' => $wc_product->get_id(),
            'name' => $wc_product->get_name(),
            'description' => $wc_product->get_description(),
            'price' => $wc_product->get_price(),
            'imageUrl' => wp_get_attachment_url($wc_product->get_image_id()),
            'categories' => wp_get_post_terms($product->ID, 'product_cat', ['fields' => 'names'])
        ];
    }, $products);
}



/**
 * Display the registry 
 */

 function display_baby_registry($atts) {
    global $wpdb;

    // Set defaults and extract shortcode attributes
    $attributes = shortcode_atts([
        'registry_id' => 0,
        'category_filters' => ''
    ], $atts);

    // Override shortcode attributes with URL query parameters if they exist
    $registry_id = empty($_GET['registry_id']) ? intval($attributes['registry_id']) : intval($_GET['registry_id']);
    $category_filters_from_url = isset($_GET['category_filters']) ? explode(',', sanitize_text_field($_GET['category_filters'])) : [];
    $category_filters_from_atts = explode(',', sanitize_text_field($attributes['category_filters']));
    $category_filters = array_filter(array_unique(array_merge($category_filters_from_atts, $category_filters_from_url)));


    // Fetch registry details
    $registry_details_table = $wpdb->prefix . "baby_registry_details";
    $registry = $wpdb->get_row($wpdb->prepare(
        "SELECT registry_name, user_id FROM $registry_details_table WHERE registry_id = %d",
        $registry_id
    ));

    if (!$registry) {
        return '<p>No Registry found.</p>';
    }

    // Get user shipping address from WooCommerce
    $user_id = $registry->user_id;
    $shipping_address1 = get_user_meta($user_id, 'shipping_address_1', true);
    $shipping_address2 = get_user_meta($user_id, 'shipping_address_2', true); // Additional address line
    $shipping_city = get_user_meta($user_id, 'shipping_city', true);
    $shipping_state = get_user_meta($user_id, 'shipping_state', true);
    $shipping_postcode = get_user_meta($user_id, 'shipping_postcode', true);
    $shipping_country = get_user_meta($user_id, 'shipping_country', true);

    $shipping_address = trim($shipping_address1 . ' ' . $shipping_address2) . ", " . $shipping_city . ", " . $shipping_state . ", " . $shipping_postcode . ", " . $shipping_country;

    $names = json_decode($registry->registry_name);
    $formatted_names = is_array($names) ? implode(' and ', array_map('esc_html', $names)) : esc_html($names);
    $output = "<h3 class='registry-title'>{$formatted_names}'s Baby Registry</h3>";

    // Delete entire registry button if current user is the owner
    if (is_user_logged_in() && $registry->user_id == get_current_user_id()) {
        $output .= '<button id="deleteRegistryButton" class="delete-registry-button" data-registry-id="' . esc_attr($registry_id) . '">Delete Entire Registry</button>';
    }

    // Copy address button
    $output .= '<div class="copy-address-container"><button id="copyAddressButton" class="copy-address-button" data-address="' . esc_attr($shipping_address) . '">Copy Shipping Address</button></div>';

    // Build the query to get registry items
    $registry_items_table = $wpdb->prefix . "baby_registry_items";
    $query = $wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_excerpt, pm.meta_value AS price, pm2.meta_value AS external_url, ri.quantity, ri.purchased_quantity
         FROM $registry_items_table ri
         JOIN {$wpdb->prefix}posts p ON p.ID = ri.product_id
         LEFT JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_price'
         LEFT JOIN {$wpdb->prefix}postmeta pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_product_url'
         WHERE ri.registry_id = %d",
        $registry_id
    );

    if (!empty($category_filters)) {
        $placeholders = implode(', ', array_fill(0, count($category_filters), '%s'));
        $query .= $wpdb->prepare(" AND p.ID IN (SELECT object_id FROM {$wpdb->prefix}term_relationships tr JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_id IN ($placeholders))", $category_filters);
    }

    $items = $wpdb->get_results($query);

    // Display the items in a grid
    if ($items) {
        $output .= "<ul class='registry-item-grid'>";
        foreach ($items as $product) {
            $price = wc_price($product->price);
            $image_url = wp_get_attachment_url(get_post_thumbnail_id($product->ID));
            $image_html = $image_url ? "<img src='{$image_url}' alt='" . esc_attr($product->post_title) . "' class='registry-item-image' />" : '';
            $quantity_needed = max(0, $product->quantity - $product->purchased_quantity);
            $external_url = get_post_meta($product->ID, '_product_url', true); // Get external URL

            $output .= "<li class='registry-item'>
                          <div class='registry-item-content'>
                            {$image_html}
                            <div class='registry-item-details'>
                              <h4 class='registry-item-title'>" . esc_html($product->post_title) . "</h4>
                              <p class='registry-item-price'>Price: {$price}</p>
                              <p class='registry-item-needed'>Needs: <span class='quantity-needed-text'>{$quantity_needed}</span></p>
                              <form action='' method='post' class='registry-item-form' data-registry-id='" . esc_attr($registry_id) . "' data-product-id='" . esc_attr($product->ID) . "'>
                                " . wp_nonce_field('update_registry_item_nonce', '_wpnonce', true, false) . "
                                <label for='purchased_amount' class='registry-item-label'>Purchased: </label>
                                <input type='number' name='purchased_amount' min='1' max='{$quantity_needed}' placeholder='1' class='registry-item-input'>
                                <input type='submit' value='Update' class='baby-registry-form'>
                              </form>";

            if (!empty($external_url)) {
                 $output .= "<button class='affiliate-link-button' onclick=\"window.open('" . esc_url($external_url) . "', '_blank')\">Buy Now</button>";
            }

            if (is_user_logged_in() && $registry->user_id == get_current_user_id()) {
                $output .= '<button class="remove-from-registry-button" data-product-id="' . esc_attr($product->ID) . '" data-registry-id="' . esc_attr($registry_id) . '">Remove from Registry</button>';
            }


            $output .= "</div></div></li>";
        }
        $output .= "</ul>";
    } else {
        $output .= "<p>No products found in this registry.</p>";
    }

    return $output;
}
add_shortcode('baby_registry', 'display_baby_registry');




/**
 * update registry items
 */

 function update_registry_item_ajax() {
    global $wpdb;

    // Check for nonce security
    if (!check_ajax_referer('baby_registry_nonce', 'security', false)) {
        wp_send_json_error('Nonce verification failed.');
        return;
    }

    // Retrieve posted data
    $registry_id = isset($_POST['registry_id']) ? intval($_POST['registry_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $purchased_amount = isset($_POST['purchased_amount']) ? intval($_POST['purchased_amount']) : 0;

    if ($registry_id <= 0 || $product_id <= 0 || $purchased_amount <= 0) {
        wp_send_json_error('Invalid parameters provided.');
        return;
    }

    // Get current purchased quantity and quantity needed
    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT quantity, purchased_quantity FROM {$wpdb->prefix}baby_registry_items WHERE registry_id = %d AND product_id = %d",
        $registry_id, $product_id
    ));

    if (!$item) {
        wp_send_json_error('Item not found in the registry.');
        return;
    }

    $new_purchased_quantity = $item->purchased_quantity + $purchased_amount;
    if ($new_purchased_quantity > $item->quantity) {
        wp_send_json_error('Purchased quantity exceeds required quantity.');
        return;
    }

    // Update purchased quantity in the database
    $updated = $wpdb->update(
        "{$wpdb->prefix}baby_registry_items",
        ['purchased_quantity' => $new_purchased_quantity],
        ['registry_id' => $registry_id, 'product_id' => $product_id],
        ['%d'],
        ['%d', '%d']
    );

    if ($updated === false) {
        wp_send_json_error('Failed to update registry item.');
        return;
    }

    // Update the items_purchased in the baby_registry_details table
    $registry_update = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}baby_registry_details
         SET items_purchased = items_purchased + %d
         WHERE registry_id = %d",
        $purchased_amount, $registry_id
    ));

    if ($registry_update === false) {
        wp_send_json_error('Failed to update registry details.');
        return;
    }

    $quantity_needed = $item->quantity - $new_purchased_quantity;

    wp_send_json_success(['quantity_needed' => $quantity_needed]);
}
add_action('wp_ajax_update_registry_item', 'update_registry_item_ajax');
add_action('wp_ajax_nopriv_update_registry_item', 'update_registry_item_ajax'); // If non-logged-in users should be able to perform this action




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
            $registry->registry_name = esc_html($formatted_names) . "'s Baby Registry";
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
        // Use thumbnail_url if it's not empty, otherwise use registry_image function
        $image_url = !empty($registry->thumbnail_url) ? $registry->thumbnail_url : registry_image($registry->baby_room);


        // Check if registry_url is not empty and use it if available
        if (!empty($registry->registry_url)) {
            // Directly use the registry_url as redirect_url
            $redirect_url = $registry->registry_url;
        } else {
            // Define URL options based on baby_room
            $redirect_url = $registry->baby_room == 2 ? 'https://metazone.store/?page_id=636' : 'https://metazone.store/?page_id=636';
        }

        // Construct the final URL by adding query arguments for registry_id and redirect_url
        $url = add_query_arg([
            'registry_id' => $registry->registry_id,
            'redirect_url' => urlencode($redirect_url)
        ], 'https://metazone.store/wp-content/plugins/babyregistry/Metazone_registry/app-files/index.html');

        $output .= '<li class="registry-item">';
        $output .= '<img src="' . esc_url($image_url) . '" alt="Registry Image" class="registry-item-image">';
        $output .= '<h3 class="registry-item-title">' . esc_html($registry->registry_name) . '</h3>';
        $output .= '<p class="registry-item-description">Description: ' . esc_html($registry->registry_description) . '</p>';
        $output .= '<p class="registry-item-due-date">Due Date: ' . esc_html($registry->due_date) . '</p>';
        $output .= '<div class="button-container">';
        $output .= '<button class="button registry-item-button styled-button" onclick="window.location.href=\'' . esc_url($url) . '\'">Enter Registry</button>';
        $output .= '<button class="button registry-item-share-button styled-button" data-redirect-url="' . esc_url($redirect_url) . '" data-registry-id="' . esc_attr($registry->registry_id) . '">Share Registry</button>';
        $output .= '</div>';
        $output .= '</li>';
    }

    if (is_wp_error($registries)) {
        return '<p>Error: ' . esc_html($registries->get_error_message()) . '</p>';
    }

    $output .= '</ul>';

    return $output;
}

add_shortcode('user_registries', 'display_user_registries');



/**
 * Adds a 'Get Registry items handler'
 */


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
        echo '<select id="registry-select" name="registry_id" class="registry-select styled-select">';
        foreach ($registries as $registry) {
            echo '<option value="' . esc_attr($registry->registry_id) . '">' . esc_html($registry->registry_name) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<p class="no-registries">No registries available. Please create one first.</p>';
    }

    // Quantity input
    echo '<input type="number" id="quantity-input" name="quantity" class="registry-quantity-input styled-quantity-input" value="1" min="1">';
    
    // Add to registry button
    echo '<button type="button" class="add-to-registry-button styled-add-button" data-product-id="' . esc_attr($product->get_id()) . '">Add to Registry</button>';
    
    // End form
    echo '</form>';
}
add_action('woocommerce_after_shop_loop_item', 'add_registry_button_on_product', 20);


/**
 * AJAX handler for adding items to the registry.
 */
function add_to_registry_ajax() {
    global $wpdb;  // Access the global database object

    // Check for nonce security
    if (!check_ajax_referer('baby_registry_nonce', '_ajax_nonce', false)) {
        wp_send_json_error('Nonce verification failed.');
        return;
    }

    $registry_id = isset($_POST['registry_id']) ? intval($_POST['registry_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

    if ($registry_id <= 0) {
        wp_send_json_error('Invalid registry specified.');
        return;
    }

    if ($product_id <= 0 || $quantity <= 0) {
        wp_send_json_error('Invalid product or quantity specified.');
        return;
    }

    // Begin a database transaction
    $wpdb->query('START TRANSACTION');

    // Check if the product already exists in the registry
    $existing_product = $wpdb->get_row($wpdb->prepare(
        "SELECT quantity FROM {$wpdb->prefix}baby_registry_items WHERE registry_id = %d AND product_id = %d",
        $registry_id, $product_id
    ));

    if ($existing_product) {
        // Product exists, update the quantity
        $new_quantity = $existing_product->quantity + $quantity;
        $update_result = $wpdb->update(
            "{$wpdb->prefix}baby_registry_items",
            ['quantity' => $new_quantity],
            ['registry_id' => $registry_id, 'product_id' => $product_id],
            ['%d'], // format for new quantity
            ['%d', '%d'] // format for where clause
        );

        if ($update_result === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Failed to update item quantity in the registry.');
            return;
        }
    } else {
        // Product does not exist, insert new entry
        $insert_result = $wpdb->insert(
            "{$wpdb->prefix}baby_registry_items",
            [
                'registry_id' => $registry_id,
                'product_id' => $product_id,
                'quantity' => $quantity,
                'purchased' => 0,
                'purchased_quantity' => 0
            ],
            ['%d', '%d', '%d', '%d', '%d']
        );

        if ($insert_result === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Failed to add item to the registry database.');
            return;
        }

        // Update items_count in the baby_registry_details table only if a new item is added
        $update_count_result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}baby_registry_details SET items_count = items_count + %d WHERE registry_id = %d",
            $quantity,  // Increment by the quantity added
            $registry_id
        ));

        if ($update_count_result === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Failed to update items count.');
            return;
        }
    }

    // If all operations are successful, commit the transaction
    $wpdb->query('COMMIT');
    wp_send_json_success('Item successfully added or updated in registry.');
}
add_action('wp_ajax_add_to_registry_ajax', 'add_to_registry_ajax');



/**
 * AJAX handler for removing items from the registry.
 */

function remove_from_registry_ajax() {
    global $wpdb;  // Access the global database object

    // Check for nonce security
    if (!check_ajax_referer('baby_registry_nonce', '_ajax_nonce', false)) {
        wp_send_json_error('Nonce verification failed.');
        return;
    }

    $registry_id = isset($_POST['registry_id']) ? intval($_POST['registry_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if ($registry_id <= 0) {
        wp_send_json_error('Invalid registry specified.');
        return;
    }

    if ($product_id <= 0) {
        wp_send_json_error('Invalid product specified.');
        return;
    }

    // Begin a database transaction
    $wpdb->query('START TRANSACTION');

    // First, decrement item_count or delete if necessary
    $table_name_items = $wpdb->prefix . 'baby_registry_items';
    $current_item = $wpdb->get_row($wpdb->prepare(
        "SELECT quantity FROM $table_name_items WHERE registry_id = %d AND product_id = %d",
        $registry_id, $product_id
    ));

    if (!$current_item || $current_item->quantity <= 1) {
        // If quantity is 1 or less, delete the item
        $delete_result = $wpdb->delete($table_name_items, [
            'registry_id' => $registry_id,
            'product_id' => $product_id
        ], [
            '%d', '%d'
        ]);

        if ($delete_result === false) {
            $wpdb->query('ROLLBACK'); // Rollback the transaction on error
            wp_send_json_error('Failed to remove item from the registry database.');
            return;
        }
    } else {
        // Decrement the quantity by one
        $update_result = $wpdb->update(
            $table_name_items,
            ['quantity' => $current_item->quantity - 1], // Decrement the quantity
            ['registry_id' => $registry_id, 'product_id' => $product_id],
            ['%d'], // value format
            ['%d', '%d'] // where format
        );

        if ($update_result === false) {
            $wpdb->query('ROLLBACK'); // Rollback the transaction on error
            wp_send_json_error('Failed to decrement item quantity.');
            return;
        }
    }

    // Update items_count in the baby_registry_details table
    $update_count_result = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}baby_registry_details SET items_count = items_count - 1 WHERE registry_id = %d AND items_count > 0",
        $registry_id
    ));

    if ($update_count_result === false) {
        $wpdb->query('ROLLBACK'); // Rollback the transaction on error
        wp_send_json_error('Failed to update items count.');
        return;
    }

    // If all operations are successful, commit the transaction
    $wpdb->query('COMMIT');
    wp_send_json_success('Item successfully updated in registry.');
}
add_action('wp_ajax_remove_from_registry_ajax', 'remove_from_registry_ajax');
// add_action('wp_ajax_nopriv_remove_from_registry_ajax', 'remove_from_registry_ajax'); // if needed, allow non-logged in users to execute action




/**
 * Enqueue scripts and styles, include AJAX script.
 */
function baby_registry_scripts() {
    // Enqueue jQuery which is already registered with WordPress
    wp_enqueue_script('jquery');
    
    wp_enqueue_script('baby-registry-js', plugin_dir_url(__FILE__) . 'public/js/baby-registry.js', array('jquery'), null, true);

    wp_localize_script('baby-registry-js', 'babyRegistryParams', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('baby_registry_nonce')
    ));

    // Enqueue the CSS file
    wp_enqueue_style('baby-registry-css', plugin_dir_url(__FILE__) . 'public/css/baby-registry.css');
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
    return 'Navigate to the registry page to view your registies';
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
                $output .= '<p>Registry created successfully! ' . $registry_creation_result . '</p>';
                if ($baby_room == 2) { // Check if the third option was selected
                    $output .= '<script>window.location.href = "https://metazone.store/?page_id=685";</script>'; // Redirect using JavaScript
                }
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

    // Dropdown for baby_room
    $output .= '<label for="baby_room">Baby Room Number:</label>';
    $output .= '<select id="baby_room" name="baby_room" class="styled-select">';
    $output .= '<option value="0">Room 1 - Modern Theme</option>';
    $output .= '<option value="1">Room 2 - Traditional Theme</option>';
    $output .= '<option value="2">Room 3 - Design a custom room</option>';
    $output .= '</select>';

    $output .= '<input type="submit" name="submit_registry" value="Create Registry" class="styled-submit styled-add-button">';
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'baby_registry_nonce')) {
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
