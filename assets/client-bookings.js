jQuery(document).ready(function($) {
    
    // Aceptar fecha
    $('.btn-accept-date').on('click', function() {
        var $btn = $(this);
        var bookingId = $btn.data('booking');
        var dateId = $btn.data('date');
        
        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();
        
        $.ajax({
            url: client_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'accept_booking_date',
                nonce: client_ajax.nonce,
                booking_id: bookingId,
                date_id: dateId
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').show();
                    $btn.find('.btn-loading').hide();
                }
            },
            error: function() {
                alert('Error de conexión');
                $btn.prop('disabled', false);
                $btn.find('.btn-text').show();
                $btn.find('.btn-loading').hide();
            }
        });
    });
    
    // Mostrar formulario de rechazo
    $('.btn-show-reject-form').on('click', function() {
        var $card = $(this).closest('.booking-card');
        var $form = $card.find('.reject-form');
        var $actions = $card.find('.reject-actions');
        
        $(this).hide();
        $form.show();
        $actions.find('.btn-reject-dates, .btn-cancel-reject').show();
    });
    
    // Cancelar rechazo
    $('.btn-cancel-reject').on('click', function() {
        var $card = $(this).closest('.booking-card');
        var $form = $card.find('.reject-form');
        var $showBtn = $card.find('.btn-show-reject-form');
        
        $form.hide();
        $showBtn.show();
        $(this).hide();
        $(this).siblings('.btn-reject-dates').hide();
    });
    
    // Rechazar fechas
    $('.btn-reject-dates').on('click', function() {
        var $btn = $(this);
        var $card = $btn.closest('.booking-card');
        var bookingId = $btn.data('booking');
        var startDate = $card.find('.suggested-start-date').val();
        var endDate = $card.find('.suggested-end-date').val();
        var reason = $card.find('.rejection-reason').val();
        
        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();
        
        $.ajax({
            url: client_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'reject_booking_dates',
                nonce: client_ajax.nonce,
                booking_id: bookingId,
                suggested_start_date: startDate,
                suggested_end_date: endDate,
                rejection_reason: reason
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').show();
                    $btn.find('.btn-loading').hide();
                }
            },
            error: function() {
                alert('Error de conexión');
                $btn.prop('disabled', false);
                $btn.find('.btn-text').show();
                $btn.find('.btn-loading').hide();
            }
        });
    });
    
});