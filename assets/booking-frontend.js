jQuery(document).ready(function($) {
    
    // Mostrar formulario de rechazo
    $('.btn-show-reject-form').on('click', function() {
        var $card = $(this).closest('.booking-status-card');
        var $rejectForm = $card.find('.reject-form');
        var $rejectBtn = $card.find('.btn-reject-dates');
        var $cancelBtn = $card.find('.btn-cancel-reject');
        
        // Mostrar formulario y botones
        $rejectForm.show();
        $rejectBtn.show();
        $cancelBtn.show();
        $(this).hide();
    });
    
    // Cancelar rechazo
    $('.btn-cancel-reject').on('click', function() {
        var $card = $(this).closest('.booking-status-card');
        var $rejectForm = $card.find('.reject-form');
        var $showBtn = $card.find('.btn-show-reject-form');
        var $rejectBtn = $card.find('.btn-reject-dates');
        
        // Ocultar formulario y botones
        $rejectForm.hide();
        $(this).hide();
        $rejectBtn.hide();
        $showBtn.show();
        
        // Limpiar campos
        $rejectForm.find('input, textarea').val('');
    });
    
    // Confirmar fecha
    $('.btn-accept-date').on('click', function() {
        var $btn = $(this);
        var bookingId = $btn.data('booking');
        var dateId = $btn.data('date');
        
        if ($btn.prop('disabled')) return;
        
        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();
        
        $.ajax({
            url: booking_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'accept_booking_date',
                booking_id: bookingId,
                date_id: dateId,
                nonce: booking_frontend_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    resetButton($btn);
                }
            },
            error: function() {
                alert('Error de conexión');
                resetButton($btn);
            }
        });
    });
    
    // Rechazar fechas
    $('.btn-reject-dates').on('click', function() {
        var $btn = $(this);
        var $card = $btn.closest('.booking-status-card');
        var bookingId = $btn.data('booking');
        var startDate = $card.find('.suggested-start-date').val();
        var endDate = $card.find('.suggested-end-date').val();
        var reason = $card.find('.rejection-reason').val();
        
        if ($btn.prop('disabled')) return;
        
        // Validación básica
        if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
            alert('La fecha de inicio debe ser anterior a la fecha de fin');
            return;
        }
        
        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();
        
        $.ajax({
            url: booking_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'reject_booking_dates',
                booking_id: bookingId,
                suggested_start_date: startDate,
                suggested_end_date: endDate,
                rejection_reason: reason,
                nonce: booking_frontend_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    resetButton($btn);
                }
            },
            error: function() {
                alert('Error de conexión');
                resetButton($btn);
            }
        });
    });
    
    function resetButton($btn) {
        $btn.prop('disabled', false);
        $btn.find('.btn-text').show();
        $btn.find('.btn-loading').hide();
    }
});