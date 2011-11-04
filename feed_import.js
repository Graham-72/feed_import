jQuery(document).ready(
    function() {
        document.getElementById('edit-add-new-item').type = 'button'; //jQuery can't change it! why?
        jQuery('#edit-add-new-item').bind('click', function(e) {
                setTimeout(function() {
                    jQuery('#edit-add-new-item-field option:selected').remove();
                    if(jQuery('#edit-add-new-item-field option').length == 0) {
                        jQuery('#edit-add-new-item').unbind('click');
                    }
                }, 1000);   
            }
        );
        
        
    }
);