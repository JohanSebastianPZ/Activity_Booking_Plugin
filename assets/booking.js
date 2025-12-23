jQuery(document).ready(function($) {
    // Deshabilitar botón de compra al inicio
    $('#confirm-booking').prop('disabled', true);

    // --- EVENTOS DE INTERFAZ ---
    
    $('#open-booking-modal').on('click', function() {
        $('#booking-modal').show().addClass('show');
        $('body').addClass('modal-open');
    });
    
    $('.close-modal, .booking-modal').on('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    function closeModal() {
        $('#booking-modal').removeClass('show');
        setTimeout(function() {
            $('#booking-modal').hide();
            $('body').removeClass('modal-open');
        }, 300);
    }

    // --- LÓGICA DE CANTIDADES ---

    $('.quantity-btn').on('click', function() {
        var $btn = $(this);
        var ticketId = $btn.data('ticket');
        var $row = $btn.closest('.ticket-row');
        var $quantityInput = $row.find('.ticket-quantity');
        var $display = $row.find('.quantity-display');
        
        var currentQty = parseInt($quantityInput.val()) || 0;
        
        if ($btn.hasClass('plus')) {
            currentQty++;
        } else if ($btn.hasClass('minus') && currentQty > 0) {
            currentQty--;
        }
        
        $quantityInput.val(currentQty);
        $display.text(currentQty);
        
        // Efecto visual de actualización
        $display.addClass('quantity-updated');
        setTimeout(function() { $display.removeClass('quantity-updated'); }, 200);
        
        // Recalcular todo
        updatePrices();
        updateConfirmButton();
    });

    // --- FUNCIÓN CÁLCULO DE PRECIOS Y DESCUENTOS ---

    function updatePrices() {
        var subtotalOriginal = 0; 
        var subtotalConDescuento = 0; 
        var totalTickets = 0;
        
        var reglas = window.REGLAS_DESCUENTO || [];
        
        $('.ticket-row').each(function() {
            var $fila = $(this);
            var cantidad = parseInt($fila.find('.ticket-quantity').val()) || 0;
            
            if (cantidad > 0) {
                
                var idTicket = $fila.find('.quantity-btn.plus').attr('data-ticket'); 
                var precioBase = parseFloat($fila.find('.price').data('price'));
                var precioUnitarioFinal = precioBase;

                
                reglas.forEach(function(r) {
                    if (String(r.type).trim().toLowerCase() === String(idTicket).trim().toLowerCase()) {
                        if (cantidad >= parseInt(r.min_qty)) {
                            precioUnitarioFinal = parseFloat(r.discount_price);
                        }
                    }
                });

                subtotalOriginal += (precioBase * cantidad);
                subtotalConDescuento += (precioUnitarioFinal * cantidad);
                totalTickets += cantidad;
            }
        });

        var gestion = totalTickets > 0 ? 0.50 : 0.00;
        var ahorro = subtotalOriginal - subtotalConDescuento;
        var totalFinal = subtotalConDescuento + gestion;

        // ACTUALIZAR INTERFAZ
        // Mostramos el precio ya rebajado en el subtotal para que el usuario no se confunda
        $('#subtotal').text(subtotalConDescuento.toFixed(2) + '€');
        $('#management-fee').text(gestion.toFixed(2) + '€');
        $('#total-price strong').text(totalFinal.toFixed(2) + '€');

        // FILA DE DESCUENTO (Opcional, para mostrar cuánto se ahorró)
        if (ahorro > 0) {
            $('#discount-amount').text('-' + ahorro.toFixed(2) + '€');
            $('.discount-row').show();
        } else {
            $('.discount-row').hide();
        }

        $('.booking-summary').toggle(totalTickets > 0);
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

    // --- ENVÍO AL CARRITO (AJAX) ---

    $(document).on('click', '#confirm-booking', function() {
        var schedule = $('input[name="booking_schedule"]:checked').val();
        var tickets = {};
        var productId = $('#current-product-id').val();
        
        $('.ticket-quantity').each(function() {
            var ticketId = $(this).attr('name').match(/\[(.*?)\]/)[1];
            var quantity = parseInt($(this).val()) || 0;
            if (quantity > 0) tickets[ticketId] = quantity;
        });

        if (!schedule) { alert('Selecciona una fecha'); return; }

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
                if (response.success) window.location.href = booking_ajax.cart_url;
                else alert('Error: ' + response.data.message);
            }
        });
    });
});