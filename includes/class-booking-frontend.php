<?php
if (!defined('ABSPATH')) {
    exit;
}

class BookingFrontend {
    
    public function __construct() {
        add_action('init', array($this, 'init_hooks'));
    }
    
    public function init_hooks() {
        // Hook para crear reservas cuando se complete el pedido
        add_action('woocommerce_order_status_completed', array($this, 'create_booking_on_order_complete'));
        add_action('woocommerce_order_status_processing', array($this, 'create_booking_on_order_complete'));
        
        // Hooks para el frontend de reservas
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Hook para mostrar información de reserva en admin
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_booking_info_in_admin'));
    }


    private function get_confirmed_booking_date($booking_id) {
        global $wpdb;
        
        // Buscar fecha aceptada
        $date = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}activity_booking_dates WHERE booking_id = %d AND status = 'accepted' ORDER BY proposed_date ASC LIMIT 1",
            $booking_id
        ));
        
        // Si no encuentra fecha aceptada, buscar cualquier fecha propuesta para debug
        if (!$date) {
            $date = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}activity_booking_dates WHERE booking_id = %d ORDER BY proposed_date ASC LIMIT 1",
                $booking_id
            ));
        }
        
        return $date;
    }

    public function display_booking_info_in_admin($order) {
        $order_id = $order->get_id();
        
        // Verificar si este pedido tiene reservas
        if (!get_post_meta($order_id, '_bookings_created', true)) {
            return;
        }
        
        $db_manager = new BookingDatabase();
        $bookings = $db_manager->get_booking_by_order($order_id);
        
        if (empty($bookings)) {
            return;
        }
        
        echo '<div class="order-booking-info">';
        echo '<h3 style="margin-bottom:16px;">Información de Reservas</h3>';
        
        foreach ($bookings as $booking) {

            if (empty($booking->status) || !is_string($booking->status) || $booking->status === '0.000000') {
                continue;
            }

            echo '<div class="booking-item" style="border-radius:4px; margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';
            echo '<h4 style="margin:0;">' . get_the_title($booking->product_id) . '</h4>';
            
                echo '<p style="margin: 5px 0;"><strong>Último estado:</strong> ' . $this->get_booking_status_label($booking->status) . '</p>';

            
            // Mostrar fecha confirmada si existe
            if ($booking->status === 'confirmed') {
                $confirmed_date = $this->get_confirmed_booking_date($booking->id);
                if ($confirmed_date) {
                    echo '<p style="margin: 5px 0;"><strong>Fecha Programada:</strong> <span style="font-weight: bold;">' 
                        . date('d/m/Y H:i', strtotime($confirmed_date->proposed_date)) . '</span></p>';
                }
            }
            
            if ($booking->preferred_schedule) {
                echo '<p style="margin: 5px 0;"><strong>Horario Preferido:</strong> ' . $booking->preferred_schedule . '</p>';
            }
            
            if ($booking->collaborator_id) {
                $collaborator = get_user_by('ID', $booking->collaborator_id);
                if ($collaborator) {
                    echo '<p style="margin: 5px 0;"><strong>Colaborador:</strong> ' . $collaborator->display_name . '</p>';
                }
            }
            echo '</div>';
        }
        
        echo '</div>';
    }
        
    private function get_booking_status_label($status) {
        $statuses = array(
            'pending_dates' => '<span style="color: #f39c12;">Pendiente de fechas</span>',
            'dates_proposed' => '<span style="color: #3498db;">Fechas propuestas</span>',
            'dates_rejected' => '<span style="color: #e74c3c;">Fechas rechazadas</span>',
            'confirmed' => '<span style="color: #27ae60;">Confirmada</span>',
            'cancelled' => '<span style="color: #95a5a6;">Cancelada</span>',
            'completed' => '<span style="color: #2ecc71;">Completada</span>'
        );
        
        return isset($statuses[$status]) ? $statuses[$status] : '<span style="color: #7f8c8d;">' . ucfirst($status) . '</span>';
    }

    public function get_confirmed_date_for_booking($booking_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->dates_table_name} WHERE booking_id = %d AND status = 'accepted' ORDER BY proposed_date ASC LIMIT 1",
            $booking_id
        ));
    }

    public function enqueue_frontend_scripts() {
        // Solo cargar en páginas específicas si es necesario
        if (is_page() || is_single()) {
            wp_enqueue_style('booking-frontend-css', plugin_dir_url(dirname(__FILE__)) . 'assets/booking-frontend.css', array(), '1.0');
            wp_enqueue_script('booking-frontend-js', plugin_dir_url(dirname(__FILE__)) . 'assets/booking-frontend.js', array('jquery'), '1.0', true);
            
            wp_localize_script('booking-frontend-js', 'booking_frontend_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('booking_nonce')
            ));
        }
    }
        
    public function create_booking_on_order_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Verificar si ya se crearon reservas para este pedido
        if (get_post_meta($order_id, '_bookings_created', true)) {
            return;
        }
        
        $db_manager = new BookingDatabase();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if ($product && $product->get_meta('_is_activity') === 'yes') {
                $booking_data = array(
                    'order_id' => $order_id,
                    'product_id' => $product_id,
                    'customer_id' => $order->get_customer_id(),
                    'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'collaborator_id' => $product->get_meta('_activity_collaborator'),
                    'preferred_schedule' => $item->get_meta('Horario'),
                    'booking_details' => $this->get_booking_details_from_item($item),
                    'total_price' => $item->get_total(),
                    'status' => 'pending_dates'
                );
                
                $booking_created = $db_manager->create_booking($booking_data);
                
                if ($booking_created) {
                    // Enviar notificación al colaborador
                    $this->notify_collaborator_new_booking($booking_data);
                    
                    // Enviar email de confirmación al cliente
                    $this->send_booking_confirmation_email($booking_data);
                }
            }
        }
        
        // Marcar que las reservas ya fueron creadas
        update_post_meta($order_id, '_bookings_created', true);
    }
        
    private function get_booking_details_from_item($item) {
        $details = array();
        $meta_data = $item->get_meta_data();
        
        foreach ($meta_data as $meta) {
            $key = $meta->get_data()['key'];
            $value = $meta->get_data()['value'];
            
            if ($key !== 'Horario') {
                $details[$key] = $value;
            }
        }
        
        return json_encode($details);
    }

    private function notify_collaborator_new_booking($booking_data) {
        $collaborator = get_user_by('ID', $booking_data['collaborator_id']);
        if (!$collaborator || !is_email($collaborator->user_email)) {
            return;
        }

        $experience_title = get_the_title($booking_data['product_id']);
        $customer_name = esc_html($booking_data['customer_name']);
        $preferred_schedule = esc_html($booking_data['preferred_schedule']);
        $total_price = number_format(floatval($booking_data['total_price']), 2) . '€';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8" />
            <title>Nueva Reserva</title>
            <style>
                * {
                        color: black;
                        font-family: "Nunito";
                    }

                    table {
                        width: 100%;
                        max-width: 600px;
                        margin: 0 auto;
                        border-collapse: collapse;
                    }

                    th,
                    td {
                        padding: 10px;
                        text-align: center;
                    }

                    h1 {
                        font-size: 22px;
                        margin-bottom: 20px;
                        font-weight: 700;
                    }

                    p {
                        font-size: 16px;
                        margin: 10px 0;
                    }

                    a {
                        color: #f07e13;
                        text-decoration: underline;
                    }

                    @media screen and (max-width: 600px) {
                        table {
                        width: 100%;
                        }
                    }

                    #template_header_image {
                        width: 100%;
                        display: inline-block;
                        text-align: center;
                        margin: 20px 0 25px 0;
                    }

                    #template_header_image img {
                        height: 50px;
                    }

                    #template_header_nav {
                        background-color: #71b849;
                        color: white;
                        border-top-left-radius: 25px;
                        border-bottom-right-radius: 25px;
                        margin-bottom: 15px;
                    }

                    #template_header_nav td {
                        height: 42px;
                        vertical-align: middle;
                    }

                    #template_header_nav a {
                        color: white;
                        text-decoration: none;
                        padding: 20px 5px;
                        font-family: "Nunito";
                        font-size: 18px;
                        font-weight: 600;
                    }

                    h1 {
                        font-size: 22px;
                        margin-bottom: 35px;
                        font-weight: 700;
                    }

                    p {
                        font-size: 16px;
                        margin: 0 0 5px 0;
                    }

                    .header {
                        margin-bottom: 10px;
                    }

                    .header p,
                    .header span {
                        text-align: left;
                    }

                    .header p {
                        margin: 0 0 10px 0;
                    }

                    .header span {
                        font-weight: 700;
                        margin: 0 0 8px 0;
                        display: block;
                    }

                    #template_table {
                        font-weight: 700;
                        font-size: 18px;
                        margin: 5px auto 25px;
                    }

                    #template_grettings {
                        margin-top: 10px;
                    }

                    #template_grettings .quote p {
                        font-style: italic;
                    }

                    #template_grettings .quote span {
                        font-weight: 700;
                        margin-top: 15px;
                        display: block;
                    }

                    #template_grettings .grettings {
                        margin: 15px 0 10px 0;
                        display: block;
                        text-align: left;
                    }

                    #template_grettings .grettings p {
                        text-align: left;
                    }

                    #template_grettings .grettings span {
                        font-weight: 700;
                    }

                    .footer {
                        margin-top: 10px;
                    }

                    .footer .logo_footer img {
                        height: 40px;
                        margin: 10px 0 20px;
                    }

                    .footer td {
                        padding: 0;
                    }

                    .footer .logo_footer {
                        border-bottom: 5px solid #71b849;
                    }

                    .footer .aviso-privacidad,
                    .footer .aviso-privacidad p {
                        background-color: white;
                        color: black;
                        text-align: left;
                        font-size: 10px;
                    }

                    .footer .aviso-privacidad {
                        margin-top: 25px;
                    }

                    .body.table{
                        width: fit-content;
                        border: 1px solid #ccc;
                        margin: 10px auto;
                        max-width: 100%;
                    }

                    .body.table tr td:first-child{
                        font-weight: 700;
                        border-right: 1px solid #ccc;
                    }

                    .body.body.table tr td{
                        text-align: left;
                        padding-right: 30px;
                    }      

                    .body.body.table tr{
                        border: 1px solid #ccc;
                    }

                    .body.table tr td:last-child{
                        font-weight: 500;
                        border-right: 1px solid #ccc;
                    }      

                    @media screen and (max-width: 600px) {
                        table {
                        width: 100%;
                        }

                        #template_header_nav td{
                        display: block;
                        height: auto;
                        }
                    }
            </style>
        </head>
        <body>
            <div id="template_header_image">
                <img src="https://ms.regalexia.com/wp-content/uploads/2023/04/logo.png" alt="Regalexia" />
            </div>

            <table id="template_header_nav">
                <tr>
                    <td><a href="https://regalexia.com/experiencias/">+ Experiencias</a></td>
                    <td><a href="https://regalexia.com/amigos-regalexia/">+ Amigos Regalexia</a></td>
                    <td><a href="https://regalexia.com/consejos-para-padres/">+ Blog</a></td>
                </tr>
            </table>

            <table class="header">
                <tr>
                    <td>
                        <h1>Nueva reserva - <?= esc_html($experience_title); ?></h1>
                        <p>Hola <?= esc_html($collaborator->display_name); ?>,</p>
                        <p>Tienes una nueva reserva para la actividad <strong><?= esc_html($experience_title); ?></strong>.</p>
                    </td>
                </tr>
            </table>

            <table class="body table">
                <tr>
                    <td>Cliente</td>
                    <td><?= $customer_name; ?></td>
                </tr>
                <tr>
                    <td>Horario preferido</td>
                    <td><?= $preferred_schedule; ?></td>
                </tr>
                <tr>
                    <td>Total</td>
                    <td><?= $total_price; ?></td>
                </tr>
            </table>

            <table class="body">
                <tr>
                    <td>
                        Por favor, accede a tu <a href="https://regalexia.com/administrar-reservas/">panel de colaborador</a> para proponer fechas disponibles al cliente.
                    </td>
                </tr>
            </table>

            <table class="footer">
                <tr>
                    <td class="logo_footer">
                        <img src="https://ms.regalexia.com/wp-content/uploads/2023/04/cropped-favico.png" />
                    </td>
                </tr>
                <tr>
                    <td>
                        <p class="aviso-privacidad">
                        <b>PROTECCIÓN DE DATOS:</b>
                            De conformidad con lo dispuesto en el Reglamento (UE) 2016/679, de
                            27 de abril (GDPR), y la Ley Orgánica 3/2018, de 5 de diciembre
                            (LOPDGDD), le informamos de que los datos personales y la dirección
                            de correo electrónico del interesado, se tratarán bajo la
                            responsabilidad de KIDSON, S.L. por un interés legítimo y para el
                            envío de comunicaciones sobre nuestros productos y servicios, y se
                            conservarán mientras ninguna de las partes se oponga a ello. Los
                            datos no se comunicarán a terceros, salvo obligación legal. Le
                            informamos de que puede ejercer los derechos de acceso,
                            rectificación, portabilidad y supresión de sus datos y los de
                            limitación y oposición a su tratamiento dirigiéndose a Aribau 168 1º1ª, 08036 Barcelona (Barcelona). E-mail:
                            <a href="mailto:hola@regalexia.com">hola@regalexia.com</a>. Si
                            considera que el tratamiento no se ajusta a la normativa vigente,
                            podrá presentar una reclamación ante la autoridad de control en
                            <a href="https://www.aepd.es/es">www.aepd.es</a>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td>
                    <p class="aviso-privacidad">
                        <b>PUBLICIDAD:</b>
                        En cumplimiento de lo previsto en el artículo 21 de la Ley 34/2002
                        de Servicios de la Sociedad de la Información y Comercio Electrónico
                        (LSSICE), si usted no desea recibir más información sobre nuestros
                        productos y/o servicios, puede darse de baja enviando un correo
                        electrónico a
                        <a href="mailto:hola@regalexia.com">hola@regalexia.com</a>,
                        indicando en el Asunto «BAJA» o «NO ENVIAR».
                    </p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php

        $message = ob_get_clean();
        $subject = 'Nueva reserva de actividad - ' . $experience_title;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($collaborator->user_email, $subject, $message, $headers);
    }
      
    private function send_booking_confirmation_email($booking_data) {
        $order = wc_get_order($booking_data['order_id']);
        $customer_email = $order ? $order->get_billing_email() : '';

        if (empty($customer_email) || !is_email($customer_email)) {
            return;
        }

        $experience_title = get_the_title($booking_data['product_id']);
        $customer_name = esc_html($booking_data['customer_name']);
        $preferred_schedule = esc_html($booking_data['preferred_schedule']);
        $total_price = number_format(floatval($booking_data['total_price']), 2) . '€';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8" />
            <title>Confirmación de reserva</title>
            <style>
                * {
                        color: black;
                        font-family: "Nunito";
                    }

                    table {
                        width: 100%;
                        max-width: 600px;
                        margin: 0 auto;
                        border-collapse: collapse;
                    }

                    th,
                    td {
                        padding: 10px;
                        text-align: center;
                    }

                    h1 {
                        font-size: 22px;
                        margin-bottom: 20px;
                        font-weight: 700;
                    }

                    p {
                        font-size: 16px;
                        margin: 10px 0;
                    }

                    a {
                        color: #f07e13;
                        text-decoration: underline;
                    }

                    @media screen and (max-width: 600px) {
                        table {
                        width: 100%;
                        }
                    }

                    #template_header_image {
                        width: 100%;
                        display: inline-block;
                        text-align: center;
                        margin: 20px 0 25px 0;
                    }

                    #template_header_image img {
                        height: 50px;
                    }

                    #template_header_nav {
                        background-color: #71b849;
                        color: white;
                        border-top-left-radius: 25px;
                        border-bottom-right-radius: 25px;
                        margin-bottom: 15px;
                    }

                    #template_header_nav td {
                        height: 42px;
                        vertical-align: middle;
                    }

                    #template_header_nav a {
                        color: white;
                        text-decoration: none;
                        padding: 20px 5px;
                        font-family: "Nunito";
                        font-size: 18px;
                        font-weight: 600;
                    }

                    h1 {
                        font-size: 22px;
                        margin-bottom: 35px;
                        font-weight: 700;
                    }

                    p {
                        font-size: 16px;
                        margin: 0 0 5px 0;
                    }

                    .header {
                        margin-bottom: 10px;
                    }

                    .header p,
                    .header span {
                        text-align: left;
                    }

                    .header p {
                        margin: 0 0 10px 0;
                    }

                    .header span {
                        font-weight: 700;
                        margin: 0 0 8px 0;
                        display: block;
                    }

                    #template_table {
                        font-weight: 700;
                        font-size: 18px;
                        margin: 5px auto 25px;
                    }

                    #template_grettings {
                        margin-top: 10px;
                    }

                    #template_grettings .quote p {
                        font-style: italic;
                    }

                    #template_grettings .quote span {
                        font-weight: 700;
                        margin-top: 15px;
                        display: block;
                    }

                    #template_grettings .grettings {
                        margin: 15px 0 10px 0;
                        display: block;
                        text-align: left;
                    }

                    #template_grettings .grettings p {
                        text-align: left;
                    }

                    #template_grettings .grettings span {
                        font-weight: 700;
                    }

                    .footer {
                        margin-top: 10px;
                    }

                    .footer .logo_footer img {
                        height: 40px;
                        margin: 10px 0 20px;
                    }

                    .footer td {
                        padding: 0;
                    }

                    .footer .logo_footer {
                        border-bottom: 5px solid #71b849;
                    }

                    .footer .aviso-privacidad,
                    .footer .aviso-privacidad p {
                        background-color: white;
                        color: black;
                        text-align: left;
                        font-size: 10px;
                    }

                    .footer .aviso-privacidad {
                        margin-top: 25px;
                    }

                    .body.table{
                        width: fit-content;
                        border: 1px solid #ccc;
                        margin: 10px auto;
                        max-width: 100%;
                    }

                    .body.table tr td:first-child{
                        font-weight: 700;
                        border-right: 1px solid #ccc;
                    }

                    .body.body.table tr td{
                        text-align: left;
                        padding-right: 30px;
                    }      

                    .body.body.table tr{
                        border: 1px solid #ccc;
                    }

                    .body.table tr td:last-child{
                        font-weight: 500;
                        border-right: 1px solid #ccc;
                    }      

                    @media screen and (max-width: 600px) {
                        table {
                        width: 100%;
                        }

                        #template_header_nav td{
                        display: block;
                        height: auto;
                        }
                    }
            </style>
        </head>
        <body>
            <div id="template_header_image">
                <img src="https://ms.regalexia.com/wp-content/uploads/2023/04/logo.png" alt="Regalexia" />
            </div>

            <table id="template_header_nav">
                <tr>
                    <td><a href="https://regalexia.com/experiencias/">+ Experiencias</a></td>
                    <td><a href="https://regalexia.com/amigos-regalexia/">+ Amigos Regalexia</a></td>
                    <td><a href="https://regalexia.com/consejos-para-padres/">+ Blog</a></td>
                </tr>
            </table>

            <table class="header">
                <tr>
                    <td>
                        <h1>Confirmación de tu reserva</h1>
                        <p>Hola <?= $customer_name; ?>,</p>
                        <p>Gracias por tu reserva de la actividad <strong><?= esc_html($experience_title); ?></strong>.</p>
                    </td>
                </tr>
            </table>

            <table class="body table">
                <tr>
                    <td>Actividad</td>
                    <td><?= esc_html($experience_title); ?></td>
                </tr>
                <tr>
                    <td>Horario preferido</td>
                    <td><?= $preferred_schedule; ?></td>
                </tr>
                <tr>
                    <td>Total</td>
                    <td><?= $total_price; ?></td>
                </tr>
            </table>

            <table class="body">
                <tr>
                    <td>
                        Nuestro colaborador te contactará pronto para proponerte fechas disponibles.  
                        Puedes revisar el estado de tu reserva desde tu <a href="https://regalexia.com/administrar-reservas/">área de cliente</a>.
                    </td>
                </tr>
            </table>

            <table class="footer">
                <tr>
                    <td class="logo_footer">
                        <img src="https://ms.regalexia.com/wp-content/uploads/2023/04/cropped-favico.png" />
                    </td>
                </tr>
                <tr>
                    <td>
                        <p class="aviso-privacidad">
                        <b>PROTECCIÓN DE DATOS:</b>
                            De conformidad con lo dispuesto en el Reglamento (UE) 2016/679, de
                            27 de abril (GDPR), y la Ley Orgánica 3/2018, de 5 de diciembre
                            (LOPDGDD), le informamos de que los datos personales y la dirección
                            de correo electrónico del interesado, se tratarán bajo la
                            responsabilidad de KIDSON, S.L. por un interés legítimo y para el
                            envío de comunicaciones sobre nuestros productos y servicios, y se
                            conservarán mientras ninguna de las partes se oponga a ello. Los
                            datos no se comunicarán a terceros, salvo obligación legal. Le
                            informamos de que puede ejercer los derechos de acceso,
                            rectificación, portabilidad y supresión de sus datos y los de
                            limitación y oposición a su tratamiento dirigiéndose a Aribau 168 1º1ª, 08036 Barcelona (Barcelona). E-mail:
                            <a href="mailto:hola@regalexia.com">hola@regalexia.com</a>. Si
                            considera que el tratamiento no se ajusta a la normativa vigente,
                            podrá presentar una reclamación ante la autoridad de control en
                            <a href="https://www.aepd.es/es">www.aepd.es</a>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td>
                    <p class="aviso-privacidad">
                        <b>PUBLICIDAD:</b>
                        En cumplimiento de lo previsto en el artículo 21 de la Ley 34/2002
                        de Servicios de la Sociedad de la Información y Comercio Electrónico
                        (LSSICE), si usted no desea recibir más información sobre nuestros
                        productos y/o servicios, puede darse de baja enviando un correo
                        electrónico a
                        <a href="mailto:hola@regalexia.com">hola@regalexia.com</a>,
                        indicando en el Asunto «BAJA» o «NO ENVIAR».
                    </p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php

        $message = ob_get_clean();
        $subject = 'Confirmación de reserva - ' . $experience_title;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($customer_email, $subject, $message, $headers);
    }

}