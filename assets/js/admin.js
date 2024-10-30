jQuery(function($){

    $('.overlay').hide();

    $(document).on('click', '.brainity-facebook-login', function(){
        window.addEventListener("message", (obj) => {
            if (obj.data.hasOwnProperty('func') && obj.data.func === 'onmessage') {
                localStorage.setItem('default_auth_token', obj.data.message);
                var expires = (new Date((new Date()).getTime() + 12096e5)).toUTCString(),
                    cookie = 'rememberMe=true; Expires=' + expires + ';';

                document.cookie = cookie;
                var data = {
                    'action': 'brainity_ajax_update',
                    'jwt_token': obj.data.message      // We pass php values differently!
                };
                // We can also pass the url value separately from ajaxurl for front end AJAX implementations
                jQuery.post(ajaxurl, data, function(responseShop) {
                    if (responseShop['success'] === true) {
                        location.reload();
                    } else {
                        $('.overlay').hide();
                    }
                });
            }
        }, false);

        $('.overlay').show();
        var data = {
            'action': 'brainity_ajax_get_host'   // We pass php values differently!
        };
        jQuery.post(ajaxurl, data, function(response) {
            var jsonResponse = JSON.parse(response);
            if (jsonResponse['success'] === true) {
                window.open(jsonResponse['data'] + '/login/facebook');
            }
        });

    });
});
