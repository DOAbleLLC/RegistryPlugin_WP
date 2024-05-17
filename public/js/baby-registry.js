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
            url: babyRegistryParams.ajaxurl, // Ensure ajaxurl is defined
            type: 'POST',
            data: {
                action: 'remove_from_registry_ajax',
                product_id: productId,
                registry_id: registryId,
                _ajax_nonce: babyRegistryParams.nonce // Use an actual nonce field as needed for security
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
    // Function to copy text to clipboard
    function copyToClipboard(text) {
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();
        alert('Address copied to clipboard!');
    }

    // Function to create the share URL
    function createShareUrl(redirectUrl, registryId) {
        var url = new URL(redirectUrl);
        url.searchParams.set('registry_id', registryId);
        return url.toString();
    }

    // Attach the click event to the share button
    $('.registry-item-share-button').on('click', function() {
        var redirectUrl = $(this).data('redirect-url');
        var registryId = $(this).data('registry-id');
        var shareUrl = createShareUrl(redirectUrl, registryId);
        console.log("Generated URL:", shareUrl);  // Debugging line
        copyToClipboard(shareUrl);
    });

    // Attach the click event to the copy address button
    $('#copyAddressButton').on('click', function() {
        var address = $(this).data('address');
        console.log("Copying address:", address);  // Debugging line
        copyToClipboard(address);
    });

    // Existing form submission code
    $('.registry-item-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var registryId = $form.data('registry-id');
        var productId = $form.data('product-id');
        var purchasedAmount = $form.find('input[name="purchased_amount"]').val();

        $.ajax({
            url: babyRegistryParams.ajaxurl,
            method: 'POST',
            data: {
                action: 'update_registry_item',
                security: babyRegistryParams.nonce,
                registry_id: registryId,
                product_id: productId,
                purchased_amount: purchasedAmount
            },
            success: function(response) {
                if (response.success) {
                    var quantityNeeded = response.data.quantity_needed;
                    $form.closest('.registry-item-details').find('.quantity-needed-text').text(quantityNeeded);
                    alert('Quantity updated successfully!');
                } else {
                    console.log('Error:', response.data);
                    alert('Error: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', error);
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