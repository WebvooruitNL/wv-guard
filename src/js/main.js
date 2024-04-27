jQuery(document).ready(function($) {
    $('.activate-license').on('click', function(e) {
        e.preventDefault();

        var licenseContainer = $(this).parent().parent();
        let license =  licenseContainer.find('.license');
        let activate_license = licenseContainer.find('.activate-license');

        var data = {
            'nonce' : licenseContainer.data('nonce'),
            'license_key' : license.val(),
            'plugin_slug' : licenseContainer.data('plugin_slug'),
            'action' : 'wv_activate_license'
        };

        // disable text field
        license.prop('disabled', true);
        activate_license.prop('disabled', true);

        $.ajax({
            url: WP_PackageUpdater.ajax_url,
            data: data,
            type: 'POST',
            success: function(response) {
                let message_container = licenseContainer.find('.license-message');
                let message  = response.data.length > 0 ? response.data[0].message : response.data.message;

                license.prop('disabled', false);
                activate_license.prop('disabled', false);

                message_container.show();
                message_container.html(message);

                if (response.success) {
                    $('.license-form').hide();
                    location.reload();
                }
            },
        });
    });
});