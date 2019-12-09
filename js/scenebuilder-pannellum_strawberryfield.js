(function ($, Drupal, drupalSettings, pannellum) {



    function marker(event, $marker, $container) {
        var pos = mousePosition(event,$container);
        $marker.css("left", pos.x + 'px');
        $marker.css("top", pos.y + 'px');
        $marker.css("opacity", '1.0');
        $marker.css("display", 'block');
        $marker.fadeIn('slow');
        $marker.fadeOut('slow');
    }

    function loadExistingHotSpots($selector,$hotspots) {
        return null;
    }



    function mousePosition(event,$container) {
        var bounds = $container.getBoundingClientRect();
        var pos = {};
        // pageX / pageY needed for iOS
        pos.x = (event.clientX || event.pageX) - bounds.left;
        pos.y = (event.clientY || event.pageY) - bounds.top;
        return pos;
    }

    Drupal.AjaxCommands.prototype.webform_strawberryfield_pannellum_editor_addHotSpot = function(ajax, response, status) {
        console.log('adding hotspot');
        // we need to find the first  '.strawberry-panorama-item' id that is child of data-drupal-selector="edit-panorama-tour-hotspots"
        console.log(response.selector);
        $targetScene = $(response.selector).find('.strawberry-panorama-item').attr("id");
        console.log($targetScene);
        $scene = Drupal.FormatStrawberryfieldPanoramas.panoramas.get($targetScene);
        $scene.panorama.addHotSpot(response.hotspot);
    };


    Drupal.behaviors.webform_strawberryfield_pannellum_editor = {
        attach: function(context, settings) {
            $('.strawberry-panorama-item[data-iiif-image]').once('attache_pne')
                .each(function (index, value) {
                    var hotspots = [];
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    console.log('Checking for loaded Panoramatour builder Hotspots');
                    console.log(drupalSettings.webform_strawberryfield.WebformPanoramaTour);
                    for (var parentselector in drupalSettings.webform_strawberryfield.WebformPanoramaTour) {
                        if (Object.prototype.hasOwnProperty.call(drupalSettings.webform_strawberryfield.WebformPanoramaTour, parentselector)) {


                            $targetScene = $("[data-webform_strawberryfield-selector='" + parentselector + "']").find('.strawberry-panorama-item').attr("id");
                            console.log(parentselector);
                            console.log($targetScene);
                            $scene = Drupal.FormatStrawberryfieldPanoramas.panoramas.get($targetScene);
                            if ((typeof $scene !== 'undefined')) {
                                drupalSettings.webform_strawberryfield.WebformPanoramaTour[parentselector].forEach(function(hotspot, key)
                                {
                                    console.log(hotspot);
                                    console.log($scene);
                                    $scene.panorama.addHotSpot(hotspot);
                                });
                            }
                        }
                    }


                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.pannellum[element_id]) != 'undefined') {
                        console.log('initializing Panellum Panorama Builder')
                        console.log(Drupal.FormatStrawberryfieldPanoramas.panoramas);
                        Drupal.FormatStrawberryfieldPanoramas.panoramas.forEach(function(item, key) {

                            var element_id_marker = element_id + '_marker';
                            var $newmarker = $( "<div class='hotspot_marker_wrapper'><div class='hotspot_editor_marker' id='" + element_id_marker +"'></div></div>");

                            $("#" +element_id+ " .pnlm-ui").append( $newmarker );
                            // Feed with existing Hotspots first




                            item.panorama.on('mousedown', function clicker(e) {

                                $hotspot_cors = item.panorama.mouseEventToCoords(e);
                                var $jquerycontainer = $(item.panorama.getContainer());

                                $button_container = $jquerycontainer.closest("[data-drupal-selector='edit-panorama-tour-hotspots-temp']");

                                $button_container.find("[  data-drupal-hotspot-property='yaw']").val($hotspot_cors[1]);
                                $button_container.find("[  data-drupal-hotspot-property='pitch']").val($hotspot_cors[0]);

                                marker(e,$newmarker,item.panorama.getContainer());

                                }
                            );
                        });
                    }

                })}}

})(jQuery, Drupal, drupalSettings, window.pannellum);
