(function ($, Drupal, drupalSettings) {

  'use strict';

  const DEFAULT_RESUMABLE_UPLOAD_THRESHOLD = 5 * 1024 * 1024 * 1024;
  const DEFAULT_GCS_MAX_OBJECT_SIZE = 5 * 1024 * 1024 * 1024 * 1024;
  const DEFAULT_RESUMABLE_CHUNK_SIZE = 32 * 1024 * 1024;
  const RESUMABLE_MAX_RETRIES = 3;

  Drupal.behaviors.gcsCorsAutoUpload = {

    attach: function (context, settings) {
      // Detach default Drupal file auto upload behavior from any gcs cors file input elements.
      const removedElements = once.remove('auto-file-upload', '.gcs-cors-file input[type="file"], input.gcs-cors-upload', context);
      $(removedElements).each(function () {
        $(this).off('.autoFileUpload');
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

  Drupal.gcsCors = Drupal.gcsCors || {};

  $.extend(Drupal.gcsCors, {

    showUploadError: function (fileInput, message) {
      const wrapper = fileInput.closest('.gcs-cors-file');
      const form = fileInput.closest('form');
      Drupal.gcsCors.uploadStatus = Drupal.gcsCors.uploadStatus || {};
      let targetId = fileInput.attr('id') || '';
      if (targetId.indexOf('--') >= 0) {
        targetId = targetId.split('--')[0];
      }
      if (targetId) {
        Drupal.gcsCors.uploadStatus[targetId] = Drupal.gcsCors.uploadStatus[targetId] || {};
        Drupal.gcsCors.uploadStatus[targetId].error = message;
      }
      form.find(':input[type="submit"]').removeAttr('disabled');
      form.find('.loader').addClass('js-hide');
      wrapper.find('.gcs-cors-upload-error')
        .text(message)
        .removeClass('js-hide');
    },

    triggerUploadButton: function (event) {
      const fileInput = $('input#' + event.target.id);
      const form = fileInput.closest('form');
      form.find(':input[type="submit"]').attr('disabled', 'disabled');
      form.find('.loader').removeClass('js-hide');
      fileInput.closest('.gcs-cors-file').find('.gcs-cors-upload-error')
        .empty()
        .addClass('js-hide');

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
        Drupal.gcsCors.showUploadError(fileInput, 'Missing upload settings for ' + fieldNameKey);
        return;
      }

      const entityType = settings.entity_type;
      const bundle = settings.bundle;
      const entityId = settings.entity_id;
      const baseUrl = event.data.baseUrl;
      const routePrefix = baseUrl + 'ajax/gcs/' + entityType + '/' + bundle + '/';
      const entityPath = entityId ? entityId + '/' : '';
      const filelist = fileInput[0].files;

      for (let delta = 0; delta < filelist.length; delta++) {
        Drupal.gcsCors.processFileUpload({
          baseUrl: baseUrl,
          bundle: bundle,
          delta: delta,
          entityPath: entityPath,
          entityType: entityType,
          fieldNameKey: fieldNameKey,
          fileInput: fileInput,
          filelist: filelist,
          form: form,
          routePrefix: routePrefix,
          settings: settings,
          targetId: targetId
        });
      }
    },

    processFileUpload: function (context) {
      const fileObj = context.filelist[context.delta];
      if (fileObj.size > Drupal.gcsCors.getMaxUploadSize()) {
        Drupal.gcsCors.showUploadError(context.fileInput, Drupal.t('The selected file exceeds the configured upload size limit.'));
        return;
      }

      const ajaxUri = context.routePrefix + context.entityPath + context.fieldNameKey + '/' + context.delta + '/' + encodeURIComponent(fileObj.name) + '?file_size=' + encodeURIComponent(fileObj.size);
      $.get({
        url: ajaxUri,
        success: function (upload) {
          Drupal.gcsCors.uploadFileToGcs(upload, fileObj, function () {
            Drupal.gcsCors.saveUploadedFile(upload, fileObj, context);
          }, function (message) {
            Drupal.gcsCors.showUploadError(context.fileInput, message);
          });
        },
        error: function (xmlHttpRequest) {
          const data = xmlHttpRequest.responseJSON || {};
          Drupal.gcsCors.showUploadError(context.fileInput, data.errmsg || Drupal.t('Could not prepare the file upload.'));
        }
      });
    },

    uploadFileToGcs: function (upload, fileObj, success, failure) {
      if (fileObj.size > Drupal.gcsCors.getResumableUploadThreshold()) {
        if (!upload.resumable_url) {
          failure(Drupal.t('Could not prepare a large-file upload session.'));
          return;
        }
        Drupal.gcsCors.uploadFileWithResumableUrl(upload.resumable_url, fileObj, success, failure);
        return;
      }

      Drupal.gcsCors.uploadFileWithPostPolicy(upload, fileObj, success, failure);
    },

    uploadFileWithPostPolicy: function (upload, fileObj, success, failure) {
      const fd = new FormData();
      $.each(upload.fields, function (key, value) {
        fd.append(key, value);
      });
      fd.append('file', fileObj);

      $.ajax({
        url: upload.url,
        type: 'POST',
        enctype: 'multipart/form-data',
        data: fd,
        cache: false,
        contentType: false,
        processData: false,
        crossDomain: true,
        success: success,
        error: function () {
          failure(Drupal.t('The file could not be uploaded to cloud storage.'));
        }
      });
    },

    uploadFileWithResumableUrl: function (resumableUrl, fileObj, success, failure) {
      Drupal.gcsCors.startResumableUpload(resumableUrl, fileObj, function (sessionUri) {
        Drupal.gcsCors.uploadResumableChunk(sessionUri, fileObj, 0, success, failure, 0);
      }, failure);
    },

    startResumableUpload: function (resumableUrl, fileObj, success, failure) {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', resumableUrl, true);
      xhr.setRequestHeader('x-goog-resumable', 'start');
      if (fileObj.type) {
        xhr.setRequestHeader('Content-Type', fileObj.type);
      }

      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          const sessionUri = xhr.getResponseHeader('Location');
          if (sessionUri) {
            success(sessionUri);
          }
          else {
            failure(Drupal.t('Cloud storage did not return a large-file upload session.'));
          }
          return;
        }
        failure(Drupal.t('Could not start the large-file upload session.'));
      };

      xhr.onerror = function () {
        failure(Drupal.t('Could not start the large-file upload session.'));
      };

      xhr.send(null);
    },

    uploadResumableChunk: function (sessionUri, fileObj, start, success, failure, attempt) {
      if (start >= fileObj.size) {
        success();
        return;
      }

      const end = Math.min(start + Drupal.gcsCors.getResumableChunkSize(), fileObj.size) - 1;
      const chunk = fileObj.slice(start, end + 1);
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', sessionUri, true);
      xhr.setRequestHeader('Content-Range', 'bytes ' + start + '-' + end + '/' + fileObj.size);

      xhr.onload = function () {
        if (xhr.status === 308) {
          const range = xhr.getResponseHeader('Range');
          const nextStart = Drupal.gcsCors.getNextResumableOffset(range, end + 1);
          Drupal.gcsCors.uploadResumableChunk(sessionUri, fileObj, nextStart, success, failure, 0);
          return;
        }

        if (xhr.status >= 200 && xhr.status < 300) {
          success();
          return;
        }

        if ((xhr.status === 500 || xhr.status === 503) && attempt < RESUMABLE_MAX_RETRIES) {
          Drupal.gcsCors.retryResumableChunk(sessionUri, fileObj, start, success, failure, attempt);
          return;
        }

        failure(Drupal.t('The large file upload failed while sending a chunk.'));
      };

      xhr.onerror = function () {
        if (attempt < RESUMABLE_MAX_RETRIES) {
          Drupal.gcsCors.retryResumableChunk(sessionUri, fileObj, start, success, failure, attempt);
          return;
        }
        failure(Drupal.t('The large file upload failed while sending a chunk.'));
      };

      xhr.send(chunk);
    },

    retryResumableChunk: function (sessionUri, fileObj, start, success, failure, attempt) {
      window.setTimeout(function () {
        Drupal.gcsCors.uploadResumableChunk(sessionUri, fileObj, start, success, failure, attempt + 1);
      }, Math.pow(2, attempt) * 1000);
    },

    getNextResumableOffset: function (range, fallback) {
      const match = range && range.match(/bytes=0-(\d+)/);
      if (match) {
        return parseInt(match[1], 10) + 1;
      }
      return fallback;
    },

    getNumericUploadSetting: function (name, fallback) {
      const settings = drupalSettings.gcs_flysystem_cors || {};
      const value = Number(settings[name]);
      return Number.isFinite(value) && value > 0 ? value : fallback;
    },

    getMaxUploadSize: function () {
      return Drupal.gcsCors.getNumericUploadSetting('max_upload_size', DEFAULT_GCS_MAX_OBJECT_SIZE);
    },

    getResumableUploadThreshold: function () {
      return Drupal.gcsCors.getNumericUploadSetting('resumable_upload_threshold', DEFAULT_RESUMABLE_UPLOAD_THRESHOLD);
    },

    getResumableChunkSize: function () {
      return Drupal.gcsCors.getNumericUploadSetting('resumable_chunk_size', DEFAULT_RESUMABLE_CHUNK_SIZE);
    },

    saveUploadedFile: function (upload, fileObj, context) {
      const saveFileUri = context.routePrefix + context.entityPath + context.fieldNameKey + '/' + context.delta + '/' + encodeURIComponent(fileObj.name) + '/' + fileObj.size;
      $.get({
        url: context.baseUrl + 'session/token',
        success: function (csrfToken) {
          const saveData = new FormData();
          saveData.append('object_name', upload.object_name);
          $.ajax({
            url: saveFileUri,
            type: 'POST',
            headers: {
              'X-CSRF-Token': csrfToken
            },
            data: saveData,
            cache: false,
            contentType: false,
            processData: false,
            success: function (data) {
              if (!data.fid) {
                Drupal.gcsCors.showUploadError(context.fileInput, data.errmsg || Drupal.t('File could not be saved in Drupal.'));
                return;
              }

              Drupal.gcsCors.recordUploadedFile(data.fid, context);
            },
            error: function (xmlHttpRequest) {
              const data = xmlHttpRequest.responseJSON || {};
              Drupal.gcsCors.showUploadError(context.fileInput, data.errmsg || Drupal.t('File could not be saved in Drupal.'));
            }
          });
        },
        error: function () {
          Drupal.gcsCors.showUploadError(context.fileInput, Drupal.t('Could not get a Drupal session token for the uploaded file.'));
        }
      });
    },

    recordUploadedFile: function (fid, context) {
      Drupal.gcsCors.uploadStatus[context.targetId].fids.push(fid);
      const fidSelector = context.targetId.replace(/upload$/, 'fids');

      let fids = $('[data-drupal-selector=' + fidSelector + ']').val();
      fids = (fids) ? fids + ' ' + fid : fid;
      $('[data-drupal-selector=' + fidSelector + ']').val(fids);

      if (Drupal.gcsCors.uploadStatus[context.targetId].fids.length === context.filelist.length) {
        Drupal.gcsCors.refreshDrupalFileWidget(context);
      }
    },

    refreshDrupalFileWidget: function (context) {
      const fd = new FormData();
      const inputs = context.form.find(':input').not('.js-form-submit');
      inputs.each(function () {
        if (this.name) {
          fd.append(this.name, $(this).val());
        }
      });

      const submits = context.form.find(':input.js-form-submit');
      submits.each(function () {
        if (this.name.substr(0, context.fieldNameKey.length) === context.fieldNameKey) {
          fd.append('_triggering_element_name', this.name);
          fd.append('_triggering_element_value', $(this).val());
        }
      });

      fd.append('_drupal_ajax', 1);
      fd.append('ajax_page_state[theme]', drupalSettings.ajaxPageState.theme);
      fd.append('ajax_page_state[theme_token]', drupalSettings.ajaxPageState.theme_token);
      fd.append('ajax_page_state[libraries]', drupalSettings.ajaxPageState.libraries);

      let posturl = '?element_parents=' + context.settings.element_parents + '&ajax_form=1&_wrapper_format=drupal_ajax&';
      const queryString = window.location.search.substring(1);
      const queryParams = queryString.split('&');
      for (let i = 0; i < queryParams.length; i++) {
        const pair = queryParams[i].split('=');
        if (decodeURIComponent(pair[0]) === 'display') {
          posturl += 'display=' + decodeURIComponent(pair[1]);
        }
      }

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
          Drupal.gcsCors.applyDrupalAjaxResponse(response, status, xmlHttpRequest, posturl, context);
        },
        error: function () {
          Drupal.gcsCors.showUploadError(context.fileInput, Drupal.t('The upload completed, but Drupal could not refresh the file widget.'));
        }
      });
    },

    applyDrupalAjaxResponse: function (response, status, xmlHttpRequest, posturl, context) {
      const responseLength = response.length;
      for (let i = 0; i < responseLength; i++) {
        const selector = response[i].selector;
        if (selector === null) {
          let fieldAjaxWrapper = $('div[data-drupal-selector="edit-' + context.fieldNameKey.replace(/_/g, '-') + '-wrapper"]');
          let childDivId;
          do {
            fieldAjaxWrapper = fieldAjaxWrapper.find('div:first-child');
            childDivId = fieldAjaxWrapper.prop('id');
          }
          while (childDivId === '' || childDivId.indexOf('ajax-wrapper') === -1);
          response[i].selector = '#' + childDivId;
        }
      }

      const ajaxObject = Drupal.ajax({
        url: posturl,
        base: false,
        element: false,
        progress: false
      });
      ajaxObject.success(response, status, xmlHttpRequest);
      Drupal.attachBehaviors();

      context.form.find(':input[type="submit"]').removeAttr('disabled');
      context.form.find('.loader').addClass('js-hide');
    }

  });

})(jQuery, Drupal, drupalSettings);
