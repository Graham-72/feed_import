
/**
 * @file
 * Javascript helper for Feed Import module
 */

(function ($) {
  Drupal.behaviors.feed_import = {
    attach: function (context, settings) {
      var fsets = $('fieldset[id^="item_container_"]', context);
      var addevent = false;
      if (context == document) {
        $('[name="add_new_item"]').bind('click', function () {
          if ($('#add-new-item-mode').attr('checked')) {
            $('#add-new-item-field option:selected').remove();
          }
          else {
            var val = $('#add-new-item-manual').val();
            $('#add-new-item-field option[value="' + val + '"]').remove();
          }
          $('#add-new-item-manual').val('');
        });
        addevent = true;
      }
      else if (fsets.length == 1) {
        addevent = true;
      }
      if (addevent) {
        // Get selects.
        $('select[id^="default_action_"]', fsets).each(function () {
          Drupal.behaviors.feed_import.checkSelectVisibility(this);
          $(this).bind('change', function() {
            Drupal.behaviors.feed_import.checkSelectVisibility(this);
          });
        });
      }
    },
    checkSelectVisibility: function (elem) {
      var val = $(elem).val();
      var target = $('div[rel="' + $(elem).attr('id') + '"]');
      if (val == '0' || val == '1') {
        target.show();
      }
      else {
        target.hide();
      }
    }
  }
}
)(jQuery);
