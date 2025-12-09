jQuery(document).ready(function($) {
    console.log(' Admin repeater script cargado');
    
    var ticket_counter = $('.ticket_product_row').length || 0;
    
    // Clonar la plantilla del primer ticket
    var ticket_template = $('.ticket_product_row').first().clone();
    
    if (ticket_template.length) {
        ticket_template.find('input[type="text"], input[type="number"]').val('');
    }
    
    // Botón AGREGAR TIPO DE ENTRADA
    $('.add_ticket_button').on('click', function(e) {
        e.preventDefault();
        console.log(' Agregando nuevo tipo de entrada');
        
        if (ticket_template.length === 0) {
            console.error(' No se encontró la plantilla de ticket');
            alert('Error: No se pudo clonar el ticket');
            return;
        }
        
        // Clonar plantilla
        var new_ticket = ticket_template.clone();
        
        // Limpiar valores
        new_ticket.find('input').val('');
        
        // Actualizar IDs y nombres con el nuevo índice
        new_ticket.find('input').each(function() {
            var $input = $(this);
            var old_id = $input.attr('id');
            var old_name = $input.attr('name');
            
            if (old_id) {
                var new_id = old_id.replace(/_\d+$/, '_' + ticket_counter);
                $input.attr('id', new_id);
            }
            
            if (old_name) {
                // Mantener la estructura de array []
                // No modificar el nombre, solo el ID
            }
        });
        
        // Agregar ANTES del botón
        $(this).before(new_ticket);
        
        ticket_counter++;
        update_ticket_buttons();
        
        console.log(' Ticket agregado. Total:', $('.ticket_product_row').length);
    });
    
    // Botón ELIMINAR TIPO DE ENTRADA
    $(document).on('click', '.ticket_product_row .remove_schedule_button', function(e) {
        e.preventDefault();
        console.log(' Intentando eliminar ticket');
        
        var total_tickets = $('.ticket_product_row').length;
        
        if (total_tickets > 1) {
            $(this).closest('.ticket_product_row').remove();
            update_ticket_buttons();
            console.log(' Ticket eliminado. Restantes:', $('.ticket_product_row').length);
        } else {
            alert(' Debe haber al menos un tipo de entrada.');
            console.log(' No se puede eliminar el último ticket');
        }
    });
    
    // Función para mostrar/ocultar botones de eliminar
    function update_ticket_buttons() {
        var $tickets = $('.ticket_product_row');
        var total = $tickets.length;
        
        $tickets.each(function(index) {
            var $button = $(this).find('.remove_schedule_button');
            
            if (total === 1) {
                $button.hide();
            } else {
                $button.show();
            }
        });
        
        console.log(' Botones actualizados. Total tickets:', total);
    }
    
    // Inicializar estado de botones al cargar
    update_ticket_buttons();
});