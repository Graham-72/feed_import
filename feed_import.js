(function ($){
  $(document).ready(
    function() {
      // jQuery can't change it! why?
      document.getElementById('edit-add-new-item').type = 'button';
      
      $('#edit-add-new-item').bind('click', function(e) {
          setTimeout(function() {
            $('#edit-add-new-item-field option:selected').remove();
            if ($('#edit-add-new-item-field option').length == 0) {
              $('#edit-add-new-item').unbind('click');
            }
            
            tryBindSelectElemToChange($('select[name^="default_action_"]:last'), 0);
            
          }, 1000);
        }
      );
      
      $('select[name^="default_action_"]').each(
        function(index) {
          bindSelectElemToChange(this);
        }
      )
    }
  );
  
  function tryBindSelectElemToChange(elem, i) {
    if (i == 10) return;
    if (!elem || elem.onchange) {
      i++;
      setTimeout('tryBindSelectElemToChange', 500, elem, i);
    }
    else {
      bindSelectElemToChange(elem);
    }
  }
  
  function bindSelectElemToChange(elem) {
    checkElementForVisibility(elem);
    $(elem).bind('change', function (){checkElementForVisibility(this);});
  }
  
  function checkElementForVisibility(elem) {
    var val = $(elem).val();
    if (val == 'default_value' || val == 'default_value_filtered') {
      $('div[rel="' + $(elem).attr('name') + '"]').show();
    }
    else {
      $('div[rel="' + $(elem).attr('name') + '"]').hide();
    }
  }
}
)(jQuery);