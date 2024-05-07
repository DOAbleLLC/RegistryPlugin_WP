jQuery(document).ready(function($) {
    $('.add-to-registry-button').on('click', function() {
        var registryId = $('#registry-select').val();
        var productId = $(this).data('product-id');
        var quantity = $('#quantity-input').val();

        $.ajax({
            url: babyRegistryParams.ajaxurl,  // Using localized script parameters
            method: 'POST',
            data: {
                action: 'add_to_registry_ajax',
                registry_id: registryId,
                product_id: productId,
                quantity: quantity,
                _ajax_nonce: babyRegistryParams.nonce  // Using nonce for security
            },
            success: function(response) {
                if (response.success) {
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

