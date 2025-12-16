jQuery(document).ready(function($) {
    console.log(' Admin repeater script cargado (Unificado)');


    var TICKET_TYPE_ROW_SELECTOR = '.ticket_product_row';     
    var ADD_TICKET_TYPE_BUTTON = '.add_ticket_type_button';   
    
    var DISCOUNT_RULE_ROW_SELECTOR = '.discount_rule_row';  
    var ADD_DISCOUNT_RULE_BUTTON = '.add_discount_rule_button';
    
    var REMOVE_BUTTON_SELECTOR = '.remove_schedule_button';  
    


    function initializeRepeater(rowSelector, addButtonSelector, isArrayIndexed) {
        
        // 1. Inicializar el contador con las filas existentes
        var counter = $(rowSelector).length;
        
        // 2. Clonar la plantilla del primer elemento
        var template = $(rowSelector).first().clone();
        
        if (template.length) {
            template.find('input, select').val(''); 
            template.find(REMOVE_BUTTON_SELECTOR).show(); 
       
            if (isArrayIndexed) {
                template.attr('data-index', 9999);
            }
        } else {
            
            return;
        }

        // --- Lógica de Añadir Fila ---
        $(addButtonSelector).on('click', function(e) {
            e.preventDefault();
            
            var new_row = template.clone();
            
            // Limpiar valores
            new_row.find('input, select').val(''); 

            // Actualizar IDs y Nombres
            new_row.find('input, select').each(function() {
                var $input = $(this);
                var old_id = $input.attr('id');
                var old_name = $input.attr('name');
                
                // Actualizar ID (usamos la lógica simple que ya tenías para evitar romper el horario)
                if (old_id) {
                    var new_id = old_id.replace(/_\d+$/, '_' + counter);
                    $input.attr('id', new_id);
                }
                
                // CRÍTICO: Si es un array indexado (Reglas de Descuento), actualizamos el nombre
                if (old_name && isArrayIndexed) {
                   
                    var new_name = old_name.replace(/\[\d+\]/g, '[' + counter + ']');
                    $input.attr('name', new_name);
                }
                
               
            });
            
            // Agregar antes del botón
            $(this).before(new_row);
            
            counter++;
            updateRemoveButtons(rowSelector);
        });

        // --- Lógica de Eliminar Fila ---
        $(document).on('click', rowSelector + ' ' + REMOVE_BUTTON_SELECTOR, function(e) {
            e.preventDefault();
            
            if ($(rowSelector).length > 1) {
                $(this).closest(rowSelector).remove();
                
                setTimeout(function() { updateRemoveButtons(rowSelector); }, 10);
            } else {
                alert('Debe haber al menos una fila.');
            }
        });
        
        // Función para mostrar/ocultar botones de eliminar
        function updateRemoveButtons(selector) {
            var $rows = $(selector);
            var total = $rows.length;
            
            $rows.each(function(index) {
                var $button = $(this).find(REMOVE_BUTTON_SELECTOR);
                
                if (total === 1) {
                    $button.hide();
                } else {
                    $button.show();
                }
            });
        }
        
        // Inicializar estado de botones al cargar
        updateRemoveButtons(rowSelector);
    }
    
    // 1. Inicializar Tipos de Entrada (USA la lógica original)
    initializeRepeater(TICKET_TYPE_ROW_SELECTOR, ADD_TICKET_TYPE_BUTTON, false); 

    // 2. Inicializar Reglas de Descuento (USA la lógica de arrays indexados)
    initializeRepeater(DISCOUNT_RULE_ROW_SELECTOR, ADD_DISCOUNT_RULE_BUTTON, true); 
});

