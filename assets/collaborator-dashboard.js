jQuery(document).ready(function($) {
    // Filtros de estado
    $('.filter-btn').on('click', function() {
        const status = $(this).data('status');
        
        // Actualizar botón activo
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        
        // Filtrar tarjetas
        if (status === 'all') {
            $('.booking-card').fadeIn(300);
        } else {
            $('.booking-card').each(function() {
                if ($(this).data('status') === status) {
                    $(this).fadeIn(300);
                } else {
                    $(this).fadeOut(300);
                }
            });
        }
    });
    
    // Proponer fechas
    $('.btn-propose-dates').on('click', function() {
        const $btn = $(this);
        const bookingId = $btn.data('booking');
        const $form = $btn.closest('.date-proposal-form');
        const $dates = $form.find('.proposed-date');
        
        // Validar que al menos hay una fecha
        const dates = [];
        $dates.each(function() {
            const date = $(this).val();
            if (date) {
                dates.push(date);
            }
        });
        
        if (dates.length === 0) {
            alert('Por favor, ingresa al menos una fecha.');
            return;
        }
        
        // Validar que las fechas sean futuras
        const now = new Date();
        const invalidDates = dates.filter(date => new Date(date) <= now);
        
        if (invalidDates.length > 0) {
            alert('Las fechas deben ser futuras.');
            return;
        }
        
        // Mostrar estado de carga
        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();
        
        // Enviar datos
        $.ajax({
            url: collaborator_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'propose_booking_dates',
                nonce: collaborator_ajax.nonce,
                booking_id: bookingId,
                dates: dates
            },
            success: function(response) {
                if (response.success) {
                    // Mostrar mensaje de éxito
                    showNotification('Fechas enviadas correctamente', 'success');
                    
                    // Recargar la página después de un breve delay
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(response.data || 'Error al enviar fechas', 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión', 'error');
            },
            complete: function() {
                // Restaurar botón
                $btn.prop('disabled', false);
                $btn.find('.btn-text').show();
                $btn.find('.btn-loading').hide();
            }
        });
    });
    
    // Función para mostrar notificaciones
    function showNotification(message, type) {
        const notification = $(`
            <div class="notification notification-${type}">
                <div class="notification-content">
                    <span class="notification-icon">
                        ${type === 'success' ? '✅' : '❌'}
                    </span>
                    <span class="notification-message">${message}</span>
                </div>
            </div>
        `);
        
        $('body').append(notification);
        
        // Mostrar notificación
        setTimeout(() => {
            notification.addClass('show');
        }, 100);
        
        // Ocultar después de 3 segundos
        setTimeout(() => {
            notification.removeClass('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
    
    // Añadir estilos para notificaciones
    if (!$('#notification-styles').length) {
        $('head').append(`
            <style id="notification-styles">
                .notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    transform: translateX(100%);
                    transition: transform 0.3s ease;
                    max-width: 400px;
                }
                
                .notification.show {
                    transform: translateX(0);
                }
                
                .notification-success {
                    border-left: 4px solid #00b894;
                }
                
                .notification-error {
                    border-left: 4px solid #e17055;
                }
                
                .notification-content {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 16px 20px;
                }
                
                .notification-icon {
                    font-size: 1.2rem;
                }
                
                .notification-message {
                    font-weight: 500;
                    color: #2d3436;
                }
                
                @media (max-width: 480px) {
                    .notification {
                        right: 10px;
                        left: 10px;
                        max-width: none;
                        transform: translateY(-100%);
                    }
                    
                    .notification.show {
                        transform: translateY(0);
                    }
                }
            </style>
        `);
    }
    
    // Mejorar accesibilidad con navegación por teclado
    $('.filter-btn').on('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).click();
        }
    });
    
    // Auto-refresh cada 30 segundos para actualizaciones en tiempo real
    let autoRefreshTimer;
    
    function startAutoRefresh() {
        autoRefreshTimer = setInterval(function() {
            // Solo refrescar si no hay formularios activos
            if (!$('.btn-propose-dates:disabled').length) {
                location.reload();
            }
        }, 30000);
    }
    
    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
        }
    }
    
    // Iniciar auto-refresh
    //startAutoRefresh();
    
    // Pausar auto-refresh cuando el usuario está interactuando
    $(document).on('focus', 'input, button', stopAutoRefresh);
    $(document).on('blur', 'input, button', function() {
        setTimeout(startAutoRefresh, 5000);
    });
    
    // Manejar visibilidad de la página
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            //startAutoRefresh();
        }
    });
});