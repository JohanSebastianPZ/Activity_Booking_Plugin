jQuery(document).ready(function($) {
    $('#confirm-booking').prop('disabled', true);

    // Abrir modal con animación
    $('#open-booking-modal').on('click', function() {
        $('#booking-modal').show().addClass('show');
        $('body').addClass('modal-open');
    });
    
    // Cerrar modal
    $('.close-modal, .booking-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // Cerrar con ESC
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#booking-modal').hasClass('show')) {
            closeModal();
        }
    });
    
    function closeModal() {
        $('#booking-modal').removeClass('show');
        setTimeout(function() {
            $('#booking-modal').hide();
            $('body').removeClass('modal-open');
        }, 300);
    }
    
    // Lógica de cantidad mejorada
    $('.quantity-btn').on('click', function() {
        var $btn = $(this);
        var ticketId = $btn.data('ticket');
        var $quantityInput = $('[name="ticket_quantity[' + ticketId + ']"]');
        var $display = $btn.closest('.quantity-selector').find('.quantity-display');
        var currentQty = parseInt($quantityInput.val()) || 0;
        
        if ($btn.hasClass('plus')) {
            currentQty++;
        } else if ($btn.hasClass('minus') && currentQty > 0) {
            currentQty--;
        }
        
        $quantityInput.val(currentQty);
        $display.text(currentQty);
        
        // Añadir efecto visual
        $display.addClass('quantity-updated');
        setTimeout(function() {
            $display.removeClass('quantity-updated');
        }, 200);
        
        updatePrices();
        updateConfirmButton();
    });
    
    function updatePrices() {
        var subtotal = 0;
        var totalTickets = 0;
        
        $('.ticket-quantity').each(function() {
            var quantity = parseInt($(this).val()) || 0;
            if (quantity > 0) {
                var ticketId = $(this).attr('name').match(/\[(.*?)\]/)[1];
                var price = parseFloat($('.ticket-row').find('[data-ticket="' + ticketId + '"]').closest('.ticket-row').find('.price').data('price'));
                subtotal += (price * quantity);
                totalTickets += quantity;
            }
        });
        
        var managementFee = 0.50; // 0.50€ por entrada
        var total = subtotal + managementFee;
        
        $('#subtotal').text(subtotal.toFixed(2) + '€');
        $('#management-fee').text(managementFee.toFixed(2) + '€');
        $('#total-price strong').text(total.toFixed(2) + '€');
        $('.btn-price').text(total.toFixed(2) + '€');
        
        // Mostrar/ocultar resumen
        if (total > 0) {
            $('.booking-summary').show();
        } else {
            $('.booking-summary').hide();
        }
    }
    
    function updateConfirmButton() {
        var hasTickets = false;
        $('.ticket-quantity').each(function() {
            if (parseInt($(this).val()) > 0) {
                hasTickets = true;
                return false;
            }
        });
        
        $('#confirm-booking').prop('disabled', !hasTickets);
    }
    
    // Inicializar precios
    updatePrices();
    
    // Confirmar reserva (resto del código igual)
    $(document).on('click', '#confirm-booking', function() {
        var schedule = $('input[name="booking_schedule"]:checked').val();
        var tickets = {};
        var hasTickets = false;
        var productId = $('#current-product-id').val();
        
        $('.ticket-quantity').each(function() {
            var ticketId = $(this).attr('name').match(/\[(.*?)\]/)[1];
            var quantity = parseInt($(this).val()) || 0;
            tickets[ticketId] = quantity;
            if (quantity > 0) {
                hasTickets = true;
            }
        });
        
        if (!productId) {
            alert('Error: ID de producto no encontrado');
            return;
        }
        
        if (!schedule) {
            alert('Por favor selecciona una fecha preferida');
            return;
        }
        
        if (!hasTickets) {
            alert('Por favor selecciona al menos una entrada');
            return;
        }
        
        // Enviar AJAX
        $.ajax({
            url: booking_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'add_booking_to_cart',
                nonce: booking_ajax.nonce,
                product_id: productId,
                schedule_id: schedule,
                tickets: tickets
            },
            success: function(response) {
                if (response.success) {
                    $('#booking-modal').fadeOut();
                    window.location.href = booking_ajax.cart_url;
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', xhr.responseText);
                alert('Error de conexión: ' + error);
            }
        });
    });
});