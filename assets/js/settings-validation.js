jQuery(document).ready(function($) {
    var maxAllowed = 3; // Set your maximum limit here
    var checkboxes = $('.my-limit-checkbox'); // Use a specific class for your checkboxes

    checkboxes.change(function() {
        var current = checkboxes.filter(':checked').length;

        if (current > maxAllowed) {
            $(this).prop('checked', false);
            alert('You can select a maximum of ' + maxAllowed + ' options.');
        }
    });
});
