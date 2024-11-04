(function ($, Drupal, drupalSettings) {

  'use strict';

  Drupal.behaviors.gcsCorsAutoUpload = {

    attach: function (context, settings) {
      // Detach default Drupal file auto upload behavior from any gcs cors file input elements.
      const removedElements = once.remove('auto-file-upload', '.gcs-cors-file input[type="file"], input.gcs-cors-upload', context);
      $(removedElements).each(function () {
        $(this).off('.autoFileUpload')
      });

      // Attach the custom gcs cors auto upload processing behavior.
      $(once('gcs-cors-auto-upload', '.gcs-cors-file input[type="file"], input.gcs-cors-upload', context)).on('change.gcsCorsAutoUpload', {
        settings: settings.gcs_flysystem_cors,
        baseUrl: settings.path.baseUrl
      }, Drupal.gcsCors.triggerUploadButton);
    },
    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        const removedElements = once.remove('gcs-cors-auto-upload', '.gcs-cors-file input[type="file"]', context);
        $(removedElements).each(function () {
          $(this).off('.gcsCorsAutoUpload');
        });
      }
    }
  };

  Drupal.gcsCors = Drupal.gcsCors || {

    triggerUploadButton: function (event) {

      var file_input = $('input#' + event.target.id);
      var form = file_input.closest('form');
      form.find(':input[type="submit"]').attr('disabled', 'disabled');
      form.find('.loader').removeClass('js-hide');

      // Get the filelist and the number of files to be uploaded.
      var filelist = file_input[0].files;
      var file_input = $('input#' + event.target.id);
      var form = file_input.closest('form');

      form.find(':input[type="submit"]').attr('disabled', 'disabled');

      var target_id = event.target.id;
      if (target_id.indexOf('--') >= 0) {
        target_id = target_id.split('--')[0];
      }
      var field_name = target_id.split('-');
      var field_name_array = field_name.slice(1, field_name.length - 2);
      var delta = field_name[field_name.length - 2];
      var field_name_key = field_name_array.join('_');
      var settings = event.data.settings[field_name_key];
      var entity_type = settings.entity_type
      var bundle = settings.bundle
      var entity_id = settings.entity_id

      var baseUrl = event.data.baseUrl;

      // Get the filelist and the number of files to be uploaded.
      var filelist = file_input[0].files;
      var num_files = filelist.length;

      // Process each specified file.
      for (var delta = 0; delta < num_files; delta++) {

        var file_obj = filelist[delta];
        var ajax_uri = baseUrl + 'ajax/gcs/' + entity_type + '/' + bundle + '/' + entity_id + '/' + field_name_key + '/' + delta + '/' + encodeURIComponent(file_obj.name);
        $.get({
          url: ajax_uri,
          success: function (r) {
            var fd = new FormData();
            $.each(r['fields'], function (key, value) {
              fd.append(key, value);
            });
            fd.append('file', file_obj);

            $.ajax({
              url: r['url'],
              type: 'POST',
              enctype: 'multipart/form-data',
              data: fd,
              cache: false,
              contentType: false,
              processData: false,
              crossDomain: true,
              success: function(r2) {
                var save_file_uri = baseUrl + 'ajax/gcs/' + entity_type + '/' + bundle + '/' + entity_id + '/' + field_name_key + '/' + delta + '/' + encodeURIComponent(file_obj.name) + '/' + file_obj.size;
                $.get({
                  url: save_file_uri,
                  success: function(data) {
                    if (!data.fid) {
                      if (data.errmsg) {
                        alert(data.errmsg);
                      }
                      else{
                        alert('File couldn\'t be saved in Drupal');
                      }
                      return;
                    }

                    // Add the fid for this file to hidden fids field.
                    var fid = data.fid;
                    var fid_selector = target_id.replace(/upload$/, 'fids');

                    var fids = $('[data-drupal-selector=' + fid_selector + ']').val();
                    fids = (fids) ? fids + ' ' + fid : fid;
                    $('[data-drupal-selector=' + fid_selector + ']').val(fids);

                    // Post the results to Drupal if all files have been processed.
                    var num_fids = fids.split(' ').length;
                    if (num_fids == filelist.length) {
                      // Use the HTML5 FormData API to build a POST form to send to Drupal.
                      var fd = new FormData();
                      // Get the non-submit inputs for processing into FormData.
                      var inputs = form.find(':input').not('.js-form-submit');
                      inputs.each(function () {
                        if (this.name) {
                          fd.append(this.name, $(this).val());
                        }
                      });
                      // Get the relevant submit input into FormData.
                      var submits = form.find(':input.js-form-submit');
                      submits.each(function () {
                        if (this.name.substr(0, field_name_key.length) == field_name_key) {
                          fd.append('_triggering_element_name', this.name);
                          fd.append('_triggering_element_value', $(this).val());
                        }
                      })
                      // Add some additional required fields into Formdata.
                      fd.append('_drupal_ajax', 1);
                      fd.append('ajax_page_state[theme]', drupalSettings.ajaxPageState.theme);
                      fd.append('ajax_page_state[theme_token]', drupalSettings.ajaxPageState.theme_token);
                      fd.append('ajax_page_state[libraries]', drupalSettings.ajaxPageState.libraries);
                      // Calculate the post url to use.
                      var posturl = '?element_parents=' + settings.element_parents + '&ajax_form=1&_wrapper_format=drupal_ajax&';

                      // integrate with drupal/form_mode_control
                      // checking for ?display=foo and appending
                      // to AJAX request if so
                      var queryString = window.location.search.substring(1);
                      var queryParams = queryString.split('&');
                      for (var i = 0; i < queryParams.length; i++) {
                        var pair = queryParams[i].split('=');
                        if (decodeURIComponent(pair[0]) === "display") {
                          posturl += "display=" + decodeURIComponent(pair[1]);
                        }
                      }

                      // Generate and send an ajax request with the uploaded file details.
                      $.ajax({
                        url: posturl,
                        type: 'POST',
                        enctype: 'multipart/form-data',
                        data: fd,
                        cache: false,
                        contentType: false,
                        processData: false,
                        dataType: 'json',

                        success: function (response, status, xmlHttpRequest) {
                          // Set the relevant selector in any of the returned Ajax
                          // commands that have a null selector.
                          var responseLength = response.length;
                          for (var i = 0; i < responseLength; i++) {
                            var selector = response[i].selector;
                            if (selector === null) {
                              // Find the first descendant div with an id beginning with "ajax-wrapper".
                              var field_ajax_wrapper = $('div[data-drupal-selector="edit-' + field_name_key.replace(/_/g,'-') + '-wrapper"]');
                              do {
                                field_ajax_wrapper = field_ajax_wrapper.find('div:first-child');
                                var child_div_id = field_ajax_wrapper.prop('id');
                              }
                              while (child_div_id == '' || child_div_id.indexOf('ajax-wrapper') == -1);
                              response[i].selector = '#' + child_div_id;
                            }
                          }
                          // Create a Drupal.Ajax object without associating an
                          // element, a progress indicator or a URL.
                          var ajaxObject = Drupal.ajax({
                            url: posturl,
                            base: false,
                            element: false,
                            progress: false
                          });
                          // Then, simulate an AJAX response having arrived,
                          // and let the Ajax system handle it.
                          ajaxObject.success(response, status,xmlHttpRequest);
                          Drupal.attachBehaviors();

                          // Re-enable all the submit buttons in the form.
                          form.find(':input[type="submit"]').removeAttr('disabled');
                          form.find('.loader').addClass('js-hide');
                        },

                        error: function (xmlHttpRequest, status, errorThrown) {
                          alert('Error return from Drupal');
                        }
                      });
                    }
                  }
                });
              }
            });
          },
        });
      }
    },
  };

})(jQuery, Drupal, drupalSettings);
