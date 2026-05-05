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

      const fileInput = $('input#' + event.target.id);
      const form = fileInput.closest('form');
      form.find(':input[type="submit"]').attr('disabled', 'disabled');
      form.find('.loader').removeClass('js-hide');

      let targetId = event.target.id;
      if (targetId.indexOf('--') >= 0) {
        targetId = targetId.split('--')[0];
      }
      const fieldNameKey = event.target.dataset.gcsCorsFieldName;
      Drupal.gcsCors.uploadStatus = Drupal.gcsCors.uploadStatus || {};
      Drupal.gcsCors.uploadStatus[targetId] = {
        expected: fileInput[0].files.length,
        fids: []
      };
      const settings = (event.data.settings || {})[fieldNameKey];
      if (!settings) {
        Drupal.gcsCors.uploadStatus[targetId].error = 'Missing upload settings for ' + fieldNameKey;
        return;
      }
      const entityType = settings.entity_type;
      const bundle = settings.bundle;
      const entityId = settings.entity_id;
      const baseUrl = event.data.baseUrl;

      // Get the filelist and the number of files to be uploaded.
      const filelist = fileInput[0].files;
      const numFiles = filelist.length;

      // Process each specified file.
      for (let delta = 0; delta < numFiles; delta++) {
        const fileObj = filelist[delta];
        const ajaxUri = baseUrl + 'ajax/gcs/' + entityType + '/' + bundle + '/' + entityId + '/' + fieldNameKey + '/' + delta + '/' + encodeURIComponent(fileObj.name);
        $.get({
          url: ajaxUri,
          success: function (r) {
            const fd = new FormData();
            $.each(r['fields'], function (key, value) {
              fd.append(key, value);
            });
            fd.append('file', fileObj);

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
                const saveFileUri = baseUrl + 'ajax/gcs/' + entityType + '/' + bundle + '/' + entityId + '/' + fieldNameKey + '/' + delta + '/' + encodeURIComponent(fileObj.name) + '/' + fileObj.size;
                $.get({
                  url: saveFileUri,
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
                    const fid = data.fid;
                    Drupal.gcsCors.uploadStatus[targetId].fids.push(fid);
                    const fidSelector = targetId.replace(/upload$/, 'fids');

                    let fids = $('[data-drupal-selector=' + fidSelector + ']').val();
                    fids = (fids) ? fids + ' ' + fid : fid;
                    $('[data-drupal-selector=' + fidSelector + ']').val(fids);

                    // Post the results to Drupal if all files have been processed.
                    const numFids = fids.split(' ').length;
                    if (numFids == filelist.length) {
                      // Use the HTML5 FormData API to build a POST form to send to Drupal.
                      const fd = new FormData();
                      // Get the non-submit inputs for processing into FormData.
                      const inputs = form.find(':input').not('.js-form-submit');
                      inputs.each(function () {
                        if (this.name) {
                          fd.append(this.name, $(this).val());
                        }
                      });
                      // Get the relevant submit input into FormData.
                      const submits = form.find(':input.js-form-submit');
                      submits.each(function () {
                        if (this.name.substr(0, fieldNameKey.length) == fieldNameKey) {
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
                      let posturl = '?element_parents=' + settings.element_parents + '&ajax_form=1&_wrapper_format=drupal_ajax&';

                      // integrate with drupal/form_mode_control
                      // checking for ?display=foo and appending
                      // to AJAX request if so
                      const queryString = window.location.search.substring(1);
                      const queryParams = queryString.split('&');
                      for (let i = 0; i < queryParams.length; i++) {
                        const pair = queryParams[i].split('=');
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
                          const responseLength = response.length;
                          for (let i = 0; i < responseLength; i++) {
                            const selector = response[i].selector;
                            if (selector === null) {
                              // Find the first descendant div with an id beginning with "ajax-wrapper".
                              let fieldAjaxWrapper = $('div[data-drupal-selector="edit-' + fieldNameKey.replace(/_/g,'-') + '-wrapper"]');
                              let childDivId;
                              do {
                                fieldAjaxWrapper = fieldAjaxWrapper.find('div:first-child');
                                childDivId = fieldAjaxWrapper.prop('id');
                              }
                              while (childDivId == '' || childDivId.indexOf('ajax-wrapper') == -1);
                              response[i].selector = '#' + childDivId;
                            }
                          }
                          // Create a Drupal.Ajax object without associating an
                          // element, a progress indicator or a URL.
                          const ajaxObject = Drupal.ajax({
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
