console.log("Script loaded");

jQuery(document).ready(function($) {
    $('.add-to-registry-button').on('click', function() {
        var registryId = $('#registry-select').val();
        var productId = $(this).data('product-id');
        var quantity = $('#quantity-input').val();

        $.ajax({
            url: babyRegistryParams.ajaxurl,
            method: 'POST',
            data: {
                action: 'add_to_registry_ajax',
                registry_id: registryId,
                product_id: productId,
                quantity: quantity,
                _ajax_nonce: babyRegistryParams.nonce  // Using nonce for security
            },
            success: function(response) {
                if(response.success) {
                    alert('Product added to the registry!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to process the request.');
            }
        });
    });
});

jQuery(document).ready(function($) {
    $('.remove-from-registry-button').click(function() {
        var productId = $(this).data('product-id');
        var registryId = $(this).data('registry-id'); // Added to capture the registry ID

        $.ajax({
            url: ajaxurl, // Ensure ajaxurl is defined
            type: 'POST',
            data: {
                action: 'remove_from_registry_ajax',
                product_id: productId,
                registry_id: registryId,
                _ajax_nonce: $('#_ajax_nonce').val() // Use an actual nonce field as needed for security
            },
            success: function(response) {
                if (response.success) {
                    alert('Item removed successfully');
                    // Optionally remove the item from the DOM or refresh the page
                } else {
                    alert('Failed to remove item: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                // Handle errors
                alert('An error occurred: ' + error);
            }
        });
    });
});

jQuery(document).ready(function($) {
    $('.registry-item-grid').on('submit', 'form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var formData = {
            'action': 'update_registry_item',
            'security': $form.find('#_wpnonce').val(),  // Assuming a nonce field is included in the form
            'registry_id': $form.data('registry-id'),
            'product_id': $form.data('product-id'),
            'purchased_amount': $form.find('input[name="purchased_amount"]').val()
        };

        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                alert('Quantity updated successfully!');
                // Update the UI to reflect the new quantity
            } else {
                alert('Error: ' + response.data);
            }
        });
    });
});



jQuery(document).ready(function($) {
    $(document).on('click', '.delete-registry-button', function() {
        var registryId = $(this).data('registry-id');
        if (confirm("Are you sure you want to delete this registry?")) {
            deleteRegistry(registryId);
        }
    });
});

function deleteRegistry(registryId) {
    jQuery.ajax({
        url: babyRegistryParams.ajaxurl, // Make sure this is defined via wp_localize_script
        type: 'POST',
        data: {
            action: 'delete_baby_registry_ajax',
            registry_id: registryId,
            nonce: babyRegistryParams.nonce  // Ensure nonce is sent for security
        },
        success: function(response) {
            if (response.success) {
                alert('Registry deleted successfully.');
                window.location.reload();  // Optionally redirect or update the UI dynamically
            } else {
                alert('Failed to delete registry: ' + response.data.message);
            }
        },
        error: function() {
            alert('Failed to process the request.');
        }
    });
}