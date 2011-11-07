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
          }, 1000);
        }
      );
    }
  );
})(jQuery);